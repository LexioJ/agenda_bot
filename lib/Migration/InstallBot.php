<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Migration;

use OCA\AgendaBot\Service\BotService;
use OCA\AgendaBot\Migration\MigrationService;
use OCA\Talk\Events\BotInstallEvent;
use OCP\IURLGenerator;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class InstallBot implements IRepairStep {
	public function __construct(
		protected IURLGenerator $url,
		protected BotService $service,
		protected MigrationService $migrationService,
	) {
	}

	public function getName(): string {
		return 'Install Agenda Bot for Talk';
	}

	public function run(IOutput $output): void {
		if (!class_exists(BotInstallEvent::class)) {
			$output->warning('Talk not found, not installing Agenda Bot');
			return;
		}

		// Install/update bot
		$backend = $this->url->getAbsoluteURL('');
		$this->service->installBot($backend);
		
		// Schedule migrations if needed and update version tracking
		$this->migrationService->scheduleIfNeeded();
	}
}
