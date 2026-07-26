<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Migration\Tasks;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Migration task to migrate from old formal German bot to new formal German bot
 * 
 * Background:
 * - Before v1.5.0: 'de' bot was formal German (Sie)
 * - From v1.5.0: 'de' = informal (Du), 'de_DE' = formal (Sie)
 * 
 * Changes:
 * - Updates room assignments from old "nextcloudapp://agenda_bot/de" (was formal) to "nextcloudapp://agenda_bot/de_DE" (new formal)
 * - Updates room configurations that reference the old German bot URL
 * - Preserves users' formal German experience by migrating to the new formal variant
 * - Both bot variants are created by BotService::installBot() during app installation
 */
class GermanFormalityMigrationTask implements IMigrationTask {
    
    private const OLD_FORMAL_GERMAN_BOT_URL = 'nextcloudapp://agenda_bot/de';     // Was formal before v1.5.0
    private const NEW_FORMAL_GERMAN_BOT_URL = 'nextcloudapp://agenda_bot/de_DE';   // Formal from v1.5.0+
    
    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger
    ) {
    }

    public function getName(): string {
        return 'German Formality Migration';
    }

    public function getDescription(): string {
        return 'Migrates room assignments from old formal German bot (de) to new formal German bot (de_DE) to preserve formal German experience';
    }

    public function getRequiredVersion(): string {
        return '1.5.0';
    }

    /**
     * Execute the German formality migration
     */
    public function execute(): bool {
        $this->logger->info('Starting German formality migration');
        
        try {
            $this->db->beginTransaction();
            
            // Verify both bot variants exist (they should be created by BotService::installBot)
            $oldFormalBotId = $this->getBotIdByUrl(self::OLD_FORMAL_GERMAN_BOT_URL);
            $newFormalBotId = $this->getBotIdByUrl(self::NEW_FORMAL_GERMAN_BOT_URL);
            
            if (!$oldFormalBotId) {
                $this->logger->warning('Old formal German bot (de) not found - no migration needed');
                $this->db->rollBack();
                return true; // Not an error, just nothing to migrate
            }
            
            if (!$newFormalBotId) {
                $this->logger->error('New formal German bot (de_DE) not found - bot installation may have failed');
                $this->db->rollBack();
                return false;
            }
            
            $migrationResults = [
                'bot_verification' => ['old_formal_bot_id' => $oldFormalBotId, 'new_formal_bot_id' => $newFormalBotId],
                'room_assignments' => $this->migrateBotAssignments(),
                'room_configurations' => $this->migrateRoomConfigurations(),
            ];
            
            $this->db->commit();
            
            $this->logger->info('German formality migration completed successfully', $migrationResults);
            
            return true;
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('German formality migration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return false;
        }
    }

    
    /**
     * Migrate bot assignments: Update room assignments from old formal to new formal German bot
     */
    private function migrateBotAssignments(): array {
        // Get bot IDs
        $oldFormalBotId = $this->getBotIdByUrl(self::OLD_FORMAL_GERMAN_BOT_URL);
        $newFormalBotId = $this->getBotIdByUrl(self::NEW_FORMAL_GERMAN_BOT_URL);
        
        if (!$oldFormalBotId) {
            return ['updated_assignments' => 0, 'message' => 'No old formal German bot (de) found'];
        }
        
        if (!$newFormalBotId) {
            return ['updated_assignments' => 0, 'error' => 'New formal German bot (de_DE) not found - registration may have failed'];
        }
        
        // Find all room assignments using the old formal German bot
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('token')
            ->from('talk_bots_conversation')
            ->where($qb->expr()->eq('bot_id', $qb->createNamedParameter($oldFormalBotId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(1))) // ENABLED
            ->executeQuery();
            
        $assignmentsToMigrate = $result->fetchAll();
        $result->closeCursor();
        
        if (empty($assignmentsToMigrate)) {
            return ['updated_assignments' => 0, 'message' => 'No room assignments found for old formal German bot (de)'];
        }
        
        $migratedCount = 0;
        
        foreach ($assignmentsToMigrate as $assignment) {
            try {
                // Update the bot_id assignment to the new formal German bot
                $updateQb = $this->db->getQueryBuilder();
                $updateQb->update('talk_bots_conversation')
                    ->set('bot_id', $updateQb->createNamedParameter($newFormalBotId, IQueryBuilder::PARAM_INT))
                    ->where($updateQb->expr()->eq('token', $updateQb->createNamedParameter($assignment['token'])))
                    ->andWhere($updateQb->expr()->eq('bot_id', $updateQb->createNamedParameter($oldFormalBotId, IQueryBuilder::PARAM_INT)));
                    
                $updatedRows = $updateQb->executeStatement();
                
                if ($updatedRows > 0) {
                    $migratedCount++;
                    $this->logger->debug('Migrated room bot assignment from old formal (de) to new formal (de_DE)', [
                        'token' => $assignment['token'],
                        'old_formal_bot_id' => $oldFormalBotId,
                        'new_formal_bot_id' => $newFormalBotId
                    ]);
                }
                
            } catch (\Exception $e) {
                $this->logger->warning('Failed to migrate room bot assignment', [
                    'token' => $assignment['token'],
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->logger->info('Migrated bot assignments from old formal (de) to new formal (de_DE)', [
            'total_found' => count($assignmentsToMigrate),
            'successfully_migrated' => $migratedCount,
            'old_formal_bot_id' => $oldFormalBotId,
            'new_formal_bot_id' => $newFormalBotId
        ]);
        
        return [
            'total_assignments' => count($assignmentsToMigrate),
            'updated_assignments' => $migratedCount,
            'old_formal_bot_id' => $oldFormalBotId,
            'new_formal_bot_id' => $newFormalBotId
        ];
    }
    
    /**
     * Get bot ID by URL
     */
    private function getBotIdByUrl(string $url): ?int {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id')
            ->from('talk_bots_server')
            ->where($qb->expr()->eq('url', $qb->createNamedParameter($url)))
            ->executeQuery()
            ->fetchOne();
            
        return $result ? (int)$result : null;
    }
    

    /**
     * Migrate room configurations that might reference bot URLs
     */
    private function migrateRoomConfigurations(): array {
        $qb = $this->db->getQueryBuilder();
        
        // Look for room configurations that might contain old formal German bot URL references
        $result = $qb->select('*')
            ->from('ab_log_entries')
            ->where($qb->expr()->eq('type', $qb->createNamedParameter('room_config')))
            ->andWhere($qb->expr()->like('details', $qb->createNamedParameter('%' . self::OLD_FORMAL_GERMAN_BOT_URL . '%')))
            ->executeQuery();
        
        $configsToUpdate = $result->fetchAll();
        $result->closeCursor();
        
        if (empty($configsToUpdate)) {
            return ['updated_configs' => 0, 'message' => 'No room configurations found to migrate'];
        }
        
        $updatedCount = 0;
        
        foreach ($configsToUpdate as $config) {
            try {
                $details = $config['details'];
                $updatedDetails = str_replace(self::OLD_FORMAL_GERMAN_BOT_URL, self::NEW_FORMAL_GERMAN_BOT_URL, $details);
                
                if ($updatedDetails !== $details) {
                    $updateQb = $this->db->getQueryBuilder();
                    $updateQb->update('ab_log_entries')
                        ->set('details', $updateQb->createNamedParameter($updatedDetails))
                        ->where($updateQb->expr()->eq('id', $updateQb->createNamedParameter($config['id'], IQueryBuilder::PARAM_INT)));
                    
                    $updateQb->executeStatement();
                    $updatedCount++;
                }
                
            } catch (\Exception $e) {
                $this->logger->warning('Failed to update room configuration', [
                    'config_id' => $config['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->logger->info('Updated room configurations', [
            'total_checked' => count($configsToUpdate),
            'updated_count' => $updatedCount
        ]);
        
        return [
            'total_checked' => count($configsToUpdate),
            'updated_configs' => $updatedCount
        ];
    }
}