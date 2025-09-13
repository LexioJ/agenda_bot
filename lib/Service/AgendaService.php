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
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

class AgendaService {
	// Updated pattern to capture flexible time formats
	public const AGENDA_PATTERN = '/^(agenda|topic|item|insert|add)\s*:\s*(?:#?(\d+)\.?\s*)?(.+?)\s*(?:\(([^)]+)\))?$/mi';
	
	// Pattern to detect bulk agenda format
	public const BULK_AGENDA_PATTERN = '/^agenda\s*:\s*\n([\s\S]+)$/mi';
	
	// Pattern for individual bullet points in bulk format
	public const BULLET_ITEM_PATTERN = '/^\s*[-*]\s+(?:#?(\d+)\.?\s*)?(.+?)\s*(?:\(([^)]+)\))?$/m';
	
	// Maximum number of items allowed in bulk operation
	public const MAX_BULK_ITEMS = 20;

	public function __construct(
		private LogEntryMapper $logEntryMapper,
		private ITimeFactory $timeFactory,
		private IConfig $config,
		private PermissionService $permissionService,
		private RoomConfigService $roomConfigService,
		private LoggerInterface $logger,
		private IFactory $l10nFactory,
		private IUserManager $userManager,
		private TimingUtilityService $timingUtilityService,
	) {
	}

	/**
	 * Parse agenda item from message
	 */
	public function parseAgendaItem(string $message): ?array {
		if (preg_match(self::AGENDA_PATTERN, $message, $matches)) {
			$durationText = isset($matches[4]) && $matches[4] !== '' ? trim($matches[4]) : '';
			$durationMinutes = $this->timingUtilityService->parseDurationToMinutes($durationText);
			
			return [
				'title' => trim($matches[3]),
				'duration' => $durationMinutes,
				'position' => isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : null,
			];
		}
		return null;
	}
	
