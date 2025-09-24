<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Migration;

use OCA\AgendaBot\Migration\Tasks\GermanFormalityMigrationTask;
use OCA\AgendaBot\Migration\Tasks\IMigrationTask;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service for coordinating migration operations and version management
 */
class MigrationService {
    
    private const APP_NAME = 'agenda_bot';
    
    // Migration version milestones in chronological order
    private const MIGRATION_VERSIONS = [
        '1.5.0', // German Formality Migration
    ];
    
    public function __construct(
        private IConfig $config,
        private IDBConnection $db,
        private IJobList $jobList,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Check if migrations should run based on version upgrade detection
     */
    public function shouldRunMigration(): bool {
        $currentVersion = $this->getCurrentVersion();
        $lastEnabledVersion = $this->config->getAppValue(self::APP_NAME, 'last_enabled_version', null);
        
        // Backward compatibility: if last_enabled_version is not set, assume 1.0.0 (pre-migration era)
        if ($lastEnabledVersion === null) {
            $lastEnabledVersion = '1.0.0';
        }
        
        // No migration needed if last enabled version is same or newer than current version
        if (version_compare($lastEnabledVersion, $currentVersion, '>=')) {
            return false;
        }
        
        // Check if there are any migration milestones between lastEnabledVersion and currentVersion
        $hasRequiredMigrations = $this->hasMigrationsInRange($lastEnabledVersion, $currentVersion);
        
        $this->logger->debug('Migration version check', [
            'current_version' => $currentVersion,
            'last_enabled_version' => $lastEnabledVersion,
            'has_required_migrations' => $hasRequiredMigrations
        ]);
        
        return $hasRequiredMigrations;
    }
    
    /**
     * Check if there are migration milestones between fromVersion and toVersion
     */
    private function hasMigrationsInRange(string $fromVersion, string $toVersion): bool {
        foreach (self::MIGRATION_VERSIONS as $migrationVersion) {
            // Migration version is in range if:
            // - fromVersion < migrationVersion (didn't have this feature before)
            // - toVersion >= migrationVersion (has this feature now)
            if (version_compare($fromVersion, $migrationVersion, '<') && 
                version_compare($toVersion, $migrationVersion, '>=')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Legacy method - migration scheduling now handled by AppEnableListener
     */
    public function scheduleIfNeeded(): void {
        // No-op: Migration scheduling moved to AppEnableListener for accurate version comparison
    }
    
    /**
     * Schedule migration background job
     */
    public function scheduleMigrationJob(): void {
        // Check if migration job is already scheduled
        if (!$this->jobList->has(\OCA\AgendaBot\BackgroundJob\AgendaMigrationJob::class, null)) {
            $this->jobList->add(\OCA\AgendaBot\BackgroundJob\AgendaMigrationJob::class);
            $this->logger->info('Migration job scheduled for version upgrade', [
                'current_version' => $this->getCurrentVersion(),
                'last_enabled_version' => $this->config->getAppValue(self::APP_NAME, 'last_enabled_version', null)
            ]);
        } else {
            $this->logger->debug('Migration job already scheduled');
        }
    }

    /**
     * Execute only the migration tasks needed for the current version upgrade
     */
    public function executeMigrations(): array {
        $this->logger->info('Starting migration execution');
        
        if (!$this->shouldRunMigration()) {
            // No migrations needed, but still update last_enabled_version for tracking
            $this->updateLastEnabledVersion();
            return [
                'success' => true,
                'message' => 'No migrations needed',
                'executed_tasks' => []
            ];
        }
        
        $lastEnabledVersion = $this->config->getAppValue(self::APP_NAME, 'last_enabled_version', null);
        
        // Backward compatibility: if last_enabled_version is not set, assume 1.0.0 (pre-migration era)
        if ($lastEnabledVersion === null) {
            $lastEnabledVersion = '1.0.0';
        }
        
        $currentVersion = $this->getCurrentVersion();
        
        // Get only the tasks needed for this specific version range
        $tasks = $this->getRequiredMigrationTasks($lastEnabledVersion, $currentVersion);
        $executed = [];
        $failed = [];
        
        foreach ($tasks as $task) {
            try {
                // Check if this task was already completed successfully
                if ($this->isTaskCompleted($task)) {
                    $this->logger->debug('Migration task already completed, skipping', ['task' => $task->getName()]);
                    continue;
                }
                
                $this->logger->info('Executing migration task', ['task' => $task->getName()]);
                
                $success = $task->execute();
                
                if ($success) {
                    $executed[] = $task->getName();
                    $this->markTaskCompleted($task);
                    $this->logger->info('Migration task completed successfully', ['task' => $task->getName()]);
                } else {
                    $failed[] = $task->getName();
                    $this->logger->error('Migration task failed', ['task' => $task->getName()]);
                }
                
            } catch (\Exception $e) {
                $failed[] = $task->getName();
                $this->logger->error('Migration task threw exception', [
                    'task' => $task->getName(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        $success = empty($failed);
        
        if ($success) {
            // Update last_enabled_version after successful migration
            $this->updateLastEnabledVersion();
            $this->logger->info('Migration completed successfully', [
                'executed_tasks' => $executed,
                'skipped_tasks' => count($this->getAllAvailableTasks()) - count($tasks),
                'from_version' => $lastEnabledVersion,
                'to_version' => $currentVersion
            ]);
        }
        
        return [
            'success' => $success,
            'executed_tasks' => $executed,
            'failed_tasks' => $failed,
            'total_available_tasks' => count($this->getAllAvailableTasks()),
            'required_tasks' => count($tasks),
            'error' => $success ? null : 'Some migration tasks failed'
        ];
    }

    /**
     * Get all available migration tasks
     */
    private function getAllAvailableTasks(): array {
        return [
            new GermanFormalityMigrationTask($this->db, $this->logger),
        ];
    }
    
    /**
     * Get only the migration tasks required for upgrading from fromVersion to toVersion
     */
    private function getRequiredMigrationTasks(string $fromVersion, string $toVersion): array {
        $allTasks = $this->getAllAvailableTasks();
        $requiredTasks = [];
        
        foreach ($allTasks as $task) {
            $taskRequiredVersion = $task->getRequiredVersion();
            
            // Task is required if:
            // - fromVersion < taskRequiredVersion (wasn't available in previous version)
            // - toVersion >= taskRequiredVersion (is available in current version)
            if (version_compare($fromVersion, $taskRequiredVersion, '<') && 
                version_compare($toVersion, $taskRequiredVersion, '>=')) {
                
                $requiredTasks[] = $task;
                $this->logger->debug('Migration task required for version upgrade', [
                    'task' => $task->getName(),
                    'task_required_version' => $taskRequiredVersion,
                    'from_version' => $fromVersion,
                    'to_version' => $toVersion
                ]);
            } else {
                $this->logger->debug('Migration task not required for version upgrade', [
                    'task' => $task->getName(),
                    'task_required_version' => $taskRequiredVersion,
                    'from_version' => $fromVersion,
                    'to_version' => $toVersion
                ]);
            }
        }
        
        return $requiredTasks;
    }
    
    /**
     * Check if a migration task has already been completed
     */
    private function isTaskCompleted(IMigrationTask $task): bool {
        $taskId = $this->getTaskId($task);
        $completedTasks = $this->config->getAppValue(self::APP_NAME, 'completed_migration_tasks', '');
        $completed = $completedTasks ? explode(',', $completedTasks) : [];
        return in_array($taskId, $completed, true);
    }
    
    /**
     * Mark a migration task as completed
     */
    private function markTaskCompleted(IMigrationTask $task): void {
        $taskId = $this->getTaskId($task);
        $completedTasks = $this->config->getAppValue(self::APP_NAME, 'completed_migration_tasks', '');
        $completed = $completedTasks ? explode(',', $completedTasks) : [];
        
        if (!in_array($taskId, $completed, true)) {
            $completed[] = $taskId;
            $this->config->setAppValue(self::APP_NAME, 'completed_migration_tasks', implode(',', $completed));
        }
    }
    
    /**
     * Get unique identifier for a migration task
     */
    private function getTaskId(IMigrationTask $task): string {
        return $task->getRequiredVersion() . ':' . str_replace(' ', '_', strtolower($task->getName()));
    }
    
    /**
     * Get the current version from oc_appconfig (set by Nextcloud from info.xml)
     */
    public function getCurrentVersion(): string {
        return $this->config->getAppValue(self::APP_NAME, 'installed_version', '1.0.0');
    }
    
    /**
     * Update last_enabled_version to current version
     */
    public function updateLastEnabledVersion(): void {
        $currentVersion = $this->getCurrentVersion();
        $this->config->setAppValue(self::APP_NAME, 'last_enabled_version', $currentVersion);
    }
    
    /**
     * Clean up migration jobs (administrative method)
     */
    public function cleanupMigrationJobs(): bool {
        try {
            if (!$this->shouldRunMigration()) {
                if ($this->jobList->has(\OCA\AgendaBot\BackgroundJob\AgendaMigrationJob::class, null)) {
                    $this->jobList->remove(\OCA\AgendaBot\BackgroundJob\AgendaMigrationJob::class, null);
                    $this->logger->info('Cleaned up unnecessary migration job');
                    return true;
                }
            }
            return false;
        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup migration jobs', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}