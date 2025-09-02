<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Service;

use OCA\AgendaBot\AppInfo\Application;
use OCA\AgendaBot\Model\LogEntry;
use OCA\AgendaBot\Model\LogEntryMapper;
use OCA\Talk\Manager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

class AgendaService {
	// Updated pattern to capture flexible time formats
	public const AGENDA_PATTERN = '/^(agenda|topic|item|insert|add)\s*:\s*(?:#?(\d+)\.?\s*)?(.+?)\s*(?:\(([^)]+)\))?$/mi';

	public function __construct(
		private LogEntryMapper $logEntryMapper,
		private ITimeFactory $timeFactory,
		private IConfig $config,
		private PermissionService $permissionService,
		private LoggerInterface $logger,
		private IFactory $l10nFactory,
	) {
	}

	/**
	 * Parse agenda item from message
	 */
	public function parseAgendaItem(string $message): ?array {
		if (preg_match(self::AGENDA_PATTERN, $message, $matches)) {
			$durationText = isset($matches[4]) && $matches[4] !== '' ? trim($matches[4]) : '';
			$durationMinutes = $this->parseDurationToMinutes($durationText);
			
			return [
				'title' => trim($matches[3]),
				'duration' => $durationMinutes,
				'position' => isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : null,
			];
		}
		return null;
	}

	/**
	 * Parse various duration formats into minutes
	 * Supports: (1 m), (2min), (3 min), (4m), (5 m), (1h), (2 h), (3 hour), (4 hours)
	 */
	private function parseDurationToMinutes(string $durationText): int {
		if (empty($durationText)) {
			return 10; // Default 10 minutes
		}
		
		// Remove common words and normalize spacing
		$normalized = strtolower(trim($durationText));
		
		// Pattern for hours: (1h), (2 h), (3 hour), (4 hours)
		if (preg_match('/^(\d+)\s*(?:h|hour|hours)$/i', $normalized, $matches)) {
			return (int)$matches[1] * 60; // Convert hours to minutes
		}
		
		// Pattern for minutes: (1 m), (2min), (3 min), (4m), (5 m)
		if (preg_match('/^(\d+)\s*(?:m|min|mins|minute|minutes)$/i', $normalized, $matches)) {
			return (int)$matches[1];
		}
		
		// If it's just a number, assume minutes
		if (preg_match('/^(\d+)$/', $normalized, $matches)) {
			return (int)$matches[1];
		}
		
		// Fallback to default
		return 10;
	}

	/**
	 * Format duration in minutes to a human-readable string
	 * Returns "x h y min" for durations >= 60 minutes, "x min" otherwise
	 */
	private function formatDurationDisplay(int $minutes, string $lang = 'en'): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		if ($minutes < 60) {
			return $minutes . ' ' . $l->t('min');
		}
		
		$hours = intval($minutes / 60);
		$remainingMinutes = $minutes % 60;
		
		if ($remainingMinutes === 0) {
			return $hours . ' ' . $l->t('h');
		}
		
