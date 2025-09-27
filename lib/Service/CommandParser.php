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
	
	// Config template patterns
	public const CONFIG_TEMPLATE_PATTERN = '/^config\s+template$/i';
	public const CONFIG_TEMPLATE_LIST_PATTERN = '/^config\s+template\s+list$/i';
	public const CONFIG_TEMPLATE_APPLY_PATTERN = '/^config\s+template\s+(formal|jour-fixe|workshop|brainstorm|training|none)$/i';
	
	// Config export pattern
	public const CONFIG_EXPORT_PATTERN = '/^config\s+export$/i';
	
	// Note: Bulk configuration detection now uses isBulkConfigMessage() method
	// instead of regex pattern to properly handle empty lines between commands
	
	public const CLEANUP_PATTERN = '/^(agenda\s+)?(cleanup|clean)$/i';
	public const RESET_PATTERN = '/^agenda\s+reset$/i';

	/**
	 * Check if message contains multiple config commands
	 */
	private function isBulkConfigMessage(string $message): bool {
		$lines = explode("\n", trim($message));
		$configCount = 0;
		
		foreach ($lines as $line) {
			$line = trim($line);
			// Skip empty lines
			if (empty($line)) {
				continue;
			}
			// Check if line starts with "config "
			if (preg_match('/^config\s+/', $line)) {
				$configCount++;
				if ($configCount >= 2) {
					return true;
				}
			}
		}
		
		return false;
	}

	/**
	 * Parse command from message
	 */
	public function parseCommand(string $message, string $token): ?array {
		$message = trim($message);
		
		// Check for bulk config commands first (multiple config commands in one message)
		if ($this->isBulkConfigMessage($message)) {
			return [
				'command' => 'bulk_config',
				'token' => $token,
				'message' => $message
			];
		}

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
		
		// Config template commands
		if (preg_match(self::CONFIG_TEMPLATE_LIST_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_template',
				'token' => $token,
				'action' => 'list'
			];
		}
		
		if (preg_match(self::CONFIG_TEMPLATE_APPLY_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_template',
				'token' => $token,
				'action' => 'apply',
				'param1' => strtolower($matches[1]) // template name
			];
		}
		
		if (preg_match(self::CONFIG_TEMPLATE_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_template',
				'token' => $token,
				'action' => 'show'
			];
		}
		
		// Config export command
		if (preg_match(self::CONFIG_EXPORT_PATTERN, $message, $matches)) {
			return [
				'command' => 'config_export',
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

		// Reset command
		if (preg_match(self::RESET_PATTERN, $message)) {
			return [
				'command' => 'reset',
				'token' => $token
			];
		}

	return null;
	}

	/**
	 * Parse bulk configuration commands from a multi-line message
	 */
	public function parseBulkCommands(string $message, string $token): array {
		$commands = [];
		$lines = explode("\n", trim($message));
		
		foreach ($lines as $lineNum => $line) {
			$line = trim($line);
			
			// Skip empty lines
			if (empty($line)) {
				continue;
			}
			
			// Parse individual config command
			$parsedCommand = $this->parseCommand($line, $token);
			
			if ($parsedCommand !== null) {
				// Add line number for error reporting
				$parsedCommand['line_number'] = $lineNum + 1;
				$parsedCommand['original_line'] = $line;
				$commands[] = $parsedCommand;
			} else {
				// Enhanced error detection with specific suggestions
				$errorDetails = $this->analyzeCommandError($line);
				$commands[] = [
					'command' => 'invalid',
					'token' => $token,
					'line_number' => $lineNum + 1,
					'original_line' => $line,
					'error' => $errorDetails['message'],
					'suggestion' => $errorDetails['suggestion'] ?? null
				];
			}
		}
		
	return $commands;
	}
	
	/**
	 * Analyze command error and provide specific suggestions
	 */
	private function analyzeCommandError(string $line): array {
		$line = trim($line);
		
		// Check for common command prefixes
		if (preg_match('/^config\s/', $line)) {
			return $this->analyzeConfigCommandError($line);
		}
		
		// Check for time monitoring legacy commands
		if (preg_match('/^time\s/', $line)) {
			return [
				'message' => 'Legacy time command format',
				'suggestion' => 'Use new format: `config time [action]` (e.g., `config time enable`, `config time warning 75`)'
			];
		}
		
		// Check for agenda commands that shouldn\'t be in bulk config
		if (preg_match('/^(agenda|topic|item|add)\s*:/', $line)) {
			return [
				'message' => 'Agenda items cannot be mixed with configuration commands',
				'suggestion' => 'Use separate messages for agenda items and config commands'
			];
		}
		
		// Check for unclear commands
		if (strlen($line) < 3) {
			return [
				'message' => 'Command too short',
				'suggestion' => 'Use `config show` to see available commands'
			];
		}
		
		// Generic error
		return [
			'message' => 'Unrecognized command syntax',
			'suggestion' => 'Use `agenda help` to see all available commands'
		];
	}
	
	/**
	 * Analyze config command specific errors
	 */
	private function analyzeConfigCommandError(string $line): array {
		// Extract the config subcommand
		if (preg_match('/^config\s+(\w+)(?:\s+(.*))?$/i', $line, $matches)) {
			$subcommand = strtolower($matches[1]);
			$params = $matches[2] ?? '';
			
			switch ($subcommand) {
				case 'time':
					return $this->analyzeTimeCommandError($params);
					
				case 'response':
					return $this->analyzeResponseCommandError($params);
					
				case 'limits':
					return $this->analyzeLimitsCommandError($params);
					
				case 'auto':
					return $this->analyzeAutoCommandError($params);
					
				case 'emojis':
					return $this->analyzeEmojisCommandError($params);
					
				case 'template':
					return $this->analyzeTemplateCommandError($params);
					
				case 'export':
					if (!empty($params)) {
						return [
							'message' => 'Export command does not take parameters',
							'suggestion' => 'Use: `config export`'
						];
					}
					return [
						'message' => 'Export command syntax error',
						'suggestion' => 'Use: `config export`'
					];
					
				default:
					return [
						'message' => 'Unknown config area: ' . $subcommand,
						'suggestion' => 'Available areas: time, response, limits, auto, emojis, template, export'
					];
			}
		}
		
		return [
			'message' => 'Invalid config command format',
			'suggestion' => 'Use format: `config [area] [action]` (e.g., `config time enable`)'
		];
	}
	
	/**
	 * Analyze time command errors
	 */
	private function analyzeTimeCommandError(string $params): array {
		if (empty($params)) {
			return [
				'message' => 'Time command missing action',
				'suggestion' => 'Use: enable, disable, warning [10-95], overtime [105-300], thresholds [warn] [overtime], or reset'
			];
		}
		
		// Check for common mistakes
		if (preg_match('/^(warning|overtime)\s+(\d+)/', $params, $matches)) {
			$action = $matches[1];
			$value = (int)$matches[2];
			
			if ($action === 'warning' && ($value < 10 || $value > 95)) {
				return [
					'message' => 'Warning threshold out of range: ' . $value,
					'suggestion' => 'Warning threshold must be between 10-95 (e.g., `config time warning 75`)'
				];
			}
			
			if ($action === 'overtime' && ($value < 105 || $value > 300)) {
				return [
					'message' => 'Overtime threshold out of range: ' . $value,
					'suggestion' => 'Overtime threshold must be between 105-300 (e.g., `config time overtime 120`)'
				];
			}
		}
		
		if (preg_match('/^thresholds\s+(\d+)\s+(\d+)/', $params, $matches)) {
			$warning = (int)$matches[1];
			$overtime = (int)$matches[2];
			
			if ($warning >= $overtime) {
				return [
					'message' => 'Warning threshold must be less than overtime threshold',
					'suggestion' => 'Example: `config time thresholds 75 110` (warning < overtime)'
				];
			}
		}
		
		return [
			'message' => 'Invalid time command parameters',
			'suggestion' => 'Examples: `config time enable`, `config time warning 80`, `config time thresholds 75 110`'
		];
	}
	
	/**
	 * Analyze response command errors
	 */
	private function analyzeResponseCommandError(string $params): array {
		if (empty($params)) {
			return [
				'message' => 'Response command missing mode',
				'suggestion' => 'Use: normal, minimal, or reset'
			];
		}
		
		$mode = strtolower(trim($params));
		if (!in_array($mode, ['normal', 'minimal', 'reset'])) {
			return [
				'message' => 'Invalid response mode: ' . $params,
				'suggestion' => 'Available modes: normal, minimal, or reset'
			];
		}
		
		return [
			'message' => 'Response command syntax error',
			'suggestion' => 'Use: `config response normal` or `config response minimal`'
		];
	}
	
	/**
	 * Analyze limits command errors
	 */
	private function analyzeLimitsCommandError(string $params): array {
		if (empty($params)) {
			return [
				'message' => 'Limits command missing parameters',
				'suggestion' => 'Use: max-items [5-100], max-bulk [3-50], default-duration [1-120], or reset'
			];
		}
		
		// Check for value range errors
		if (preg_match('/^max-items\s+(\d+)/', $params, $matches)) {
			$value = (int)$matches[1];
			if ($value < 5 || $value > 100) {
				return [
					'message' => 'Max items out of range: ' . $value,
					'suggestion' => 'Max items must be between 5-100 (e.g., `config limits max-items 50`)'
				];
			}
		}
		
		if (preg_match('/^max-bulk\s+(\d+)/', $params, $matches)) {
			$value = (int)$matches[1];
			if ($value < 3 || $value > 50) {
				return [
					'message' => 'Max bulk out of range: ' . $value,
					'suggestion' => 'Max bulk must be between 3-50 (e.g., `config limits max-bulk 25`)'
				];
			}
		}
		
		if (preg_match('/^default-duration\s+(\d+)/', $params, $matches)) {
			$value = (int)$matches[1];
			if ($value < 1 || $value > 120) {
				return [
					'message' => 'Default duration out of range: ' . $value,
					'suggestion' => 'Default duration must be between 1-120 minutes (e.g., `config limits default-duration 10`)'
				];
			}
		}
		
		return [
			'message' => 'Invalid limits command parameters',
			'suggestion' => 'Examples: `config limits max-items 30`, `config limits default-duration 15`'
		];
	}
	
	/**
	 * Analyze auto command errors
	 */
	private function analyzeAutoCommandError(string $params): array {
		if (empty($params)) {
			return [
				'message' => 'Auto command missing parameters',
				'suggestion' => 'Use: start-agenda [enable/disable], cleanup [enable/disable], summary [enable/disable], or reset'
			];
		}
		
		// Check for valid auto behaviors
		if (preg_match('/^(start-agenda|cleanup|summary)\s+(\w+)/', $params, $matches)) {
			$behavior = $matches[1];
			$action = strtolower($matches[2]);
			
			if (!in_array($action, ['enable', 'disable'])) {
				return [
					'message' => 'Invalid action for ' . $behavior . ': ' . $action,
					'suggestion' => 'Use enable or disable (e.g., `config auto ' . $behavior . ' enable`)'
				];
			}
		}
		
		return [
			'message' => 'Invalid auto command parameters',
			'suggestion' => 'Examples: `config auto start-agenda enable`, `config auto cleanup disable`'
		];
	}
	
	/**
	 * Analyze emojis command errors
	 */
	private function analyzeEmojisCommandError(string $params): array {
		if (empty($params)) {
			return [
				'message' => 'Emojis command missing parameters',
				'suggestion' => 'Use: current-item, completed, pending, on-time, time-warning [emoji], or reset'
			];
		}
		
		// Check for valid emoji types
		if (preg_match('/^(current-item|completed|pending|on-time|time-warning)\s+(.*)/', $params, $matches)) {
			$emojiType = $matches[1];
			$emoji = trim($matches[2]);
			
			if (empty($emoji)) {
				return [
					'message' => 'Missing emoji for ' . $emojiType,
					'suggestion' => 'Example: `config emojis ' . $emojiType . ' 🎯`'
				];
			}
		}
		
		return [
			'message' => 'Invalid emojis command parameters',
			'suggestion' => 'Examples: `config emojis current-item 🎯`, `config emojis completed 🎉`'
		];
	}
	
	/**
	 * Analyze template command errors
	 */
	private function analyzeTemplateCommandError(string $params): array {
		if (empty($params)) {
			return [
				'message' => 'Template command missing parameters',
				'suggestion' => 'Use: list, [template-name], or reset'
			];
		}
		
		$templateName = strtolower(trim($params));
		$validTemplates = ['formal', 'jour-fixe', 'workshop', 'brainstorm', 'training', 'list', 'none', 'reset'];
		
		if (!in_array($templateName, $validTemplates)) {
			return [
				'message' => 'Unknown template: ' . $params,
				'suggestion' => 'Use `config template list` to see available templates'
			];
		}
		
		return [
			'message' => 'Invalid template command',
			'suggestion' => 'Examples: `config template formal`, `config template list`'
		];
	}

	/**
	 * Check if message is a command
	 */
	public function isCommand(string $message): bool {
		return $this->parseCommand($message, '') !== null;
	}
}
