<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Service;

use DateTime;
use OCA\AgendaBot\AppInfo\Application;
use OCA\AgendaBot\Model\LogEntry;
use OCA\AgendaBot\Model\LogEntryMapper;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\L10N\IFactory;
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
		private IFactory $l10nFactory,
		private RoomConfigService $roomConfigService,
		private TimingUtilityService $timingUtilityService,
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

	$progressRatio = $this->timingUtilityService->calculateProgressRatio($elapsedMinutes, $plannedMinutes);
	$config = $this->getTimeMonitoringConfig($token);
	
	$this->logger->debug(sprintf("TimeMonitor: Checking item %d '%s' - %.1f/%d min (%.2f ratio), thresholds: warning=%.0f%%, 100%%=100%%, overtime=%.0f%%", $itemId, $title, $elapsedMinutes, $plannedMinutes, $progressRatio, ($config['warning_threshold'] ?? self::DEFAULT_WARNING_THRESHOLD_80) * 100, ($config['overtime_threshold'] ?? self::DEFAULT_OVERTIME_THRESHOLD) * 100));
		
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
	 * Ensures warnings are sent in proper sequence: approaching → overtime → overtime_critical
	 * Never sends lower-level warnings after higher-level ones have been sent
	 */
	private function determineWarningType(float $progressRatio, LogEntry $item): ?string {
		$config = $this->getTimeMonitoringConfig($item->getToken());
		
		if (!$config['enabled']) {
			return null;
		}
		
		// Get thresholds - simplified schema
		$warningThreshold = $config['warning_threshold'] ?? self::DEFAULT_WARNING_THRESHOLD_80;
		$timeReachedThreshold = 1.0; // Always 100% - fixed
		$overtimeThreshold = $config['overtime_threshold'] ?? self::DEFAULT_OVERTIME_THRESHOLD;
		
		// Check what warnings have already been sent to ensure proper progression
		$approachingSent = $this->hasWarningBeenSent($item, 'approaching');
		$overtimeSent = $this->hasWarningBeenSent($item, 'overtime');
		$overtimeCriticalSent = $this->hasWarningBeenSent($item, 'overtime_critical');
		
		$this->logger->debug(sprintf(
			"TimeMonitor: Item %d warnings status - approaching:%s, overtime:%s, critical:%s",
			$item->getId(),
			$approachingSent ? 'sent' : 'not-sent',
			$overtimeSent ? 'sent' : 'not-sent',
			$overtimeCriticalSent ? 'sent' : 'not-sent'
		));
		
		// Determine the next appropriate warning based on progression and current ratio
		if ($progressRatio >= $overtimeThreshold) {
			// We're in overtime critical zone - send only if not already sent
			if (!$overtimeCriticalSent) {
				$this->logger->debug(sprintf("TimeMonitor: Item %d triggering overtime_critical warning", $item->getId()));
				return 'overtime_critical';
			} else {
				$this->logger->debug(sprintf("TimeMonitor: Item %d overtime_critical already sent, skipping", $item->getId()));
			}
		} elseif ($progressRatio >= $timeReachedThreshold) {
			// We're at/past planned time (100%) - send only if not already sent AND overtime_critical hasn't been sent
			if (!$overtimeSent && !$overtimeCriticalSent) {
				$this->logger->debug(sprintf("TimeMonitor: Item %d triggering overtime warning", $item->getId()));
				return 'overtime';
			} else {
				$this->logger->debug(sprintf("TimeMonitor: Item %d overtime blocked (overtime:%s, critical:%s)", 
					$item->getId(), $overtimeSent ? 'sent' : 'not-sent', $overtimeCriticalSent ? 'sent' : 'not-sent'));
			}
		} elseif ($progressRatio >= $warningThreshold) {
			// We're approaching limit (configurable %) - send only if no higher warnings have been sent
			if (!$approachingSent && !$overtimeSent && !$overtimeCriticalSent) {
				$this->logger->debug(sprintf("TimeMonitor: Item %d triggering approaching warning", $item->getId()));
				return 'approaching';
			} else {
				$this->logger->debug(sprintf("TimeMonitor: Item %d approaching blocked (approaching:%s, overtime:%s, critical:%s)", 
					$item->getId(), $approachingSent ? 'sent' : 'not-sent', $overtimeSent ? 'sent' : 'not-sent', $overtimeCriticalSent ? 'sent' : 'not-sent'));
			}
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

			// Get room language for proper localization
			$lang = $this->roomConfigService->getRoomLanguage($token);
			$l = $this->l10nFactory->get(Application::APP_ID, $lang);
			
			// Generate warning message based on type with room language
			$message = $this->generateWarningMessage($warningType, $item, $elapsedMinutes, $plannedMinutes, $lang);
			
			// Use localized bot name to match BotService behavior
			$botName = $l->t('Agenda bot');
			
			// Send message as bot using ChatManager with proper bot actor format
			$this->chatManager->sendMessage(
				$room,
				null, // No participant for bot messages
				Attendee::ACTOR_BOTS, // Proper actor type for bots
				$botName, // Use localized bot name
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
	private function generateWarningMessage(string $warningType, LogEntry $item, float $elapsedMinutes, int $plannedMinutes, string $lang = 'en'): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		$title = $item->getDetails();
		$elapsedInt = (int)ceil($elapsedMinutes);
		$config = $this->getTimeMonitoringConfig($item->getToken());
		
		switch ($warningType) {
			case 'approaching':
				return '⏰ **' . $l->t('Time Check') . '**: ' . $l->t('"%s" is approaching time limit (%d of %d minutes used)', [$title, $elapsedInt, $plannedMinutes]);
				
			case 'overtime':
				return '⚠️ **' . $l->t('Time Alert') . '**: ' . $l->t('"%s" has reached planned time (%d min planned, %d min elapsed)', [$title, $plannedMinutes, $elapsedInt]);
				
			case 'overtime_critical':
				$overtimeMinutes = $elapsedInt - $plannedMinutes;
				$overtimePercent = round(($config['overtime_threshold'] - 1.0) * 100);
				return '🚨 **' . $l->t('Overtime Alert') . '**: ' . $l->t('"%s" has exceeded time limit by %d%% (%d min over, %d min planned, %d min elapsed)', [
					$title, 
					$overtimePercent, 
					$overtimeMinutes, 
					$plannedMinutes, 
					$elapsedInt
				]);
				
			default:
				return '⏰ ' . $l->t('Time monitoring alert for "%s"', [$title]);
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
	 * Get time monitoring configuration (room-aware)
	 */
	public function getTimeMonitoringConfig(?string $token = null): array {
		if ($token !== null) {
			return $this->roomConfigService->getRoomTimeMonitoringConfig($token);
		}
		
		// Fallback to global config when no token provided (backward compatibility)
		return [
			'enabled' => $this->config->getAppValue('agenda_bot', 'time-monitoring-enabled', 'true') === 'true',
			'warning_threshold' => (float)$this->config->getAppValue('agenda_bot', 'warning-threshold', (string)self::DEFAULT_WARNING_THRESHOLD_80),
			'overtime_threshold' => (float)$this->config->getAppValue('agenda_bot', 'overtime-warning-threshold', (string)self::DEFAULT_OVERTIME_THRESHOLD),
			'check_interval' => (int)$this->config->getAppValue('agenda_bot', 'monitor-check-interval', '300'),
			'source' => 'global',
		];
	}
}
