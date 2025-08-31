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
	public const COMPLETE_PATTERN = '/^(complete|done|close)\s*:\s*(\d+)$/i';
	public const COMPLETE_CURRENT_PATTERN = '/^(complete|done|close)\s*:?\s*$/i';
	public const REOPEN_PATTERN = '/^(incomplete|undone|reopen)\s*:\s*(\d+)$/i';
	public const NEXT_PATTERN = '/^next\s*:\s*(\d+)$/i';
	public const REORDER_PATTERN = '/^reorder\s*:\s*((?:\d+,?\s*)+)$/i';
	public const MOVE_PATTERN = '/^move\s*:\s*(\d+)\s+to\s+(\d+)$/i';
	public const SWAP_PATTERN = '/^swap\s*:\s*(\d+),\s*(\d+)$/i';
	public const REMOVE_PATTERN = '/^(remove|delete)\s*:\s*(\d+)$/i';
	public const TIME_CONFIG_PATTERN = '/^time\s+(config|status)$/i';
	public const TIME_ENABLE_PATTERN = '/^time\s+(enable|disable)$/i';
	public const TIME_THRESHOLDS_PATTERN = '/^time\s+thresholds\s+(\d+)\s+(\d+)\s+(\d+)$/i';
	public const TIME_DEBUG_PATTERN = '/^time\s+(debug|test)$/i';
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

		// Complete command with specific item number
		if (preg_match(self::COMPLETE_PATTERN, $message, $matches)) {
			return [
				'command' => 'complete',
				'token' => $token,
				'action' => strtolower($matches[1]),
				'item' => (int)$matches[2]
			];
		}

		// Complete current item command (without number)
		if (preg_match(self::COMPLETE_CURRENT_PATTERN, $message, $matches)) {
			return [
				'command' => 'complete_current',
				'token' => $token,
				'action' => strtolower($matches[1])
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
				'item' => (int)$matches[1]
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

		// Time thresholds command
		if (preg_match(self::TIME_THRESHOLDS_PATTERN, $message, $matches)) {
			return [
				'command' => 'time_thresholds',
				'token' => $token,
				'threshold_80' => (int)$matches[1],
				'threshold_100' => (int)$matches[2],
				'threshold_overtime' => (int)$matches[3]
			];
		}


		// Time debug command
		if (preg_match(self::TIME_DEBUG_PATTERN, $message, $matches)) {
			return [
				'command' => 'time_debug',
				'token' => $token,
				'action' => strtolower($matches[1])
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

	/**
	 * Get command help text
	 */
	public function getCommandHelp(): string {
		return "### 📋 **Agenda Commands:**\n\n" .
			   "**Adding Items:**\n" .
			   "• `agenda: Topic name (15 min)` - Add agenda item with time\n" .
			   "• `topic: Meeting topic (1h)` - Alternative syntax\n" .
			   "• `item: General discussion` - Add item (10 min default)\n" .
			   "• `insert: New topic (30 min)` - Insert agenda item\n" .
			   "• `add: Another topic (2 hours)` - Add agenda item\n" .
			   "\n**Time Formats:** `(5 m)`, `(10 min)`, `(1h)`, `(2 hours)`, `(90 min)`\n\n" .
			   "**Status & Management:**\n" .
			   "• `agenda status` - Show current agenda status\n" .
			   "• `agenda list` - Show agenda items\n" .
			   "• `agenda clear` - Clear all agenda items\n" .
			   "• `next: 2` - Set agenda item 2 as current\n\n" .
			   "**Complete/Reopen Items:**\n" .
			   "• `complete: 1` / `done: 1` / `close: 1` - Mark item as completed\n" .
			   "• `incomplete: 1` / `undone: 1` / `reopen: 1` - Reopen completed item\n\n" .
			   "**Reorder & Management:**\n" .
			   "• `reorder: 2,1,4,3` - Reorder agenda items\n" .
			   "• `move: 3 to 1` - Move item 3 to position 1\n" .
			   "• `swap: 1,3` - Swap agenda items 1 and 3\n" .
			   "• `remove: 2` / `delete: 2` - Remove agenda item 2\n\n" .
			   "**Get Help:**\n" .
			   "• `agenda help` - Show this help message";
	}
}
