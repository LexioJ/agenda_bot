<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\AppInfo;

use OCA\AgendaBot\Listener\BotInvokeListener;
use OCA\AgendaBot\Migration\MigrationService;
use OCA\Talk\Events\BotInvokeEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'agenda_bot';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(BotInvokeEvent::class, BotInvokeListener::class);
	}

	public function boot(IBootContext $context): void {
		// Initialize version tracking and schedule migrations if needed
		$this->initializeVersionTracking($context);
		$this->scheduleMigrationsIfNeeded($context);
	}

	/**
	 * Initialize version tracking for migration detection
	 */
	private function initializeVersionTracking(IBootContext $context): void {
		try {
			$server = $context->getServerContainer();
			$config = $server->getConfig();
			$logger = $server->get(\Psr\Log\LoggerInterface::class);
			
			$currentVersion = $config->getAppValue(self::APP_ID, 'installed_version', null);
			$lastEnabledVersion = $config->getAppValue(self::APP_ID, 'last_enabled_version', null);
			
			if ($lastEnabledVersion === null) {
				// This is the first time the app is enabled with version tracking
				// Set last_enabled_version to current version (fresh install, no migration needed)
				$versionToSet = $currentVersion ?? '1.5.0'; // Fallback to 1.5.0 if not set
				$config->setAppValue(self::APP_ID, 'last_enabled_version', $versionToSet);
				$logger->info('Fresh install detected - no migrations needed', [
					'last_enabled_version' => $versionToSet,
					'current_version' => $currentVersion
				]);
			}
			
		} catch (\Throwable $e) {
			// Don't let version tracking errors break app bootstrap
			$logger = $context->getServerContainer()->get(\Psr\Log\LoggerInterface::class);
			$logger->error('Failed to initialize version tracking', [
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Schedule migrations if needed using the Migration Framework
	 */
	private function scheduleMigrationsIfNeeded(IBootContext $context): void {
		try {
			$server = $context->getServerContainer();
			$migrationService = $server->get(MigrationService::class);
			
			// Schedule migrations if version upgrade is detected
			$migrationService->scheduleIfNeeded();
			
		} catch (\Throwable $e) {
			// Don't let migration scheduling errors break app bootstrap
			$logger = $context->getServerContainer()->get(\Psr\Log\LoggerInterface::class);
			$logger->error('Failed to schedule migrations', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
		}
	}

}
