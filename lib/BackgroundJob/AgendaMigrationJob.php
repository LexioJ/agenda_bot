<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\BackgroundJob;

use OCA\AgendaBot\Migration\MigrationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Background job for executing Agenda Bot migration tasks
 * 
 * This job is automatically scheduled when:
 * - App is enabled for the first time
 * - App version changes during updates
 * - Manual migration triggers (admin only)
 */
class AgendaMigrationJob extends QueuedJob {
    
public function __construct(
        ITimeFactory $time,
        private MigrationService $migrationService,
        private IJobList $jobList,
        private IConfig $config,
        private LoggerInterface $logger
    ) {
        parent::__construct($time);
        // Set job to run only once
        $this->setAllowParallelRuns(false);
    }

    /**
     * Execute migration tasks and remove job when completed
     */
    protected function run($argument): void {
        $this->logger->info('Migration job started', ['argument' => $argument]);
        
        try {
            $result = $this->migrationService->executeMigrations();
            
            if ($result['success']) {
                $this->logger->info('Migration job completed successfully', [
                    'executed_tasks' => count($result['executed_tasks']),
                    'tasks' => $result['executed_tasks']
                ]);
                
                // Remove the migration job since it completed successfully
                $this->removeMigrationJob();
                
            } else {
                $this->logger->error('Migration job failed', [
                    'error' => $result['error'],
                    'attempted_tasks' => $result['attempted_tasks'] ?? []
                ]);
                // Keep job in queue for potential retry by leaving it scheduled
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Migration job encountered exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Keep job for retry - don't remove it
            throw $e;
        }
    }
    
    /**
     * Remove migration job from the job list
     */
    private function removeMigrationJob(): void {
        try {
            if ($this->jobList->has(self::class, null)) {
                $this->jobList->remove(self::class, null);
                $this->logger->info('Migration job removed from job list after successful completion');
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to remove migration job from job list', [
                'error' => $e->getMessage()
            ]);
        }
    }
}