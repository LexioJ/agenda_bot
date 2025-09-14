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
	// Unified config command patterns
	public const CONFIG_SHOW_PATTERN = '/^config\s+show$/i';
	public const CONFIG_TIME_PATTERN = '/^config\s+time$/i';
	public const CONFIG_TIME_ENABLE_PATTERN = '/^config\s+time\s+(enable|disable)$/i';
	public const CONFIG_TIME_WARNING_PATTERN = '/^config\s+time\s+warning\s+(\d+)$/i';
	public const CONFIG_TIME_OVERTIME_PATTERN = '/^config\s+time\s+overtime\s+(\d+)$/i';
	public const CONFIG_TIME_THRESHOLDS_PATTERN = '/^config\s+time\s+thresholds\s+(\d+)\s+(\d+)$/i';
	public const CONFIG_TIME_RESET_PATTERN = '/^config\s+time\s+reset$/i';
	public const CONFIG_RESPONSE_PATTERN = '/^config\s+response(?:\s+(show|normal|minimal|reset))?$/i';
	public const CONFIG_RESET_PATTERN = '/^config\s+reset(?:\s+(time|response))?$/i';
	
	// Config limits patterns
	public const CONFIG_LIMITS_PATTERN = '/^config\s+limits$/i';
	public const CONFIG_LIMITS_MAX_ITEMS_PATTERN = '/^config\s+limits\s+max-items\s+(\d+)$/i';
	public const CONFIG_LIMITS_MAX_BULK_PATTERN = '/^config\s+limits\s+max-bulk\s+(\d+)$/i';
	public const CONFIG_LIMITS_DEFAULT_DURATION_PATTERN = '/^config\s+limits\s+default-duration\s+(\d+)$/i';
	public const CONFIG_LIMITS_RESET_PATTERN = '/^config\s+limits\s+reset$/i';
	
	// Config auto-behaviors patterns
	public const CONFIG_AUTO_PATTERN = '/^config\s+auto$/i';
	public const CONFIG_AUTO_START_PATTERN = '/^config\s+auto\s+start-agenda\s+(enable|disable)$/i';
	public const CONFIG_AUTO_CLEANUP_PATTERN = '/^config\s+auto\s+cleanup\s+(enable|disable)$/i';
	public const CONFIG_AUTO_SUMMARY_PATTERN = '/^config\s+auto\s+summary\s+(enable|disable)$/i';
	public const CONFIG_AUTO_RESET_PATTERN = '/^config\s+auto\s+reset$/i';
	
	// Config emojis patterns
	public const CONFIG_EMOJIS_PATTERN = '/^config\s+emojis$/i';
	public const CONFIG_EMOJIS_SET_PATTERN = '/^config\s+emojis\s+(current-item|completed|pending|on-time|time-warning)\s+(.+)$/i';
	public const CONFIG_EMOJIS_RESET_PATTERN = '/^config\s+emojis\s+reset$/i';
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

		// Unified config commands (prioritized over legacy commands)
		// Config show - display all room configuration
		if (preg_match(self::CONFIG_SHOW_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_show',
				'token' => $token
			];
		}

		// Config time - unified time monitoring configuration
		if (preg_match(self::CONFIG_TIME_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_time',
				'token' => $token,
				'action' => 'show'
			];
		}

		// Config time enable/disable command
		if (preg_match(self::CONFIG_TIME_ENABLE_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_time',
				'token' => $token,
				'action' => strtolower($matches[1])
			];
		}

		// Config time warning threshold command
		if (preg_match(self::CONFIG_TIME_WARNING_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_time',
				'token' => $token,
				'action' => 'warning',
				'param1' => (int)$matches[1]
			];
		}

		// Config time overtime threshold command
		if (preg_match(self::CONFIG_TIME_OVERTIME_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_time',
				'token' => $token,
				'action' => 'overtime',
				'param1' => (int)$matches[1]
			];
		}

		// Config time thresholds command (both warning and overtime)
		if (preg_match(self::CONFIG_TIME_THRESHOLDS_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_time',
				'token' => $token,
				'action' => 'thresholds',
				'param1' => (int)$matches[1],
				'param2' => (int)$matches[2]
			];
		}

		// Config time reset command
		if (preg_match(self::CONFIG_TIME_RESET_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_time',
				'token' => $token,
				'action' => 'reset'
			];
		}

		// Config response - response behavior configuration
		if (preg_match(self::CONFIG_RESPONSE_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_response',
				'token' => $token,
				'action' => isset($matches[1]) && $matches[1] !== '' ? strtolower($matches[1]) : 'show'
			];
		}

		// Config reset - reset configuration sections
		if (preg_match(self::CONFIG_RESET_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_reset',
				'token' => $token,
				'section' => isset($matches[1]) && $matches[1] !== '' ? strtolower($matches[1]) : null
			];
		}
		
		// Config limits commands
		if (preg_match(self::CONFIG_LIMITS_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_limits',
				'token' => $token,
				'action' => 'show'
			];
		}
		
		if (preg_match(self::CONFIG_LIMITS_MAX_ITEMS_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_limits',
				'token' => $token,
				'action' => 'max-items',
				'param1' => (int)$matches[1]
			];
		}
		
		if (preg_match(self::CONFIG_LIMITS_MAX_BULK_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_limits',
				'token' => $token,
				'action' => 'max-bulk',
				'param1' => (int)$matches[1]
			];
		}
		
		if (preg_match(self::CONFIG_LIMITS_DEFAULT_DURATION_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_limits',
				'token' => $token,
				'action' => 'default-duration',
				'param1' => (int)$matches[1]
			];
		}
		
		if (preg_match(self::CONFIG_LIMITS_RESET_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_limits',
				'token' => $token,
				'action' => 'reset'
			];
		}
		
		// Config auto-behaviors commands
		if (preg_match(self::CONFIG_AUTO_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_auto',
				'token' => $token,
				'action' => 'show'
			];
		}
		
		if (preg_match(self::CONFIG_AUTO_START_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_auto',
				'token' => $token,
				'action' => 'start-agenda',
				'param1' => strtolower($matches[1]) === 'enable'
			];
		}
		
		if (preg_match(self::CONFIG_AUTO_CLEANUP_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_auto',
				'token' => $token,
				'action' => 'cleanup',
				'param1' => strtolower($matches[1]) === 'enable'
			];
		}
		
		if (preg_match(self::CONFIG_AUTO_SUMMARY_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_auto',
				'token' => $token,
				'action' => 'summary',
				'param1' => strtolower($matches[1]) === 'enable'
			];
		}
		
		if (preg_match(self::CONFIG_AUTO_RESET_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_auto',
				'token' => $token,
				'action' => 'reset'
			];
		}
		
		// Config emojis commands
		if (preg_match(self::CONFIG_EMOJIS_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_emojis',
				'token' => $token,
				'action' => 'show'
			];
		}
		
		if (preg_match(self::CONFIG_EMOJIS_SET_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_emojis',
				'token' => $token,
				'action' => 'set',
				'param1' => strtolower($matches[1]), // emoji type
				'param2' => trim($matches[2]) // emoji value
			];
		}
		
		if (preg_match(self::CONFIG_EMOJIS_RESET_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_emojis',
				'token' => $token,
				'action' => 'reset'
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
