<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Service;

use DateTime;
use OCA\AgendaBot\Model\LogEntry;
use OCA\AgendaBot\Model\LogEntryMapper;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class TimeMonitorService {
	
	// Default warning thresholds as percentages of planned duration
	public const DEFAULT_WARNING_THRESHOLD_80 = 0.8;  // 80% of planned time
	public const DEFAULT_WARNING_THRESHOLD_100 = 1.0; // 100% of planned time (on time)
	public const DEFAULT_OVERTIME_THRESHOLD = 1.2; // 120% of planned time (20% overtime)

	public function __construct(
		private LogEntryMapper $logEntryMapper,
		private ITimeFactory $timeFactory,
		private IConfig $config,
		private LoggerInterface $logger,
		private ChatManager $chatManager,
		private Manager $talkManager,
	) {
	}

	/**
	 * Check all active agenda items across all conversations for time warnings
	 */
	public function checkAllActiveAgendaItems(): void {
		// Find all active agenda items that have been started
		$activeItems = $this->logEntryMapper->findActiveAgendaItems();
		
		$this->logger->debug('TimeMonitorService: Found ' . count($activeItems) . ' active agenda items to check');
		
		foreach ($activeItems as $item) {
			$this->checkAgendaItemTime($item);
		}
	}

	/**
	 * Check if a call is currently active for the given token
	 */
	private function isCallActive(string $token): bool {
		try {
			$room = $this->talkManager->getRoomByToken($token);
			if (!$room) {
				return false;
			}

			// Check if there's an active call in the room
			$call = $room->getActiveSince();
			return $call !== null;
		} catch (\Exception $e) {
			$this->logger->debug('Failed to check call status for token ' . $token . ': ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Check if an agenda item needs a time warning
	 */
	public function checkAgendaItemTime(LogEntry $item): void {
		$token = $item->getToken();
		$itemId = $item->getId();
		$title = $item->getDetails();
		
		if (!$item->getStartTime() || $item->getIsCompleted()) {
			$this->logger->debug("TimeMonitor: Skipping item {$itemId} '{$title}' - not started or completed");
			return; // Not started or already completed
		}

		// Only send warnings if call is currently active
		$callActive = $this->isCallActive($token);
		if (!$callActive) {
			$this->logger->debug("TimeMonitor: Skipping item {$itemId} '{$title}' - call not active for token {$token}");
			return; // Call not active, skip warnings
		}

		$now = $this->timeFactory->now()->getTimestamp();
		$elapsedMinutes = ($now - $item->getStartTime()) / 60;
		$plannedMinutes = $item->getDurationMinutes();
		
		if (!$plannedMinutes || $plannedMinutes <= 0) {
			$this->logger->debug("TimeMonitor: Skipping item {$itemId} '{$title}' - no planned duration");
			return; // No planned duration
		}

		$progressRatio = $elapsedMinutes / $plannedMinutes;
		$config = $this->getTimeMonitoringConfig();
		
		$this->logger->debug(sprintf("TimeMonitor: Checking item %d '%s' - %.1f/%d min (%.2f ratio), thresholds: 80%%=%s, 100%%=%s, overtime=%s", $itemId, $title, $elapsedMinutes, $plannedMinutes, $progressRatio, $config['warning_threshold_80'], $config['warning_threshold_100'], $config['overtime_threshold']));
		
		$warningType = $this->determineWarningType($progressRatio, $item);
		
		if ($warningType) {
			$this->logger->info("TimeMonitor: Sending {$warningType} warning for item {$itemId} '{$title}'");
			$this->sendTimeWarning($item, $warningType, $elapsedMinutes, $plannedMinutes);
		} else {
			$this->logger->debug(sprintf("TimeMonitor: No warning needed for item %d '%s' at ratio %.2f", $itemId, $title, $progressRatio));
		}
	}

	/**
	 * Determine if a warning should be sent based on progress ratio
	 */
	private function determineWarningType(float $progressRatio, LogEntry $item): ?string {
		$config = $this->getTimeMonitoringConfig();
		
		if (!$config['enabled']) {
			return null;
		}
		
		// Get configurable thresholds
		$overtimeThreshold = $config['overtime_threshold'];
		$warningThreshold100 = $config['warning_threshold_100'];
		$warningThreshold80 = $config['warning_threshold_80'];
		
		// Check each warning level in order of severity, ensuring we only send each warning once
		if ($progressRatio >= $overtimeThreshold && !$this->hasWarningBeenSent($item, 'overtime_critical')) {
			return 'overtime_critical'; // Configurable % over time
		} elseif ($progressRatio >= $warningThreshold100 && !$this->hasWarningBeenSent($item, 'overtime')) {
			return 'overtime'; // Exactly at or past planned time
		} elseif ($progressRatio >= $warningThreshold80 && !$this->hasWarningBeenSent($item, 'approaching')) {
			return 'approaching'; // 80% of planned time reached
		}
		
		return null;
	}

	/**
	 * Check if a specific warning type has been sent for this item
	 */
	private function hasWarningBeenSent(LogEntry $item, string $warningType): bool {
		// Check if there's already a warning log entry for this item and type
		$warnings = $this->logEntryMapper->findWarningsForAgendaItem($item->getId(), $warningType);
		return !empty($warnings);
	}

	/**
	 * Send a time warning message to the conversation
	 */
	private function sendTimeWarning(LogEntry $item, string $warningType, float $elapsedMinutes, int $plannedMinutes): void {
		try {
			$token = $item->getToken();

			// Get the room for this token
			$room = $this->talkManager->getRoomByToken($token);
			if (!$room) {
				$this->logger->warning('Could not find room for token: ' . $token);
				return;
			}

			// Generate warning message based on type
			$message = $this->generateWarningMessage($warningType, $item, $elapsedMinutes, $plannedMinutes);
			
			// Send message as bot using ChatManager with proper bot actor format
			$this->chatManager->sendMessage(
				$room,
				null, // No participant for bot messages
				Attendee::ACTOR_BOTS, // Proper actor type for bots
				'agenda_bot', // Simple bot identifier
				$message,
				DateTime::createFromImmutable($this->timeFactory->now()),
				null, // No reply to message
				'', // No reference ID
				false, // Not silent
				false // Don't rate limit guest mentions
			);

			// Log the warning to prevent duplicates - this is our primary duplicate prevention mechanism
			$this->logTimeWarning($item, $warningType, $elapsedMinutes, $plannedMinutes);

		} catch (\Exception $e) {
			$this->logger->error('Failed to send time warning: ' . $e->getMessage(), [
				'item_id' => $item->getId(),
				'warning_type' => $warningType,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Generate warning message based on warning type
	 */
	private function generateWarningMessage(string $warningType, LogEntry $item, float $elapsedMinutes, int $plannedMinutes): string {
		$title = $item->getDetails();
		$elapsedInt = (int)ceil($elapsedMinutes);
		$config = $this->getTimeMonitoringConfig();
		
		switch ($warningType) {
			case 'approaching':
				return sprintf('⏰ **Time Check**: "%s" is approaching time limit (%d of %d minutes used)', $title, $elapsedInt, $plannedMinutes);
				
			case 'overtime':
				return sprintf('⚠️ **Time Alert**: "%s" has reached planned time (%d min planned, %d min elapsed)', $title, $plannedMinutes, $elapsedInt);
				
			case 'overtime_critical':
				$overtimeMinutes = $elapsedInt - $plannedMinutes;
				$overtimePercent = round(($config['overtime_threshold'] - 1.0) * 100);
				return sprintf('🚨 **Overtime Alert**: "%s" has exceeded time limit by %d%% (%d min over, %d min planned, %d min elapsed)', 
					$title, $overtimePercent, $overtimeMinutes, $plannedMinutes, $elapsedInt);
				
			default:
				return sprintf('⏰ Time monitoring alert for "%s"', $title);
		}
	}

	/**
	 * Log a time warning to the database
	 */
	private function logTimeWarning(LogEntry $item, string $warningType, float $elapsedMinutes, int $plannedMinutes): void {
		$warning = new LogEntry();
		$warning->setServer('local');
		$warning->setToken($item->getToken());
		$warning->setType(LogEntry::TYPE_AGENDA_WARNING);
		$warning->setParentId($item->getId());
		$warning->setDetails(json_encode([
			'warning_type' => $warningType,
			'elapsed_minutes' => $elapsedMinutes,
			'planned_minutes' => $plannedMinutes,
			'timestamp' => $this->timeFactory->now()->getTimestamp(),
		], JSON_THROW_ON_ERROR));
		
		$this->logEntryMapper->insert($warning);
	}

	/**
	 * Start monitoring an agenda item
	 */
	public function startAgendaItem(LogEntry $item): void {
		if ($item->getStartTime()) {
			return; // Already started
		}

		$item->setStartTime($this->timeFactory->now()->getTimestamp());
		$item->setWarningSent(false); // Reset warning flag
		$this->logEntryMapper->update($item);
	}

	/**
	 * Stop monitoring an agenda item
	 */
	public function stopAgendaItem(LogEntry $item): void {
		$item->setStartTime(null);
		$item->setWarningSent(false);
		$this->logEntryMapper->update($item);
	}

	/**
	 * Complete an agenda item and stop monitoring
	 */
	public function completeAgendaItem(LogEntry $item): void {
		$item->setIsCompleted(true);
		$item->setCompletedAt($this->timeFactory->now()->getTimestamp());
		$this->logEntryMapper->update($item);
	}

	/**
	 * Get time monitoring configuration
	 */
	public function getTimeMonitoringConfig(): array {
		return [
			'enabled' => $this->config->getAppValue('agenda_bot', 'time-monitoring-enabled', 'true') === 'true',
			'warning_threshold_80' => (float)$this->config->getAppValue('agenda_bot', 'warning-threshold-80', (string)self::DEFAULT_WARNING_THRESHOLD_80),
			'warning_threshold_100' => (float)$this->config->getAppValue('agenda_bot', 'warning-threshold-100', (string)self::DEFAULT_WARNING_THRESHOLD_100),
			'overtime_threshold' => (float)$this->config->getAppValue('agenda_bot', 'overtime-warning-threshold', (string)self::DEFAULT_OVERTIME_THRESHOLD),
			'check_interval' => (int)$this->config->getAppValue('agenda_bot', 'monitor-check-interval', '120'),
		];
	}
}