	/**
	 * Parse bulk agenda items from message
	 * Format: agenda:\n- Item 1 (15m)\n- Item 2\n* Item 3 (30m)
	 */
	public function parseBulkAgendaItems(string $message): ?array {
		// Check if this matches the bulk agenda pattern
		if (!preg_match(self::BULK_AGENDA_PATTERN, $message, $matches)) {
			return null;
		}
		
		$bulkContent = trim($matches[1]);
		if (empty($bulkContent)) {
			return null;
		}
		
		// Split into lines and parse each bullet point
		$lines = explode("\n", $bulkContent);
		$items = [];
		$lineNumber = 2; // Start at 2 because "agenda:" is line 1
		
		foreach ($lines as $line) {
			$lineNumber++;
			$trimmedLine = trim($line);
			
			// Skip empty lines
			if (empty($trimmedLine)) {
				continue;
			}
			
			// Check if line matches bullet pattern
			if (preg_match(self::BULLET_ITEM_PATTERN, $trimmedLine, $itemMatches)) {
				$durationText = isset($itemMatches[3]) && $itemMatches[3] !== '' ? trim($itemMatches[3]) : '';
				$durationMinutes = $this->timingUtilityService->parseDurationToMinutes($durationText);
				$title = trim($itemMatches[2]);
				
				// Skip items with empty titles
				if (empty($title)) {
					continue;
				}
				
				$items[] = [
					'title' => $title,
					'duration' => $durationMinutes,
					'position' => isset($itemMatches[1]) && $itemMatches[1] !== '' ? (int)$itemMatches[1] : null,
					'line_number' => $lineNumber
				];
				
				// Check for max items limit
				if (count($items) > self::MAX_BULK_ITEMS) {
					return [
						'error' => 'max_items_exceeded',
						'found_items' => count($items),
						'max_items' => self::MAX_BULK_ITEMS
					];
				}
			}
		}
		
		// Return null if no valid items found
		if (empty($items)) {
			return null;
		}
		
		return [
			'items' => $items,
			'total_items' => count($items)
		];
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
					$this->timingUtilityService->formatDurationDisplay($duration, $lang)
				]),
			];
	}
	
	/**
	 * Add multiple agenda items in bulk (requires add permissions: types 1,2,3,6)
	 */
	public function addBulkAgendaItems(string $token, array $bulkData, ?array $actorData = null, string $lang = 'en'): array {
		// Check add permissions if actor data is provided
		if ($actorData !== null && !$this->permissionService->canAddAgendaItems($token, $actorData)) {
			return [
				'success' => false,
				'message' => $this->permissionService->getAddAgendaDeniedMessage($lang)
			];
		}
		
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Check for error conditions first
		if (isset($bulkData['error'])) {
			if ($bulkData['error'] === 'max_items_exceeded') {
				return [
					'success' => false,
					'message' => '❌ ' . $l->t('Too many items: %d items found, maximum is %d', [
						$bulkData['found_items'],
						$bulkData['max_items']
					])
				];
			}
		}
		
		$items = $bulkData['items'] ?? [];
		if (empty($items)) {
			return [
				'success' => false,
				'message' => '❌ ' . $l->t('No valid agenda items found in bulk format')
			];
		}
		
		$addedItems = [];
		$failedItems = [];
		$startingPosition = $this->logEntryMapper->getNextAgendaPosition($token);
		$currentPosition = $startingPosition;
		
		// Process each item
		foreach ($items as $index => $itemData) {
			try {
				// Determine position - use explicit position if provided, otherwise sequential
				$position = $itemData['position'] ?? $currentPosition;
				
				// Check for position conflicts and resolve
				if ($this->logEntryMapper->isAgendaPositionOccupied($token, $position)) {
					$position = $this->logEntryMapper->getNextAgendaPosition($token);
				}
				
				// Create log entry
				$logEntry = new LogEntry();
				$logEntry->setServer('local');
				$logEntry->setToken($token);
				$logEntry->setType(LogEntry::TYPE_AGENDA_ITEM);
				$logEntry->setDetails($itemData['title']);
				$logEntry->setOrderPosition($position);
				$logEntry->setDurationMinutes($itemData['duration']);
				$logEntry->setConflictResolved(false);
				$logEntry->setWarningSent(false);
				$logEntry->setIsCompleted(false);
				
				$this->logEntryMapper->insert($logEntry);
				
				$addedItems[] = [
					'position' => $position,
					'title' => $itemData['title'],
					'duration' => $itemData['duration'],
					'duration_display' => $this->timingUtilityService->formatDurationDisplay($itemData['duration'], $lang)
				];
				
				// Update current position for next item (if no explicit position)
				if (!isset($itemData['position'])) {
					$currentPosition = $position + 1;
				}
				
			} catch (\Exception $e) {
				$this->logger->error('Failed to add bulk agenda item', [
					'token' => $token,
					'item_index' => $index,
					'item_title' => $itemData['title'] ?? 'unknown',
					'error' => $e->getMessage()
				]);
				
				$failedItems[] = [
					'title' => $itemData['title'] ?? 'unknown',
					'line_number' => $itemData['line_number'] ?? ($index + 1),
					'error' => 'Database error'
				];
			}
		}
		
		// Generate response message
		if (empty($addedItems)) {
			return [
				'success' => false,
				'message' => '❌ ' . $l->t('Failed to add any agenda items')
			];
		}
		
		$message = '📋 ' . $l->t('Added %d agenda items:', [count($addedItems)]) . "\n";
		foreach ($addedItems as $item) {
			$message .= sprintf("• %d. %s (%s)\n", $item['position'], $item['title'], $item['duration_display']);
		}
		
		// Add warning for failed items if any
		if (!empty($failedItems)) {
			$message .= "\n⚠️ " . $l->t('Failed to add %d items', [count($failedItems)]);
		}
		
		return [
			'success' => true,
			'message' => trim($message),
			'added_count' => count($addedItems),
			'failed_count' => count($failedItems)
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
				'start_time' => $entry->getStartTime(),
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
			$timeSpent = $this->timingUtilityService->getTimeSpentOnItem($currentItem);
				$timeSpentDisplay = $this->timingUtilityService->formatDurationDisplay($timeSpent, $lang);
				$plannedDisplay = $this->timingUtilityService->formatDurationDisplay($item['duration'], $lang);
				$timeInfo = " *({$timeSpentDisplay}/{$plannedDisplay})*";
				$prefix = "`🗣️ {$item['position']} {$item['title']}`";
				$title = '';
			} elseif ($item['completed']) {
				$icon = $l->t('Completed') . ' ';
				$actualDuration = 0;
				if (!empty($item['start_time']) && !empty($item['completed_at'])) {
					$actualDuration = $this->timingUtilityService->calculateActualDurationFromTimestamps($item['start_time'], $item['completed_at']);
				}
				$actualDisplay = $this->timingUtilityService->formatDurationDisplay($actualDuration, $lang);
				$plannedDisplay = $this->timingUtilityService->formatDurationDisplay($item['duration'], $lang);
				$timeInfo = " *(" . $l->t('%s/%s', [$actualDisplay, $plannedDisplay]) . ")*";
				$title = $item['title'];
			} else {
				$icon = $l->t('Pending') . ' ';
				$timeInfo = " *(" . $l->t('%s', [$this->timingUtilityService->formatDurationDisplay($item['duration'], $lang)]) . ")*";
				$title = $item['title'];
			}
			
			// For current item, prefix already contains the full formatted string
			if ($currentItem && $currentItem->getOrderPosition() === $item['position']) {
				$status .= "{$prefix}{$timeInfo}\n";
			} else {
				$status .= "{$prefix}{$icon}{$item['position']}. {$title}{$timeInfo}\n";
			}
		}

		// Add timing summary at the end if there are any completed items or a current item
		$timingSummary = $this->generateTimingSummary($items, $currentItem, $lang);
		if (!empty($timingSummary)) {
			$status .= "\n" . $timingSummary;
		}

		return $status;
	}

	/**
	 * Generate timing summary for agenda status overview
	 */
	private function generateTimingSummary(array $items, ?LogEntry $currentItem, string $lang): string {
		if (empty($items)) {
			return '';
		}
		
		// Calculate total planned time
		$totalPlannedMinutes = array_sum(array_column($items, 'duration'));
		
		// Calculate actual time using utility service
		$timingData = $this->timingUtilityService->calculateActualTimeSpent($items, $currentItem);
		
		// Generate timing summary string (single-line for compact status display)
		return $this->timingUtilityService->generateTimingSummaryString(
			$totalPlannedMinutes,
			$timingData['total_actual_minutes'],
			$timingData['has_actual_time'],
			$lang,
			"---\n*",
			"*",
			false, // no bold formatting
			false  // single-line format for agenda status
		);
	}

	/**
	 * Get the current active agenda item
	 */
	public function getCurrentAgendaItem(string $token): ?LogEntry {
		return $this->logEntryMapper->findCurrentAgendaItem($token);
	}

	/**
	 * Set the current agenda item (requires moderator permissions)
	 * If position is null, behaves like done: - completes current item and moves to next
	 * If position is specified, sets that item as current
	 */
	public function setCurrentAgendaItem(string $token, ?int $position = null, ?array $actorData = null, string $lang = 'en'): ?string {
		// Check moderator permissions if actor data is provided
		if ($actorData !== null && !$this->permissionService->isActorModerator($token, $actorData)) {
			$l = $this->l10nFactory->get(Application::APP_ID, $lang);
			return $this->permissionService->getPermissionDeniedMessage($l->t('set the current agenda item'), $lang);
		}
		
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// If no position specified, behave like done: - complete current and move to next
		if ($position === null) {
			return $this->completeItem($token, null, $actorData, $lang);
		}
		
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

	$plannedDisplay = $this->timingUtilityService->formatDurationDisplay($item->getDurationMinutes(), $lang);
	return '🗣️ ' . $l->t('Set agenda item %d as current:', [$position]) . "\n`" . $item->getDetails() . "`\n*" . $l->t('Planned duration: %s', [$plannedDisplay]) . "*";
	}

	/**
	 * Clear all current agenda items for a conversation (when call ends)
	 */
	public function clearAllCurrentItems(string $token): void {
		$items = $this->logEntryMapper->findAgendaItems($token);
		foreach ($items as $item) {
			if ($item->getStartTime() !== null && !$item->getIsCompleted()) {
				$item->setStartTime(null);
				$this->logEntryMapper->update($item);
			}
		}
	}

	/**
	 * Clear all current agenda items for a conversation (private helper)
	 */
	private function clearCurrentAgendaItems(string $token): void {
		$this->clearAllCurrentItems($token);
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
	 * Complete an agenda item - handles both current item completion and specific item completion
	 * If position is null, completes current item and moves to next
	 * If position is specified and it's the current item, completes it and moves to next
	 * If position is specified and it's not current, just marks it as completed
	 */
	public function completeItem(string $token, ?int $position = null, ?array $actorData = null, string $lang = 'en'): ?string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Check moderator permissions if actor data is provided
		if ($actorData !== null && !$this->permissionService->isActorModerator($token, $actorData)) {
			return $this->permissionService->getPermissionDeniedMessage($l->t('complete agenda items'), $lang);
		}
		
		$currentItem = $this->getCurrentAgendaItem($token);
		$itemToComplete = null;
		$isCurrentItem = false;
		
		// Determine which item to complete
		if ($position === null) {
			// No position specified - complete current item
			if (!$currentItem) {
				return '❌ ' . $l->t('No current agenda item is active');
			}
			$itemToComplete = $currentItem;
			$isCurrentItem = true;
		} else {
			// Position specified - find that item
			$itemToComplete = $this->logEntryMapper->findAgendaItemByPosition($token, $position);
			if (!$itemToComplete) {
				return '❌ ' . $l->t('Agenda item %d not found', [$position]);
			}
			// Check if the specified item is the current item
			$isCurrentItem = $currentItem && $currentItem->getOrderPosition() === $position;
		}
		
		if ($itemToComplete->getIsCompleted()) {
			if ($position === null) {
				return 'ℹ️ ' . $l->t('Current agenda item %d is already completed: "%s"', [$itemToComplete->getOrderPosition(), $itemToComplete->getDetails()]);
			} else {
				return 'ℹ️ ' . $l->t('Agenda item %d is already completed: "%s"', [$position, $itemToComplete->getDetails()]);
			}
		}
		
		// Mark item as completed
		$itemToComplete->setIsCompleted(true);
		$itemToComplete->setCompletedAt($this->timeFactory->now()->getTimestamp());
		$this->logEntryMapper->update($itemToComplete);
		
		// Build response based on whether this was the current item
		if ($isCurrentItem) {
			// Calculate timing details for current item completion
			$actualTime = $this->timingUtilityService->getTimeSpentOnItem($itemToComplete);
			$plannedTime = $itemToComplete->getDurationMinutes();
			$actualDisplay = $this->timingUtilityService->formatDurationDisplay($actualTime, $lang);
			$plannedDisplay = $this->timingUtilityService->formatDurationDisplay($plannedTime, $lang);
			
			$response = "✅ " . $l->t('Completed agenda item %d: **"%s"** (%s/%s)', [
				$itemToComplete->getOrderPosition(), 
				$itemToComplete->getDetails(), 
				$actualDisplay, 
				$plannedDisplay
			]);
			
			// Move to next incomplete item since we completed the current one
			$nextItem = $this->moveToNextIncompleteItem($token);
			if ($nextItem) {
				$nextPlannedDisplay = $this->timingUtilityService->formatDurationDisplay($nextItem->getDurationMinutes(), $lang);
				$response .= "\n🗣️ " . $l->t('Moving to next item %d:', [$nextItem->getOrderPosition()]);
				$response .= "\n`" . $nextItem->getDetails() . "`";
				$response .= "\n*" . $l->t('Planned duration: %s', [$nextPlannedDisplay]) . "*";
			} else {
				$response .= "\n\n### 🏁 " . $l->t('All agenda items completed!');
			}
		} else {
			// Just a regular item completion, not the current one
			$response = '✅ ' . $l->t('Marked agenda item %d as completed: "%s"', [$itemToComplete->getOrderPosition(), $itemToComplete->getDetails()]);
		}
		
		return $response;
	}

	/**
	 * Modify existing agenda item title and/or duration (requires moderator permissions or item creator)
	 */
	public function modifyAgendaItem(string $token, int $position, ?string $newTitle, ?string $newDuration, ?array $actorData = null, string $lang = 'en'): ?string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Check if both parameters are null or empty
		if (($newTitle === null || trim($newTitle) === '') && ($newDuration === null || trim($newDuration) === '')) {
			return '❌ ' . $l->t('Please specify new title, duration, or both');
		}
		
		// Find the agenda item
		$item = $this->logEntryMapper->findAgendaItemByPosition($token, $position);
		if (!$item) {
			return '❌ ' . $l->t('Agenda item %d not found', [$position]);
		}
		
		// Cannot modify completed items
		if ($item->getIsCompleted()) {
			return '❌ ' . $l->t('Cannot modify completed item %d. Use "reopen: %d" first', [$position, $position]);
		}
		
		// Check permissions - moderators/owners can modify any item
		if ($actorData !== null && !$this->permissionService->isActorModerator($token, $actorData)) {
			// TODO: In future enhancement, add logic to check if user created this item
			// For now, only moderators/owners can modify items
			return $this->permissionService->getPermissionDeniedMessage($l->t('modify agenda items'), $lang);
		}
		
		$changes = [];
		$originalTitle = $item->getDetails();
		$originalDuration = $item->getDurationMinutes();
		
		// Update title if provided
		if ($newTitle !== null && trim($newTitle) !== '') {
			$cleanTitle = trim($newTitle);
			if ($cleanTitle !== $originalTitle) {
				$item->setDetails($cleanTitle);
				$changes[] = $l->t('title updated');
			}
		}
		
		// Update duration if provided
		if ($newDuration !== null && trim($newDuration) !== '') {
			$cleanDuration = trim($newDuration);
			$durationMinutes = $this->timingUtilityService->parseDurationToMinutes($cleanDuration);
			
			if ($durationMinutes <= 0) {
				return '❌ ' . $l->t('Invalid duration format. Use (5 min), (1h), etc.');
			}
			
			if ($durationMinutes !== $originalDuration) {
				$item->setDurationMinutes($durationMinutes);
				
				// Reset time warnings when duration changes
				$item->setWarningSent(false);
				
				$changes[] = $l->t('duration updated');
			}
		}
		
		// If no actual changes were made
		if (empty($changes)) {
			return 'ℹ️ ' . $l->t('No changes made to agenda item %d', [$position]);
		}
		
		// Save changes
		try {
			$this->logEntryMapper->update($item);
			
			// Build response message
			$response = '✏️ ' . $l->t('Modified agenda item %d: "%s"', [$position, $item->getDetails()]);
			$response .= ' (' . $this->timingUtilityService->formatDurationDisplay($item->getDurationMinutes(), $lang) . ')';
			$response .= ' - ' . implode(', ', $changes);
			
			return $response;
			
		} catch (\Exception $e) {
			$this->logger->error('Failed to modify agenda item', [
				'token' => $token,
				'position' => $position,
				'error' => $e->getMessage()
			]);
			return '❌ ' . $l->t('Failed to modify agenda item %d', [$position]);
		}
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
	 * Get time monitoring configuration (room-aware)
	 */
	public function getTimeMonitoringConfig(string $token = null): array {
		if ($token !== null) {
			return $this->roomConfigService->getRoomTimeMonitoringConfig($token);
		}
		
		// Fallback to global config when no token provided (backward compatibility)
		return [
			'enabled' => $this->config->getAppValue('agenda_bot', 'time-monitoring-enabled', 'true') === 'true',
			'warning_threshold' => (float)$this->config->getAppValue('agenda_bot', 'warning-threshold', '0.8'),
			'overtime_threshold' => (float)$this->config->getAppValue('agenda_bot', 'overtime-warning-threshold', '1.2'),
			'check_interval' => RoomConfigService::FIXED_CHECK_INTERVAL,
			'source' => 'global',
		];
	}

	/**
	 * Set time monitoring configuration (requires moderator permissions) - now room-aware
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
		
		// Get user ID from actor data for audit trail
		$userId = 'unknown';
		if ($actorData !== null && is_array($actorData)) {
			// Try different possible keys for user identification
			$rawUserId = $actorData['id'] ?? $actorData['actorId'] ?? $actorData['name'] ?? 'unknown';
			
			// Clean up user ID by removing prefixes like 'users/' or 'guests/'
			if ($rawUserId !== 'unknown' && is_string($rawUserId)) {
				$userId = $this->cleanUserId($rawUserId);
			}
		}
		
		// Get current configuration to preserve existing values
		$currentConfig = $this->getTimeMonitoringConfig($token);
		
		// Prepare room configuration by merging new values with current ones
		$roomConfig = [
			'enabled' => $currentConfig['enabled'],
			'warning_threshold' => $currentConfig['warning_threshold'],
			'overtime_threshold' => $currentConfig['overtime_threshold']
		];
		
		if (isset($config['enabled'])) {
			$roomConfig['enabled'] = (bool)$config['enabled'];
			$changes[] = $l->t('enabled status', [$config['enabled'] ? $l->t('enabled') : $l->t('disabled')]);
		}
		
		if (isset($config['warning_threshold'])) {
			$threshold = max(0.1, min(0.95, (float)$config['warning_threshold']));
			$roomConfig['warning_threshold'] = $threshold;
			$changes[] = $l->t('Time limit warning at %d%%', [round($threshold * 100)]);
		}
		
		if (isset($config['overtime_threshold'])) {
			$threshold = max(1.05, min(3.0, (float)$config['overtime_threshold']));
			$roomConfig['overtime_threshold'] = $threshold;
			$changes[] = $l->t('Overtime warning at %d%%', [round($threshold * 100)]);
		}
		
		if (empty($changes)) {
			$result['success'] = false;
			$result['message'] = '❌ ' . $l->t('No valid configuration changes provided');
		} else {
			// Save room-level configuration
			$this->roomConfigService->setRoomTimeMonitoringConfig($token, $roomConfig, $userId);
			$result['message'] = '✅ ' . $l->t('Updated room time monitoring: %s', [implode(', ', $changes)]);
		}
		
		return $result;
	}

	/**
	 * Get formatted time monitoring status (room-aware)
	 */
	public function getTimeMonitoringStatus(string $token, string $lang = 'en'): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		$config = $this->getTimeMonitoringConfig($token);
		$metadata = $this->roomConfigService->getRoomConfigMetadata($token);
		
		$configType = $config['source'] ?? 'room';
		$title = $configType === 'room' ? 'Room Time Monitoring' : 'Time Monitoring (Global Default)';
		
		$status = "### ⏰ **" . $l->t($title) . ":**\n\n";
		
		if (!$config['enabled']) {
			$status .= "❌ **" . $l->t("Disabled") . "** - " . $l->t("No time warnings will be sent") . "\n\n";
		} else {
			$status .= "✅ **" . $l->t("Enabled") . "** - " . $l->t("Active monitoring with the following thresholds") . ":\n\n";
			$status .= "• **" . $l->t("Time Limit Warning") . "**: " . 
				$l->t("%.0f%% of planned time", [$config['warning_threshold'] * 100]) . "\n";
			$status .= "• **" . $l->t("Time Limit Reached") . "**: " . 
				$l->t("%.0f%% of planned time", [100]) . " (" . $l->t("fixed") . ")\n";
			$status .= "• **" . $l->t("Overtime Alert") . "**: " . 
				$l->t("%.0f%% of planned time", [$config['overtime_threshold'] * 100]) . "\n";
			$minutes = (int) round($config['check_interval'] / 60);
			$status .= "• **" . $l->t("Check Interval") . "**: " . $l->t("%d minutes (%s)", [$minutes, $l->t("fixed")]) . "\n\n";
		}
		
		// Add configuration metadata for room configs
		if ($configType === 'room' && $metadata) {
			$configuredAt = $metadata['configured_at'] ? date('M j, Y', $metadata['configured_at']) : 'unknown';
			$configuredByUserId = $metadata['configured_by'] ?? 'unknown';
			$configuredByDisplay = $this->formatUserForDisplay($configuredByUserId);
			$status .= "📝 *" . $l->t('Configured by: %s on %s', [$configuredByDisplay, $configuredAt]) . "*\n\n";
		}
		
		$status .= "**" . $l->t("Configuration Commands") . ":**\n";
		$status .= "• `time config` - " . $l->t("Show room time monitoring configuration") . "\n";
		$status .= "• `time enable` / `time disable` - " . $l->t("Enable/disable monitoring for this room") . "\n";
		$status .= "• `time warning 85` - " . $l->t("Set time limit warning threshold") . " (" . $l->t("percentages") . ")\n";
		$status .= "• `time overtime 110` - " . $l->t("Set overtime alert threshold") . " (" . $l->t("percentages") . ")\n";
		$status .= "• `time thresholds 75 120` - " . $l->t("Set both warning and overtime thresholds") . "\n";
		$status .= "• `time reset` - " . $l->t("Reset to global defaults") . "\n\n";
		
		if ($configType === 'global') {
			$status .= "💡 *" . $l->t('This room is using global defaults. Use time commands to set room-specific configuration.') . "*\n";
		}
		
		$status .= "ℹ️ *" . $l->t('Time checks run every 5 minutes with background jobs') . "*\n";
		$status .= "🔒 *" . $l->t('Moderators/Owners can configure room-specific time monitoring') . "*\n";
		
		return $status;
	}

	/**
	 * Clean user ID by removing common prefixes like 'users/', 'guests/', etc.
	 */
	private function cleanUserId(string $rawUserId): string {
		// Remove common prefixes that might be present in actor data
		if (str_starts_with($rawUserId, 'users/')) {
			return substr($rawUserId, 6); // Remove 'users/' prefix
		}
		if (str_starts_with($rawUserId, 'guests/')) {
			return substr($rawUserId, 7); // Remove 'guests/' prefix
		}
		if (str_starts_with($rawUserId, 'emails/')) {
			return substr($rawUserId, 7); // Remove 'emails/' prefix
		}
		if (str_starts_with($rawUserId, 'federated_users/')) {
			return substr($rawUserId, 16); // Remove 'federated_users/' prefix
		}
		
		return $rawUserId; // Return as-is if no known prefix
	}
	
	/**
	 * Format user ID for display as a mention or display name
	 */
	private function formatUserForDisplay(string $userId): string {
		if ($userId === 'unknown' || empty($userId)) {
			return 'unknown';
		}
		
		// Clean the user ID in case it still has prefixes
		$cleanUserId = $this->cleanUserId($userId);
		
		$user = $this->userManager->get($cleanUserId);
		if ($user === null) {
			// User might have been deleted, return formatted mention with original ID
			return '**@' . $cleanUserId . '**';
		}
		
		// Get display name
		$displayName = $user->getDisplayName();
		if ($displayName && $displayName !== $cleanUserId) {
			// Return mention format with display name
			return '**@' . $displayName . '**';
		}
		
		// Fallback to mention format with user ID
		return '**@' . $cleanUserId . '**';
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
				"• `agenda status` / `agenda list` - " . $l->t('Show current agenda status and items') . "\n" .
				"• `agenda help` - " . $l->t('Show this help message') . "\n\n";
		
		// Add item commands for users who can add (types 1,2,3,6)
		if ($canAddItems) {
			$help .= "**" . $l->t('Adding Items:') . "**\n" .
					 "• `agenda: Topic name (15 min)` - " . $l->t('Add agenda item with time') . "\n" .
					 "• `topic: Meeting topic (1h)` - " . $l->t('Alternative syntax') . "\n" .
					 "• `add: Another topic` - " . $l->t('Add item (10 min default)') . "\n\n" .
					 "**" . $l->t('Bulk Agenda Creation:') . "**\n" .
					 "`agenda:`\n" .
					 "`- Item 1 (15m)`\n" .
					 "`- Item 2 (30m)`\n" .
					 "`- Item 3`\n" .
					 "*" . $l->t('Create multiple agenda items at once by using:') . "* `agenda:` + *" . $l->t('Multiple agenda items using bullet points') . "*\n" .
					 "*" . $l->t('Maximum %d items per bulk operation', [self::MAX_BULK_ITEMS]) . "*\n" .
					 "**" . $l->t('Time Formats:') . "** `(5 m)`, `(10 min)`, `(1h)`, `(2 hours)`, `(90 min)`\n\n";
		}
		
		// Time monitoring - available to all users for viewing
		$help .= "**" . $l->t('Time Monitoring:') . "**\n" .
				 "• `time config` - " . $l->t('Show time monitoring configuration') . "\n";
		
		// Full moderator commands for types 1,2,6 (Owner, Moderator, Guest with moderator permissions)
		if ($isModerator) {
			$help .= "\n**" . $l->t('Moderator Commands:') . "**\n" .
					 "• `done:` - " . $l->t('Complete current item and move to next') . " 🔒\n" .
					 "• `next: 2` - " . $l->t('Set agenda item %d as current', [2]) . " 🔒\n" .
					 "• `complete: 2` / `done: 3` / `close: 1` - " . $l->t('Mark item as completed') . " 🔒\n" .
					 "• `incomplete: 3` / `undone: 1` / `reopen: 2` - " . $l->t('Reopen completed item') . " 🔒\n" .
					 "• `change: 2 New Title (30 min)` - " . $l->t('Modify agenda item title/duration') . " 🔒\n" .
					 "• `reorder: 2,1,3` - " . $l->t('Reorder agenda items') . " 🔒\n" .
					 "• `move: 3 to 1` - " . $l->t('Move item %d to position %d', [3, 1]) . " 🔒\n" .
					 "• `swap: 1,3` - " . $l->t('Swap agenda items %d and %d', [1, 3]) . " 🔒\n" .
					 "• `remove: 2` / `delete: 2` - " . $l->t('Remove agenda item %d', [2]) . " 🔒\n" .
					 "• `cleanup` / `agenda cleanup` - " . $l->t('Remove completed items') . " 🔒\n" .					 
					 "• `agenda clear` - " . $l->t('Clear all agenda items') . " 🔒\n\n" .
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
		// Create a mapping from old position to new position
		$positionMapping = [];
		for ($i = 0; $i < count($positions); $i++) {
			$oldPosition = $i + 1; // Positions are 1-based
			$newPosition = array_search($oldPosition, $positions) + 1; // Find where old position appears in new order
			$positionMapping[$oldPosition] = $newPosition;
		}
		
		$updates = [];
		foreach ($items as $item) {
			$currentPosition = $item->getOrderPosition();
			$newPosition = $positionMapping[$currentPosition];
			if ($currentPosition !== $newPosition) {
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
			$actualDuration = 0;
			if (!empty($item['start_time']) && !empty($item['completed_at'])) {
				$actualDuration = $this->timingUtilityService->calculateActualDurationFromTimestamps($item['start_time'], $item['completed_at']);
			}
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
