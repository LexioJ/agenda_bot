<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Service;

class CommandParser {
	public function __construct() {
	}

	// Command patterns
	public const STATUS_COMMAND_PATTERN = '/^agenda\s*(status|list)$/i';
	public const HELP_COMMAND_PATTERN = '/^agenda\s*help$/i';
	public const CLEAR_COMMAND_PATTERN = '/^agenda\s*clear$/i';
	public const COMPLETE_PATTERN = '/^(complete|done|close)\s*:?\s*(\d+)?$/i';
	public const REOPEN_PATTERN = '/^(incomplete|undone|reopen)\s*:\s*(\d+)$/i';
	public const NEXT_PATTERN = '/^next\s*:?\s*(\d+)?$/i';
	public const REORDER_PATTERN = '/^reorder\s*:\s*((?:\d+,?\s*)+)$/i';
	public const MOVE_PATTERN = '/^move\s*:\s*(\d+)\s+to\s+(\d+)$/i';
	public const SWAP_PATTERN = '/^swap\s*:\s*(\d+),\s*(\d+)$/i';
	public const REMOVE_PATTERN = '/^(remove|delete)\s*:\s*(\d+)$/i';
	public const CHANGE_PATTERN = '/^change\s*:\s*(\d+)\s+(?:(?:"([^"]+)"|([^(]+?))\s*(?:\(([^)]+)\))?|\(([^)]+)\))\s*$/mi';
	// Room-level time monitoring commands
	public const TIME_CONFIG_PATTERN = '/^time\s+(config|status)$/i';
	public const TIME_ENABLE_PATTERN = '/^time\s+(enable|disable)$/i';
	public const TIME_WARNING_PATTERN = '/^time\s+warning\s+(\d+)$/i';
	public const TIME_OVERTIME_PATTERN = '/^time\s+overtime\s+(\d+)$/i';
	public const TIME_THRESHOLDS_PATTERN = '/^time\s+thresholds\s+(\d+)\s+(\d+)$/i'; // Only 2 values now
	public const TIME_RESET_PATTERN = '/^time\s+reset$/i';
	public const CLEANUP_PATTERN = '/^(agenda\s+)?(cleanup|clean)$/i';

	/**
	 * Parse command from message
	 */
	public function parseCommand(string $message, string $token): ?array {
		$message = trim($message);

		// Status commands
		if (preg_match(self::STATUS_COMMAND_PATTERN, $message, $matches)) {
			return [
				'command' => 'status',
				'token' => $token,
				'subcommand' => strtolower($matches[1] ?? 'status')
			];
		}

		// Help command
		if (preg_match(self::HELP_COMMAND_PATTERN, $message)) {
			return [
				'command' => 'help',
				'token' => $token
			];
		}

		// Clear command
		if (preg_match(self::CLEAR_COMMAND_PATTERN, $message)) {
			return [
				'command' => 'clear',
				'token' => $token
			];
		}

		// Complete command - handles both numbered and non-numbered completion
		if (preg_match(self::COMPLETE_PATTERN, $message, $matches)) {
			return [
				'command' => 'complete',
				'token' => $token,
				'action' => strtolower($matches[1]),
				'item' => isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : null
			];
		}

		// Reopen command
		if (preg_match(self::REOPEN_PATTERN, $message, $matches)) {
			return [
				'command' => 'reopen',
				'token' => $token,
				'action' => strtolower($matches[1]),
				'item' => (int)$matches[2]
			];
		}

		// Next command
		if (preg_match(self::NEXT_PATTERN, $message, $matches)) {
			return [
				'command' => 'next',
				'token' => $token,
				'item' => isset($matches[1]) && $matches[1] !== '' ? (int)$matches[1] : null
			];
		}

		// Reorder command
		if (preg_match(self::REORDER_PATTERN, $message, $matches)) {
			$positions = array_map('intval', array_map('trim', explode(',', $matches[1])));
			return [
				'command' => 'reorder',
				'token' => $token,
				'positions' => $positions
			];
		}

		// Move command
		if (preg_match(self::MOVE_PATTERN, $message, $matches)) {
			return [
				'command' => 'move',
				'token' => $token,
				'from' => (int)$matches[1],
				'to' => (int)$matches[2]
			];
		}

		// Swap command
		if (preg_match(self::SWAP_PATTERN, $message, $matches)) {
			return [
				'command' => 'swap',
				'token' => $token,
				'item1' => (int)$matches[1],
				'item2' => (int)$matches[2]
			];
		}

		// Remove command
		if (preg_match(self::REMOVE_PATTERN, $message, $matches)) {
			return [
				'command' => 'remove',
				'token' => $token,
				'action' => strtolower($matches[1]),
				'item' => (int)$matches[2]
			];
		}

		// Change command
		if (preg_match(self::CHANGE_PATTERN, $message, $matches)) {
			// Handle title - can be in matches[2] (quoted) or matches[3] (unquoted)
			$title = isset($matches[2]) && $matches[2] !== '' ? trim($matches[2]) : 
					 (isset($matches[3]) && $matches[3] !== '' ? trim($matches[3]) : null);
			
			// Handle duration - can be in matches[4] (with title) or matches[5] (duration-only)
			$duration = isset($matches[4]) && $matches[4] !== '' ? trim($matches[4]) : 
						(isset($matches[5]) && $matches[5] !== '' ? trim($matches[5]) : null);
			
			return [
				'command' => 'change',
				'token' => $token,
				'item' => (int)$matches[1],
				'new_title' => $title,
				'new_duration' => $duration
			];
		}

		// Time config command
		if (preg_match(self::TIME_CONFIG_PATTERN, $message, $matches)) {
			return [
				'command' => 'time_config',
				'token' => $token,
				'subcommand' => strtolower($matches[1])
			];
		}

		// Time enable/disable command
		if (preg_match(self::TIME_ENABLE_PATTERN, $message, $matches)) {
			return [
				'command' => 'time_enable',
				'token' => $token,
				'action' => strtolower($matches[1])
			];
		}

		// Time warning threshold command
		if (preg_match(self::TIME_WARNING_PATTERN, $message, $matches)) {
			return [
				'command' => 'time_warning',
				'token' => $token,
				'threshold' => (int)$matches[1]
			];
		}

		// Time overtime threshold command
		if (preg_match(self::TIME_OVERTIME_PATTERN, $message, $matches)) {
			return [
				'command' => 'time_overtime',
				'token' => $token,
				'threshold' => (int)$matches[1]
			];
		}

		// Time thresholds command (both warning and overtime)
		if (preg_match(self::TIME_THRESHOLDS_PATTERN, $message, $matches)) {
			return [
				'command' => 'time_thresholds',
				'token' => $token,
				'warning_threshold' => (int)$matches[1],
				'overtime_threshold' => (int)$matches[2]
			];
		}

		// Time reset command
		if (preg_match(self::TIME_RESET_PATTERN, $message, $matches)) {
			return [
				'command' => 'time_reset',
				'token' => $token
			];
		}


		// Cleanup command
		if (preg_match(self::CLEANUP_PATTERN, $message, $matches)) {
			return [
				'command' => 'cleanup',
				'token' => $token,
				'action' => strtolower($matches[2])
			];
		}

		return null;
	}

	/**
	 * Check if message is a command
	 */
	public function isCommand(string $message): bool {
		return $this->parseCommand($message, '') !== null;
	}
}