		return $hours . ' ' . $l->t('h %d min', [$remainingMinutes]);
	}

	/**
	 * Add agenda item (requires add permissions: types 1,2,3,6)
	 */
	public function addAgendaItem(string $token, array $agendaData, ?array $actorData = null, string $lang = 'en'): array {
		// Check add permissions if actor data is provided
		if ($actorData !== null && !$this->permissionService->canAddAgendaItems($token, $actorData)) {
			return [
				'success' => false,
				'message' => $this->permissionService->getAddAgendaDeniedMessage($lang)
			];
		}
		
		$position = $agendaData['position'] ?? $this->logEntryMapper->getNextAgendaPosition($token);
		$title = $agendaData['title'];
		$duration = $agendaData['duration'];

		// Check for conflicts
		if ($this->logEntryMapper->isAgendaPositionOccupied($token, $position)) {
			$position = $this->logEntryMapper->getNextAgendaPosition($token);
		}

		$logEntry = new LogEntry();
		$logEntry->setServer('local');
		$logEntry->setToken($token);
		$logEntry->setType(LogEntry::TYPE_AGENDA_ITEM);
		$logEntry->setDetails($title);
		$logEntry->setOrderPosition($position);
		$logEntry->setDurationMinutes($duration);
		$logEntry->setConflictResolved(false);
		$logEntry->setWarningSent(false);
		$logEntry->setIsCompleted(false);

		$this->logEntryMapper->insert($logEntry);

		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		return [
			'success' => true,
			'message' => '📋 ' . $l->t('Added agenda item %d: %s (%s)', [
				$position,
				$title,
				$this->formatDurationDisplay($duration, $lang)
			]),
		];
	}

	/**
	 * Get agenda items for a conversation
	 */
	public function getAgendaItems(string $token): array {
		$entries = $this->logEntryMapper->findAgendaItems($token);
		$items = [];

		foreach ($entries as $entry) {
			$items[] = [
				'id' => $entry->getId(),
				'position' => $entry->getOrderPosition(),
				'title' => $entry->getDetails(),
				'duration' => $entry->getDurationMinutes(),
				'completed' => $entry->getIsCompleted(),
				'completed_at' => $entry->getCompletedAt(),
			];
		}

		return $items;
	}

	/**
	 * Get agenda status
	 */
	public function getAgendaStatus(string $token, string $lang = 'en'): string {
		$items = $this->getAgendaItems($token);
		$currentItem = $this->getCurrentAgendaItem($token);

		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		$status = "### 📋 " . $l->t('Agenda Status') . "\n\n";

		if (empty($items)) {
			return $status . $l->t('No agenda items found.');
		}

		foreach ($items as $item) {
			$icon = '';
			$prefix = '';
			
			// Check if this is the current item
			if ($currentItem && $currentItem->getOrderPosition() === $item['position']) {
				$prefix = $l->t('Current Item') . ' **';
				$timeSpent = $this->getTimeSpentOnItem($currentItem);
				$timeSpentDisplay = $this->formatDurationDisplay($timeSpent);
				$plannedDisplay = $this->formatDurationDisplay($item['duration']);
				$timeInfo = "** *(" . $l->t('%s/%s', [$timeSpentDisplay, $plannedDisplay]) . ")*";
			} elseif ($item['completed']) {
				$icon = $l->t('Completed') . ' ';
				$actualDuration = $this->getActualDurationForCompletedItem($token, $item['position']);
				$actualDisplay = $this->formatDurationDisplay($actualDuration);
				$plannedDisplay = $this->formatDurationDisplay($item['duration']);
				$timeInfo = " *(" . $l->t('%s/%s', [$actualDisplay, $plannedDisplay]) . ")*";
			} else {
				$icon = $l->t('Pending') . ' ';
				$timeInfo = " *(" . $l->t('%s', [$this->formatDurationDisplay($item['duration'])]) . ")*";
			}
			
			$status .= "{$prefix}{$icon}{$item['position']}. {$item['title']}{$timeInfo}\n";
		}

		return $status;
	}

	/**
	 * Get the current active agenda item
	 */
	public function getCurrentAgendaItem(string $token): ?LogEntry {
		return $this->logEntryMapper->findCurrentAgendaItem($token);
	}

	/**
	 * Set the current agenda item (requires moderator permissions)
	 */
	public function setCurrentAgendaItem(string $token, int $position, ?array $actorData = null, string $lang = 'en'): ?string {
		// Check moderator permissions if actor data is provided
		if ($actorData !== null && !$this->permissionService->isActorModerator($token, $actorData)) {
			$l = $this->l10nFactory->get(Application::APP_ID, $lang);
			return $this->permissionService->getPermissionDeniedMessage($l->t('set the current agenda item'), $lang);
		}
		
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		$item = $this->logEntryMapper->findAgendaItemByPosition($token, $position);
		if (!$item) {
			return '❌ ' . $l->t('Agenda item %d not found', [$position]);
		}

		if ($item->getIsCompleted()) {
			return 'ℹ️ ' . $l->t('Cannot set completed item %d as current: "%s"', [
				$position,
				$item->getDetails()
			]);
		}

		// Clear current status from other items
		$this->clearCurrentAgendaItems($token);

		// Set this item as current
		$item->setStartTime($this->timeFactory->now()->getTimestamp());
		$this->logEntryMapper->update($item);

		return '➡️ ' . $l->t('Set agenda item %d as current: "%s"', [$position, $item->getDetails()]);
	}

	/**
	 * Clear all current agenda items for a conversation
	 */
	private function clearCurrentAgendaItems(string $token): void {
		$items = $this->logEntryMapper->findAgendaItems($token);
		foreach ($items as $item) {
			if ($item->getStartTime() !== null && !$item->getIsCompleted()) {
				$item->setStartTime(null);
				$this->logEntryMapper->update($item);
			}
		}
	}

	/**
	 * Get time spent on an agenda item in minutes
	 */
	private function getTimeSpentOnItem(LogEntry $item): int {
		if ($item->getStartTime() === null) {
			return 0;
		}

		$now = $this->timeFactory->now()->getTimestamp();
		$timeSpent = $now - $item->getStartTime();
		
		// Convert seconds to minutes, round up
		return (int) ceil($timeSpent / 60);
	}

	/**
	 * Get actual duration spent on a completed agenda item in minutes
	 */
	private function getActualDurationForCompletedItem(string $token, int $position): int {
		$item = $this->logEntryMapper->findAgendaItemByPosition($token, $position);
		
		if (!$item || !$item->getIsCompleted()) {
			return 0;
		}
		
		// If we don't have start time or completed time, return 0
		if ($item->getStartTime() === null || $item->getCompletedAt() === null) {
			return 0;
		}
		
		// Calculate actual time spent from start to completion
		$timeSpent = $item->getCompletedAt() - $item->getStartTime();
		
		// Convert seconds to minutes, round up
		return (int) ceil($timeSpent / 60);
	}

	/**
	 * Move to the next incomplete agenda item
	 */
	private function moveToNextIncompleteItem(string $token): ?LogEntry {
		$items = $this->logEntryMapper->findIncompleteAgendaItems($token);
		
		if (empty($items)) {
			return null;
		}

		// Find the first incomplete item
		$nextItem = $items[0];
		
		// Clear current status from other items
		$this->clearCurrentAgendaItems($token);

		// Set this item as current
		$nextItem->setStartTime($this->timeFactory->now()->getTimestamp());
		$this->logEntryMapper->update($nextItem);
		
		return $nextItem;
	}

	/**
	 * Check if a call is currently active for the given token
	 */
	private function isCallActive(string $token): bool {
		try {
			// We need to use the Talk Manager, but we don't have it injected
			// For now, we'll use a simple heuristic: check if there are any active agenda items
			// This is a temporary solution until we can inject Talk Manager
			$currentItem = $this->getCurrentAgendaItem($token);
			return $currentItem !== null && $currentItem->getStartTime() !== null;
		} catch (\Exception $e) {
			$this->logger->debug('Failed to check call status: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Mark agenda item as completed (requires moderator permissions)
	 */
	public function completeAgendaItem(string $token, int $position, ?array $actorData = null, string $lang = 'en'): ?string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Check moderator permissions if actor data is provided
		if ($actorData !== null && !$this->permissionService->isActorModerator($token, $actorData)) {
			return $this->permissionService->getPermissionDeniedMessage($l->t('complete agenda items'), $lang);
		}
		
		$item = $this->logEntryMapper->findAgendaItemByPosition($token, $position);
		if (!$item) {
			return '❌ ' . $l->t('Agenda item %d not found', [$position]);
		}

		if ($item->getIsCompleted()) {
			return 'ℹ️ ' . $l->t('Agenda item %d is already completed: "%s"', [$position, $item->getDetails()]);
		}

		$item->setIsCompleted(true);
		$item->setCompletedAt($this->timeFactory->now()->getTimestamp());
		// Keep startTime for duration calculation, just mark as completed
		$this->logEntryMapper->update($item);

		$response = '✅ ' . $l->t('Marked agenda item %d as completed: "%s"', [$position, $item->getDetails()]);

		return $response;
	}

	/**
	 * Complete the current agenda item and move to next
	 */
	public function completeCurrentAgendaItem(string $token, string $lang = 'en'): ?string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		$currentItem = $this->getCurrentAgendaItem($token);
		
		if (!$currentItem) {
			return '❌ ' . $l->t('No current agenda item is active');
		}

		if ($currentItem->getIsCompleted()) {
			return 'ℹ️ ' . $l->t('Current agenda item %d is already completed: "%s"', [$currentItem->getOrderPosition(), $currentItem->getDetails()]);
		}

		// Calculate actual time spent before marking as completed
		$actualTime = $this->getTimeSpentOnItem($currentItem);
		$plannedTime = $currentItem->getDurationMinutes();
		$actualDisplay = $this->formatDurationDisplay($actualTime, $lang);
		$plannedDisplay = $this->formatDurationDisplay($plannedTime, $lang);

		// Mark as completed
		$currentItem->setIsCompleted(true);
		$currentItem->setCompletedAt($this->timeFactory->now()->getTimestamp());
		// Keep startTime for duration calculation, just mark as completed
		$this->logEntryMapper->update($currentItem);

		$response = "#### ✅ " . $l->t('Completed current agenda item %d: **"%s"** (%s/%s)', [
			$currentItem->getOrderPosition(), 
			$currentItem->getDetails(), 
			$actualDisplay, 
			$plannedDisplay
		]);

		// Always try to move to next incomplete item (the done: command is typically used during calls)
		$nextItem = $this->moveToNextIncompleteItem($token);
		if ($nextItem) {
			$nextPlannedDisplay = $this->formatDurationDisplay($nextItem->getDurationMinutes(), $lang);
			$response .= "\n➡️ " . $l->t('Moving to next item %d:', [$nextItem->getOrderPosition()]);
			$response .= "\n#### \"" . $nextItem->getDetails() . "\" (" . $nextPlannedDisplay . ")";
		} else {
			$response .= "\n\n#### 🎉 " . $l->t('All agenda items completed!');
		}

		return $response;
	}

	/**
	 * Reopen/mark agenda item as incomplete (requires moderator permissions)
	 */
	public function reopenAgendaItem(string $token, int $position, ?array $actorData = null, string $lang = 'en'): ?string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Check moderator permissions if actor data is provided
		if ($actorData !== null && !$this->permissionService->isActorModerator($token, $actorData)) {
			return $this->permissionService->getPermissionDeniedMessage($l->t('reopen agenda items'), $lang);
		}
		
		$item = $this->logEntryMapper->findAgendaItemByPosition($token, $position);
		if (!$item) {
			return '❌ ' . $l->t('Agenda item %d not found', [$position]);
		}

		if (!$item->getIsCompleted()) {
			return '️ℹ️ ' . $l->t('Agenda item %d is already open/incomplete: "%s"', [$position, $item->getDetails()]);
		}

		$item->setIsCompleted(false);
		$item->setCompletedAt(null);
		$this->logEntryMapper->update($item);

		return '🔄 ' . $l->t('Reopened agenda item %d: "%s"', [$position, $item->getDetails()]);
	}

	/**
	 * Clear all agenda items for a conversation (requires moderator permissions)
	 */
	public function clearAgenda(string $token, ?array $actorData = null, string $lang = 'en'): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Check moderator permissions if actor data is provided
		if ($actorData !== null && !$this->permissionService->isActorModerator($token, $actorData)) {
			return $this->permissionService->getPermissionDeniedMessage($l->t('clear the agenda'), $lang);
		}
		
		$items = $this->logEntryMapper->findAgendaItems($token);
		$count = count($items);

		foreach ($items as $item) {
			$this->logEntryMapper->delete($item);
		}

		return '🗑️ ' . $l->t('Cleared %d agenda items', [$count]);
	}

	/**
	 * Get time monitoring configuration
	 */
	public function getTimeMonitoringConfig(): array {
		return [
			'enabled' => $this->config->getAppValue('agenda_bot', 'time-monitoring-enabled', 'true') === 'true',
			'warning_threshold_80' => (float)$this->config->getAppValue('agenda_bot', 'warning-threshold-80', '0.8'),
			'warning_threshold_100' => (float)$this->config->getAppValue('agenda_bot', 'warning-threshold-100', '1.0'),
			'overtime_threshold' => (float)$this->config->getAppValue('agenda_bot', 'overtime-warning-threshold', '1.2'),
			'check_interval' => (int)$this->config->getAppValue('agenda_bot', 'monitor-check-interval', '120'),
		];
	}

	/**
	 * Set time monitoring configuration (requires moderator permissions)
	 */
	public function setTimeMonitoringConfig(array $config, string $token, ?array $actorData = null, string $lang = 'en'): array {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Check moderator permissions if actor data is provided
		if ($actorData !== null && !$this->permissionService->isActorModerator($token, $actorData)) {
			return [
				'success' => false,
				'message' => $this->permissionService->getPermissionDeniedMessage($l->t('configure time monitoring settings'), $lang)
			];
		}
		
		$result = ['success' => true, 'message' => ''];
		$changes = [];
		
		if (isset($config['enabled'])) {
			$this->config->setAppValue('agenda_bot', 'time-monitoring-enabled', $config['enabled'] ? 'true' : 'false');
			$changes[] = $l->t('enabled status', [$config['enabled'] ? $l->t('enabled') : $l->t('disabled')]);
		}
		
		if (isset($config['warning_threshold_80'])) {
			$threshold = max(0.1, min(1.0, (float)$config['warning_threshold_80']));
			$this->config->setAppValue('agenda_bot', 'warning-threshold-80', (string)$threshold);
			$changes[] = $l->t('80%% warning at %d%%', [round($threshold * 100)]);
		}
		
		if (isset($config['warning_threshold_100'])) {
			$threshold = max(0.5, min(2.0, (float)$config['warning_threshold_100']));
			$this->config->setAppValue('agenda_bot', 'warning-threshold-100', (string)$threshold);
			$changes[] = $l->t('100%% warning at %d%%', [round($threshold * 100)]);
		}
		
		if (isset($config['overtime_threshold'])) {
			$threshold = max(1.0, min(3.0, (float)$config['overtime_threshold']));
			$this->config->setAppValue('agenda_bot', 'overtime-warning-threshold', (string)$threshold);
			$changes[] = $l->t('Overtime warning at %d%%', [round($threshold * 100)]);
		}
		
		if (isset($config['check_interval'])) {
			$interval = max(30, min(600, (int)$config['check_interval']));
			$this->config->setAppValue('agenda_bot', 'monitor-check-interval', (string)$interval);
			$changes[] = $l->t('check interval status', [$interval]);
		}
		
		if (empty($changes)) {
			$result['success'] = false;
			$result['message'] = '❌ ' . $l->t('No valid configuration changes provided');
		} else {
			$result['message'] = '✅ ' . $l->t('Updated time monitoring: %s', [implode(', ', $changes)]);
		}
		
		return $result;
	}

	/**
	 * Get formatted time monitoring status
	 */
	public function getTimeMonitoringStatus(string $lang = 'en'): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		$config = $this->getTimeMonitoringConfig();
		
		$status = "### ⏰ **" . $l->t('Time Monitoring Configuration') . ":**\n\n";
		
		if (!$config['enabled']) {
			$status .= "❌ **" . $l->t("Disabled") . "** - " . $l->t("No time warnings will be sent") . "\n\n";
		} else {
			$status .= "✅ **" . $l->t("Enabled") . "** - " . $l->t("Active monitoring with the following thresholds") . ":\n\n";
			$status .= "• **" . $l->t("First Warning") . "**: " . 
				$l->t("%.0f%% of planned time", [$config['warning_threshold_80'] * 100]) . "\n";
			$status .= "• **" . $l->t("Time Limit Warning") . "**: " . 
				$l->t("%.0f%% of planned time", [$config['warning_threshold_100'] * 100]) . "\n";
			$status .= "• **" . $l->t("Overtime Alert") . "**: " . 
				$l->t("%.0f%% of planned time", [$config['overtime_threshold'] * 100]) . "\n";
			$status .= "• **" . $l->t("Check Interval") . "**: " . $l->t("%d minutes (%s)", [5, $l->t("fixed")]) . "\n\n";
		}
		
		$status .= "**" . $l->t("Configuration Commands") . ":**\n";
		$status .= "• `time config` - " . $l->t("Show time monitoring configuration") . "\n";
		$status .= "• `time enable` / `time disable` - " . $l->t("Enable/disable monitoring") . "\n";
		$status .= "• `time thresholds 75 100 125` - " . $l->t("Set warning thresholds") . " (" . $l->t("percentages") . ")\n";
		
		return $status;
	}

	/**
	 * Get agenda help based on user role
	 */
	public function getAgendaHelp(string $token = '', ?array $actorData = null, string $lang = 'en'): string {
		$isModerator = false;
		$canAddItems = false;
		$participantType = null;
		
		// Check user permissions
		if (!empty($token) && $actorData !== null) {
			$participantType = (int)($actorData['talkParticipantType'] ?? 0);
			try {
				$isModerator = $this->permissionService->isActorModerator($token, $actorData);
				$canAddItems = $this->permissionService->canAddAgendaItems($token, $actorData);
			} catch (\Exception $e) {
				$this->logger->error('Permission check failed in getAgendaHelp: ' . $e->getMessage(), [
					'token' => $token,
					'actorData' => $actorData,
					'exception' => $e,
				]);
				// If permission check fails, default to false (view-only)
				$isModerator = false;
				$canAddItems = false;
			}
		}
		
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Base help content available to all users
		$help = "### 📋 **" . $l->t('Agenda Commands:') . "**\n\n" .
				"**" . $l->t('Status & Viewing:') . "**\n" .
				"• `agenda status` - " . $l->t('Show current agenda status') . "\n" .
				"• `agenda list` - " . $l->t('Show agenda items') . "\n" .
				"• `agenda help` - " . $l->t('Show this help message') . "\n\n";
		
		// Add item commands for users who can add (types 1,2,3,6)
		if ($canAddItems) {
			$help .= "**" . $l->t('Adding Items:') . "**\n" .
					 "• `agenda: Topic name (15 min)` - " . $l->t('Add agenda item with time') . "\n" .
					 "• `topic: Meeting topic (1h)` - " . $l->t('Alternative syntax') . "\n" .
					 "• `add: Another topic` - " . $l->t('Add item (10 min default)') . "\n" .
					 "**" . $l->t('Time Formats:') . "** `(5 m)`, `(10 min)`, `(1h)`, `(2 hours)`, `(90 min)`\n\n";
		}
		
		// Time monitoring - available to all users for viewing
		$help .= "**" . $l->t('Time Monitoring:') . "**\n" .
				 "• `time config` - " . $l->t('Show time monitoring configuration') . "\n";
		
		// Full moderator commands for types 1,2,6 (Owner, Moderator, Guest with moderator permissions)
		if ($isModerator) {
			$help .= "\n**" . $l->t('Moderator Commands:') . "**\n" .
					 "• `agenda clear` - " . $l->t('Clear all agenda items') . " 🔒\n" .
					 "• `cleanup` / `agenda cleanup` - " . $l->t('Remove completed items') . " 🔒\n" .
					 "• `next: 2` - " . $l->t('Set agenda item %d as current', [2]) . " 🔒\n" .
					 "• `complete: 1` / `done: 1` / `close: 1` - " . $l->t('Mark item as completed') . " 🔒\n" .
					 "• `done:` - " . $l->t('Complete current item and move to next') . " 🔒\n" .
					 "• `incomplete: 1` / `undone: 1` / `reopen: 1` - " . $l->t('Reopen completed item') . " 🔒\n" .
					 "• `time enable` / `time disable` - " . $l->t('Enable/disable time warnings') . " 🔒\n" .
					 "• `time thresholds 75 100 125` - " . $l->t('Set warning thresholds (percentages)') . " 🔒\n" .
					 "• `reorder: 2,1,4,3` - " . $l->t('Reorder agenda items') . " 🔒\n" .
					 "• `move: 3 to 1` - " . $l->t('Move item %d to position %d', [3, 1]) . " 🔒\n" .
					 "• `swap: 1,3` - " . $l->t('Swap agenda items %d and %d', [1, 3]) . " 🔒\n" .
					 "• `remove: 2` / `delete: 2` - " . $l->t('Remove agenda item %d', [2]) . " 🔒\n\n" .
					 "*" . $l->t('🔒 Require moderator/owner access') . "*";
		} else {
			// Show different messages based on participant type
			if ($participantType === 3) {
				// Regular users (type 3) can add items but not manage
				$help .= "\n*" . $l->t('🔒 Advanced management commands require moderator/owner permissions') . "*";
			} elseif (in_array($participantType, [4, 5])) {
				// Guests and public link users (types 4,5) are view-only
				$help .= "\n*" . $l->t('🔒 You have view-only access. Adding and managing agenda items requires higher permissions') . "*";
			} else {
				// Fallback for unknown types
				$help .= "\n*" . $l->t('🔒 Some commands require moderator/owner permissions') . "*";
			}
		}
		
		return $help;
	}

	/**
	 * Reorder agenda items to specified positions (requires moderator permissions)
	 */
	public function reorderAgendaItems(string $token, array $positions, ?array $actorData = null, string $lang = 'en'): ?string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Check moderator permissions if actor data is provided
		if ($actorData !== null && !$this->permissionService->isActorModerator($token, $actorData)) {
			return $this->permissionService->getPermissionDeniedMessage($l->t('reorder agenda items'), $lang);
		}
		$items = $this->logEntryMapper->findAgendaItems($token);
		
		if (empty($items)) {
			return '❌ ' . $l->t('No agenda items to reorder');
		}
		
		if (count($positions) !== count($items)) {
			return '❌ ' . $l->t('Invalid number of positions (%d items vs %d positions)', [count($items), count($positions)]);
		}
		
		// Validate positions
		$sortedPositions = array_values($positions);
		sort($sortedPositions);
		$expectedPositions = range(1, count($items));
		
		if ($sortedPositions !== $expectedPositions) {
			return '❌ ' . $l->t('Invalid positions - must use positions 1-%d exactly once', [count($items)]);
		}
		
		// Build update array
		$updates = [];
		foreach ($items as $index => $item) {
			$newPosition = $positions[$index];
			if ($item->getOrderPosition() !== $newPosition) {
				$updates[$item->getId()] = $newPosition;
			}
		}
		
		if (empty($updates)) {
			return '✅ ' . $l->t('No changes needed - agenda is already in the requested order');
		}
		
		// Apply updates
		$this->logEntryMapper->updateAgendaPositions($token, $updates);
		
		return '🔄 ' . $l->t('Reordered agenda items: [%s]', [implode(', ', $positions)]);
	}

	/**
	 * Move agenda item from one position to another (requires moderator permissions)
	 */
	public function moveAgendaItem(string $token, int $from, int $to, ?array $actorData = null, string $lang = 'en'): ?string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Check moderator permissions if actor data is provided
		if ($actorData !== null && !$this->permissionService->isActorModerator($token, $actorData)) {
			return $this->permissionService->getPermissionDeniedMessage($l->t('move agenda items'), $lang);
		}
		$fromItem = $this->logEntryMapper->findAgendaItemByPosition($token, $from);
		if (!$fromItem) {
			return '❌ ' . $l->t('Agenda item %d not found', [$from]);
		}
		
		$items = $this->logEntryMapper->findAgendaItems($token);
		if ($to < 1 || $to > count($items)) {
			return '❌ ' . $l->t('Target position %d is invalid (must be 1-%d)', [$to, count($items)]);
		}
		
		if ($from === $to) {
			return '✅ ' . $l->t('Item %d is already at position %d', [$from, $to]);
		}
		
		// Calculate new positions for all items
		$updates = [];
		
		foreach ($items as $item) {
			$currentPos = $item->getOrderPosition();
			$newPos = $currentPos;
			
			if ($currentPos === $from) {
				// This is the item being moved
				$newPos = $to;
			} elseif ($from < $to && $currentPos > $from && $currentPos <= $to) {
				// Items between from and to shift left
				$newPos = $currentPos - 1;
			} elseif ($from > $to && $currentPos >= $to && $currentPos < $from) {
				// Items between to and from shift right
				$newPos = $currentPos + 1;
			}
			
			if ($newPos !== $currentPos) {
				$updates[$item->getId()] = $newPos;
			}
		}
		
		// Apply updates
		$this->logEntryMapper->updateAgendaPositions($token, $updates);
		
		return '🔄 ' . $l->t('Moved "%s" from position %d to %d', [
			$fromItem->getDetails(),
			$from,
			$to
		]);
	}

	/**
	 * Swap two agenda items (requires moderator permissions)
	 */
	public function swapAgendaItems(string $token, int $item1, int $item2, ?array $actorData = null, string $lang = 'en'): ?string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Check moderator permissions if actor data is provided
		if ($actorData !== null && !$this->permissionService->isActorModerator($token, $actorData)) {
			return $this->permissionService->getPermissionDeniedMessage($l->t('swap agenda items'), $lang);
		}
		$firstItem = $this->logEntryMapper->findAgendaItemByPosition($token, $item1);
		$secondItem = $this->logEntryMapper->findAgendaItemByPosition($token, $item2);
		
		if (!$firstItem) {
			return '❌ ' . $l->t('Agenda item %d not found', [$item1]);
		}
		
		if (!$secondItem) {
			return '❌ ' . $l->t('Agenda item %d not found', [$item2]);
		}
		
		if ($item1 === $item2) {
			return '✅ ' . $l->t('Cannot swap item %d with itself', [$item1]);
		}
		
		// Swap positions
		$updates = [
			$firstItem->getId() => $item2,
			$secondItem->getId() => $item1
		];
		
		$this->logEntryMapper->updateAgendaPositions($token, $updates);
		
		return '🔄 ' . $l->t('Swapped "%s" (pos %d) ↔ "%s" (pos %d)', [
			$firstItem->getDetails(),
			$item1,
			$secondItem->getDetails(),
			$item2
		]);
	}

	/**
	 * Remove agenda item completely (requires moderator permissions)
	 */
	public function removeAgendaItem(string $token, int $position, ?array $actorData = null, string $lang = 'en'): ?string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Check moderator permissions if actor data is provided
		if ($actorData !== null && !$this->permissionService->isActorModerator($token, $actorData)) {
			return $this->permissionService->getPermissionDeniedMessage($l->t('remove agenda items'), $lang);
		}
		
		$item = $this->logEntryMapper->findAgendaItemByPosition($token, $position);
		if (!$item) {
			return '❌ ' . $l->t('Agenda item %d not found', [$position]);
		}

		$itemTitle = $item->getDetails();
		
		// Delete the item
		$this->logEntryMapper->delete($item);

		// Reorder remaining items to close gaps
		$this->compactAgendaPositions($token, $position);

		return '🗑️ ' . $l->t('Removed agenda item %d: "%s"', [$position, $itemTitle]);
	}

	/**
	 * Compact agenda positions after item removal
	 */
	private function compactAgendaPositions(string $token, int $removedPosition): void {
		$items = $this->logEntryMapper->findAgendaItems($token);
		$updates = [];

		// Find items that need to be shifted up
		foreach ($items as $item) {
			if ($item->getOrderPosition() > $removedPosition) {
				$updates[$item->getId()] = $item->getOrderPosition() - 1;
			}
		}

		if (!empty($updates)) {
			$this->logEntryMapper->updateAgendaPositions($token, $updates);
		}
	}

	/**
	 * Remove all completed agenda items and reorder remaining items (requires moderator permissions)
	 */
	public function removeCompletedItems(string $token, ?array $actorData = null, string $lang = 'en'): ?string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Check moderator permissions if actor data is provided
		if ($actorData !== null && !$this->permissionService->isActorModerator($token, $actorData)) {
			return $this->permissionService->getPermissionDeniedMessage($l->t('remove completed agenda items'), $lang);
		}
		
		$allItems = $this->logEntryMapper->findAgendaItems($token);
		$completedItems = array_filter($allItems, fn($item) => $item->getIsCompleted());
		$incompleteItems = array_filter($allItems, fn($item) => !$item->getIsCompleted());
		
		if (empty($completedItems)) {
			return '✅ ' . $l->t('No completed items to remove');
		}
		
		$completedCount = count($completedItems);
		
		// Remove all completed items
		foreach ($completedItems as $item) {
			$this->logEntryMapper->delete($item);
		}
		
		// Reorder remaining items starting from position 1
		if (!empty($incompleteItems)) {
			// Sort incomplete items by their current position
			usort($incompleteItems, fn($a, $b) => $a->getOrderPosition() <=> $b->getOrderPosition());
			
			$updates = [];
			foreach ($incompleteItems as $index => $item) {
				$newPosition = $index + 1; // Start from 1
				if ($item->getOrderPosition() !== $newPosition) {
					$updates[$item->getId()] = $newPosition;
				}
			}
			
			if (!empty($updates)) {
				$this->logEntryMapper->updateAgendaPositions($token, $updates);
			}
			
			return '🧹 ' . $l->t('Removed %d completed items and reordered %d remaining items', [$completedCount, count($incompleteItems)]);
		} else {
			return '🧹 ' . $l->t('Removed %d completed items - agenda is now empty', [$completedCount]);
		}
	}

	/**
	 * Check if a message is an agenda summary by looking for the summary header
	 */
	public function isSummaryMessage(string $token, string $messageId): bool {
		// For now, we'll rely on the reaction emoji validation to ensure proper cleanup
		// In a full implementation, we could store summary message IDs in the database
		// But since reactions are only on summary messages that contain the header,
		// we can assume reactions with cleanup emojis are on summary messages
		return true;
	}

	/**
	 * Export agenda items for summary
	 */
	public function exportAgenda(string $token): array {
		$items = $this->getAgendaItems($token);
		$completed = array_filter($items, fn($item) => $item['completed']);
		$incomplete = array_filter($items, fn($item) => !$item['completed']);

		// Add timing details for completed items
		$completedWithTiming = [];
		$inTimeCount = 0;
		$overdueCount = 0;

		foreach ($completed as $item) {
			$actualDuration = $this->getActualDurationForCompletedItem($token, $item['position']);
			$plannedDuration = $item['duration'];
			$timeDiff = $actualDuration - $plannedDuration;
			$isOverdue = $timeDiff > 0;
			
			if ($isOverdue) {
				$overdueCount++;
			} else {
				$inTimeCount++;
			}

			$completedWithTiming[] = array_merge($item, [
				'actual_duration' => $actualDuration,
				'time_diff' => $timeDiff,
				'is_overdue' => $isOverdue,
			]);
		}

	return [
			'total' => count($items),
			'completed' => count($completed),
			'incomplete' => count($incomplete),
			'items' => $items,
			'completed_items' => array_values($completed),
			'completed_items_with_timing' => $completedWithTiming,
			'incomplete_items' => array_values($incomplete),
			'timing_stats' => [
				'in_time_count' => $inTimeCount,
				'overdue_count' => $overdueCount,
				'in_time_percentage' => count($completed) > 0 ? round(($inTimeCount / count($completed)) * 100) : 0,
				'overdue_percentage' => count($completed) > 0 ? round(($overdueCount / count($completed)) * 100) : 0,
			],
		];
	}
}
