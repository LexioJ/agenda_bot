<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\BackgroundJob;

use OCA\AgendaBot\Service\AgendaService;
use OCA\AgendaBot\Service\TimeMonitorService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Background job to monitor agenda item durations and send time warnings
 */
class AgendaTimeMonitorJob extends TimedJob {
	
	public function __construct(
		ITimeFactory $time,
		private TimeMonitorService $timeMonitorService,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		
		// Use configurable check interval (default 120 seconds)
		$interval = (int)$this->config->getAppValue('agenda_bot', 'monitor-check-interval', '120');
		$this->setInterval($interval);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		try {
			// Check if time monitoring is enabled
			$enabled = $this->config->getAppValue('agenda_bot', 'time-monitoring-enabled', 'true') === 'true';
			if (!$enabled) {
				return;
			}

			// Update interval in case it was changed
			$interval = (int)$this->config->getAppValue('agenda_bot', 'monitor-check-interval', '120');
			$this->setInterval($interval);

			// Monitor all active agenda items across all conversations
			$this->timeMonitorService->checkAllActiveAgendaItems();
			
		} catch (\Exception $e) {
			$this->logger->error('AgendaTimeMonitorJob failed: ' . $e->getMessage(), [
				'exception' => $e,
				'job' => self::class,
			]);
		}
	}
}
