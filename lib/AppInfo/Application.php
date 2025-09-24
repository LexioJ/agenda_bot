<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\AppInfo;

use OCA\AgendaBot\Listener\BotInvokeListener;
use OCA\AgendaBot\Listener\AppEnableListener;
use OCA\AgendaBot\Migration\MigrationService;
use OCA\Talk\Events\BotInvokeEvent;
use OCP\App\Events\AppEnableEvent;
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
		$context->registerEventListener(AppEnableEvent::class, AppEnableListener::class);
	}

	public function boot(IBootContext $context): void {
		// Migration scheduling and version tracking is handled by AppEnableListener:
		// - Runs after app installation/upgrade when installed_version is accurate
		// - Makes proper version comparison for migration decisions
		// - Updates version tracking appropriately
		// 
		// InstallBot.scheduleIfNeeded() is kept for compatibility but no longer active
	}

}
