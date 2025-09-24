<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Listener;

use OCA\AgendaBot\AppInfo\Application;
use OCA\AgendaBot\Migration\MigrationService;
use OCP\App\Events\AppEnableEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Listener for app enable events to handle migration scheduling and version tracking
 * 
 * This runs after app installation/upgrade is complete, when installed_version
 * is accurate, allowing proper version comparison for migration decisions.
 */
class AppEnableListener implements IEventListener {

	public function __construct(
		private MigrationService $migrationService,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof AppEnableEvent)) {
			return;
		}

		// Only handle our app's enable events
		if ($event->getAppId() !== Application::APP_ID) {
			return;
		}

		// Now we have accurate version information - make migration decision
		if ($this->migrationService->shouldRunMigration()) {
			$this->migrationService->scheduleMigrationJob();
			// Don't update version tracking yet - wait for migration to complete
		} else {
			// No migration needed - update version tracking to current version
			$this->migrationService->updateLastEnabledVersion();
		}
	}
}