<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Service;

use OCA\AgendaBot\AppInfo\Application;
use OCA\AgendaBot\Model\LogEntry;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\L10N\IFactory;

/**
 * Utility service for timing calculations and formatting
 * Centralizes common timing logic used across AgendaService and SummaryService
 */
class TimingUtilityService {
	
	public function __construct(
		private ITimeFactory $timeFactory,
		private IFactory $l10nFactory,
	) {
	}

	/**
	 * Format duration in minutes to a human-readable string
	 * Returns "x h y min" for durations >= 60 minutes, "x min" otherwise
	 */
	public function formatDurationDisplay(int $minutes, string $lang = 'en'): string {
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
	 * Calculate total actual time spent from agenda items and current item
	 */
	public function calculateActualTimeSpent(array $items, ?LogEntry $currentItem = null): array {
		$totalActualMinutes = 0;
		$hasActualTime = false;
		
		// Add completed items' actual time
		foreach ($items as $item) {
			if ($item['completed'] && !empty($item['start_time']) && !empty($item['completed_at'])) {
				$actualDuration = $this->calculateActualDurationFromTimestamps($item['start_time'], $item['completed_at']);
				$totalActualMinutes += $actualDuration;
				$hasActualTime = true;
			}
		}
		
		// Add current item's time if any
		if ($currentItem) {
			$currentTimeSpent = $this->getTimeSpentOnItem($currentItem);
			if ($currentTimeSpent > 0) {
				$totalActualMinutes += $currentTimeSpent;
				$hasActualTime = true;
			}
		}
		
		return [
			'total_actual_minutes' => $totalActualMinutes,
			'has_actual_time' => $hasActualTime
		];
	}

	/**
	 * Calculate total actual time from completed items with timing data (for meeting summaries)
	 */
	public function calculateCompletedActualTime(array $completedItemsWithTiming): array {
		$totalActualMinutes = 0;
		$hasActualTime = false;
		
		foreach ($completedItemsWithTiming as $item) {
			if (isset($item['actual_duration']) && $item['actual_duration'] > 0) {
				$totalActualMinutes += $item['actual_duration'];
				$hasActualTime = true;
			}
		}
		
		return [
			'total_actual_minutes' => $totalActualMinutes,
			'has_actual_time' => $hasActualTime
		];
	}

	/**
	 * Generate timing summary string for display
	 * @param bool $multiLine If true, formats as two lines for summaries; if false, one line for status/agenda views
	 */
	public function generateTimingSummaryString(
		int $totalPlannedMinutes,
		int $totalActualMinutes,
		bool $hasActualTime,
		string $lang,
		string $prefix = '',
		string $suffix = '',
		bool $bold = false,
		bool $multiLine = true
	): string {
		// Only return empty if there's no planned time at all
		if ($totalPlannedMinutes <= 0) {
			return '';
		}
		
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		$totalPlannedDisplay = $this->formatDurationDisplay($totalPlannedMinutes, $lang);
		
		$formatStart = $bold ? '**' : '';
		$formatEnd = $bold ? '**' : '';
		
		if ($multiLine) {
			// Multi-line format for summaries (more readable)
			$summary = $prefix . '📅 ' . $formatStart . $l->t('Total planned time:') . $formatEnd . ' ' . $totalPlannedDisplay;
			
			// Add actual time if we have any - second line
			if ($hasActualTime && $totalActualMinutes > 0) {
				$totalActualDisplay = $this->formatDurationDisplay($totalActualMinutes, $lang);
				$summary .= "\n" . '🕐 ' . $formatStart . $l->t('Time spent:') . $formatEnd . ' ' . $totalActualDisplay;
				
				// Add timing indicator if we have a reasonable comparison
				if ($totalPlannedMinutes > 0) {
					$percentage = round(($totalActualMinutes / $totalPlannedMinutes) * 100);
					if ($percentage <= 100) {
						$summary .= ' (👍 ' . $percentage . '%)';
					} else {
						$summary .= ' (⏰ ' . $percentage . '%)';
					}
				}
			}
		} else {
			// Single-line format for status/agenda views (more compact)
			$summary = $prefix . $formatStart . '📅 ' . $l->t('Total planned time: %s', [$totalPlannedDisplay]);
			
			// Add actual time if we have any - same line
			if ($hasActualTime && $totalActualMinutes > 0) {
				$totalActualDisplay = $this->formatDurationDisplay($totalActualMinutes, $lang);
				$summary .= ' | 🕐 ' . $l->t('Time spent: %s', [$totalActualDisplay]);
				
				// Add timing indicator if we have a reasonable comparison
				if ($totalPlannedMinutes > 0) {
					$percentage = round(($totalActualMinutes / $totalPlannedMinutes) * 100);
					if ($percentage <= 100) {
						$summary .= ' (👍 ' . $percentage . '%)';
					} else {
						$summary .= ' (⏰ ' . $percentage . '%)';
					}
				}
			}
			
			$summary .= $formatEnd;
		}
		
		$summary .= $suffix;
		
		return $summary;
	}

	/**
	 * Parse duration string to minutes
	 * Supports formats like: (5 min), (1h), (1h 30min), (90min), etc.
	 * Moved from AgendaService to centralize duration parsing logic
	 */
	public function parseDurationToMinutes(string $duration): int {
		// Remove parentheses and clean up
		$duration = trim($duration, '() ');
		if (empty($duration)) {
			return 10; // Default 10 minutes for empty duration (agenda item default)
		}
		
		// Convert to lowercase for easier matching
		$duration = strtolower($duration);
		
		$totalMinutes = 0;
		
		// Check for hours and minutes pattern: "1h 30min" or "1h 30m"
		if (preg_match('/(\d+)\s*h(?:our)?s?\s*(\d+)\s*m(?:in)?(?:ute)?s?/', $duration, $matches)) {
			$hours = (int) $matches[1];
			$minutes = (int) $matches[2];
			$totalMinutes = ($hours * 60) + $minutes;
		}
		// Check for hours only: "2h" or "2 hours"
		elseif (preg_match('/(\d+)\s*h(?:our)?s?$/', $duration, $matches)) {
			$hours = (int) $matches[1];
			$totalMinutes = $hours * 60;
		}
		// Check for minutes only: "30min", "30m", "30 minutes"
		elseif (preg_match('/(\d+)\s*m(?:in)?(?:ute)?s?$/', $duration, $matches)) {
			$totalMinutes = (int) $matches[1];
		}
		// Check for just numbers (assume minutes)
		elseif (preg_match('/^(\d+)$/', $duration, $matches)) {
			$totalMinutes = (int) $matches[1];
		}
		
		// Return parsed value or default if parsing failed
		return $totalMinutes > 0 ? $totalMinutes : 10;
	}

	/**
	 * Get time spent on an agenda item in minutes
	 * Moved from AgendaService to centralize timing calculations
	 */
	public function getTimeSpentOnItem(LogEntry $item): int {
		if ($item->getStartTime() === null) {
			return 0;
		}

		$now = $this->timeFactory->now()->getTimestamp();
		$timeSpent = $now - $item->getStartTime();
		
		// Convert seconds to minutes, round up
		return (int) ceil($timeSpent / 60);
	}

	/**
	 * Calculate actual duration for a completed agenda item in minutes
	 * Centralizes the calculation logic that was duplicated across services
	 */
	public function calculateItemActualDuration(LogEntry $item): int {
		// Check if item is completed and has timing data
		if (!$item->getIsCompleted() || $item->getStartTime() === null || $item->getCompletedAt() === null) {
			return 0;
		}
		
		// Calculate actual time spent from start to completion
		$timeSpent = $item->getCompletedAt() - $item->getStartTime();
		
		// Convert seconds to minutes, round up
		return (int) ceil($timeSpent / 60);
	}

	/**
	 * Calculate actual duration from start and end timestamps (for array data)
	 * Helper method to work with array-based item data
	 */
	public function calculateActualDurationFromTimestamps(?int $startTime, ?int $completedAt): int {
		if ($startTime === null || $completedAt === null || $startTime >= $completedAt) {
			return 0;
		}
		
		$timeSpent = $completedAt - $startTime;
		
		// Convert seconds to minutes, round up
		return (int) ceil($timeSpent / 60);
	}

	/**
	 * Calculate progress ratio for time monitoring
	 * Returns the ratio of elapsed time to planned time (e.g., 0.8 for 80% progress)
	 * Handles edge cases like zero or negative planned time
	 */
	public function calculateProgressRatio(float $elapsedMinutes, int $plannedMinutes): float {
		if ($plannedMinutes <= 0) {
			return 0.0; // Avoid division by zero
		}
		
		return $elapsedMinutes / $plannedMinutes;
	}
}
