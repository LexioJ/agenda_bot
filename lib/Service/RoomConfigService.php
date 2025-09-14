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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class RoomConfigService {
	
	// Default values for room-level configuration
	public const DEFAULT_ENABLED = true;
	public const DEFAULT_WARNING_THRESHOLD = 0.8;        // 80%
	public const DEFAULT_OVERTIME_THRESHOLD = 1.2;       // 120%
	public const FIXED_TIME_REACHED_THRESHOLD = 1.0;     // 100% - never configurable
	public const FIXED_CHECK_INTERVAL = 300;             // 5 minutes - Nextcloud cron
	
	public function __construct(
		private LogEntryMapper $logEntryMapper,
		private ITimeFactory $timeFactory,
		private IConfig $config,
		private LoggerInterface $logger,
		private IDBConnection $db,
	) {
	}
	
	/**
	 * Get room-specific time monitoring configuration
	 * Returns room config if exists, otherwise falls back to global config
	 */
	public function getRoomTimeMonitoringConfig(string $token): array {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if ($roomConfigEntry) {
			$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
			$timeMonitoring = $configData['time_monitoring'] ?? [];
			
			// Return room-specific config with defaults for missing values
			return [
				'enabled' => $timeMonitoring['enabled'] ?? self::DEFAULT_ENABLED,
				'warning_threshold' => (float)($timeMonitoring['warning_threshold'] ?? self::DEFAULT_WARNING_THRESHOLD),
				'overtime_threshold' => (float)($timeMonitoring['overtime_threshold'] ?? self::DEFAULT_OVERTIME_THRESHOLD),
				'check_interval' => self::FIXED_CHECK_INTERVAL,
				'source' => 'room',
			];
		}
		
		// Fallback to global configuration
		return $this->getGlobalTimeMonitoringConfig();
	}
	
	/**
	 * Get unified time monitoring configuration with proper source detection
	 * (This is used by the config handlers)
	 */
	public function getTimeMonitoringConfig(string $token): array {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if ($roomConfigEntry) {
			$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
			$timeMonitoring = $configData['time_monitoring'] ?? null;
			
			// Only return room-specific config if time_monitoring section actually exists
			if ($timeMonitoring !== null) {
				return [
					'enabled' => $timeMonitoring['enabled'] ?? self::DEFAULT_ENABLED,
					'warning_threshold' => (float)($timeMonitoring['warning_threshold'] ?? self::DEFAULT_WARNING_THRESHOLD),
					'overtime_threshold' => (float)($timeMonitoring['overtime_threshold'] ?? self::DEFAULT_OVERTIME_THRESHOLD),
					'check_interval' => self::FIXED_CHECK_INTERVAL,
					'source' => 'room',
					'configured_by' => $configData['configured_by'] ?? null,
					'configured_at' => $configData['configured_at'] ?? null,
				];
			}
		}
		
		// Fallback to global configuration
		return $this->getGlobalTimeMonitoringConfig();
	}
	
	/**
	 * Set room-specific time monitoring configuration
	 */
	public function setRoomTimeMonitoringConfig(string $token, array $config, string $userId = 'system'): void {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if (!$roomConfigEntry) {
			// Create new room config entry
			$roomConfigEntry = new LogEntry();
			$roomConfigEntry->setServer('local');
			$roomConfigEntry->setToken($token);
			$roomConfigEntry->setType(LogEntry::TYPE_ROOM_CONFIG);
		}
		
		// Get existing config data to preserve other settings (like language)
		$existingData = [];
		if ($roomConfigEntry->getId()) {
			$existingData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
		}
		
		// Prepare configuration data, preserving existing non-time-monitoring settings
		$configData = array_merge($existingData, [
			'time_monitoring' => [
				'enabled' => $config['enabled'] ?? self::DEFAULT_ENABLED,
				'warning_threshold' => $this->validateThreshold($config['warning_threshold'] ?? self::DEFAULT_WARNING_THRESHOLD, 0.1, 0.95),
				'overtime_threshold' => $this->validateThreshold($config['overtime_threshold'] ?? self::DEFAULT_OVERTIME_THRESHOLD, 1.05, 3.0),
			],
			'configured_by' => $userId,
			'configured_at' => $this->timeFactory->now()->getTimestamp(),
		]);
		
		$roomConfigEntry->setDetails(json_encode($configData, JSON_THROW_ON_ERROR));
		
		if ($roomConfigEntry->getId()) {
			$this->logEntryMapper->update($roomConfigEntry);
			$this->logger->info('Updated room time monitoring config for token: ' . $token, ['config' => $configData]);
		} else {
			$this->logEntryMapper->insert($roomConfigEntry);
			$this->logger->info('Created room time monitoring config for token: ' . $token, ['config' => $configData]);
		}
	}
	
	/**
	 * Check if room has custom configuration
	 */
	public function hasRoomConfig(string $token): bool {
		return $this->findRoomConfigEntry($token) !== null;
	}
	
	/**
	 * Reset room configuration (delete custom config, fallback to global)
	 */
	public function resetRoomConfig(string $token): bool {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if ($roomConfigEntry) {
			$this->logEntryMapper->delete($roomConfigEntry);
			$this->logger->info('Reset room time monitoring config for token: ' . $token);
			return true;
		}
		
		return false;
	}
	
	/**
	 * Get global time monitoring configuration as fallback
	 */
	private function getGlobalTimeMonitoringConfig(): array {
		return [
			'enabled' => $this->config->getAppValue('agenda_bot', 'time-monitoring-enabled', 'true') === 'true',
			'warning_threshold' => (float)$this->config->getAppValue('agenda_bot', 'warning-threshold', (string)self::DEFAULT_WARNING_THRESHOLD),
			'overtime_threshold' => (float)$this->config->getAppValue('agenda_bot', 'overtime-warning-threshold', (string)self::DEFAULT_OVERTIME_THRESHOLD),
			'check_interval' => self::FIXED_CHECK_INTERVAL,
			'source' => 'global',
		];
	}
	
	/**
	 * Find room configuration entry
	 */
	private function findRoomConfigEntry(string $token): ?LogEntry {
		return $this->logEntryMapper->findRoomConfig($token);
	}
	
	/**
	 * Validate threshold values
	 */
	private function validateThreshold(float $value, float $min, float $max): float {
		return max($min, min($max, $value));
	}
	
	/**
	 * Set room language for proper localization in background jobs
	 */
	public function setRoomLanguage(string $token, string $language): void {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if (!$roomConfigEntry) {
			// Create new room config entry
			$roomConfigEntry = new LogEntry();
			$roomConfigEntry->setServer('local');
			$roomConfigEntry->setToken($token);
			$roomConfigEntry->setType(LogEntry::TYPE_ROOM_CONFIG);
			$configData = [];
		} else {
			// Get existing config data to preserve other settings
			$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
		}
		
		// Update language in config data
		$configData['language'] = $language;
		$configData['language_updated_at'] = $this->timeFactory->now()->getTimestamp();
		
		$roomConfigEntry->setDetails(json_encode($configData, JSON_THROW_ON_ERROR));
		
		if ($roomConfigEntry->getId()) {
			$this->logEntryMapper->update($roomConfigEntry);
		} else {
			$this->logEntryMapper->insert($roomConfigEntry);
		}
		
		$this->logger->debug('Set room language for token: ' . $token, ['language' => $language]);
	}
	
	/**
	 * Get room language for background job localization
	 */
	public function getRoomLanguage(string $token): string {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if (!$roomConfigEntry) {
			return 'en'; // Default to English if no room config
		}
		
		$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
		return $configData['language'] ?? 'en';
	}
	
	/**
	 * Store the message ID of the last agenda summary for cleanup reaction tracking
	 */
	public function setLastSummaryMessageId(string $token, string $messageId): void {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if (!$roomConfigEntry) {
			// Create new room config entry
			$roomConfigEntry = new LogEntry();
			$roomConfigEntry->setServer('local');
			$roomConfigEntry->setToken($token);
			$roomConfigEntry->setType(LogEntry::TYPE_ROOM_CONFIG);
			$configData = [];
		} else {
			// Get existing config data to preserve other settings
			$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
		}
		
		// Update last summary message ID
		$configData['last_summary_message_id'] = $messageId;
		$configData['last_summary_timestamp'] = $this->timeFactory->now()->getTimestamp();
		
		$roomConfigEntry->setDetails(json_encode($configData, JSON_THROW_ON_ERROR));
		
		if ($roomConfigEntry->getId()) {
			$this->logEntryMapper->update($roomConfigEntry);
		} else {
			$this->logEntryMapper->insert($roomConfigEntry);
		}
		
		$this->logger->debug('Stored summary message ID for cleanup tracking', ['token' => $token, 'message_id' => $messageId]);
	}
	
	/**
	 * Clear the stored summary message ID (used after successful cleanup)
	 */
	public function clearLastSummaryMessageId(string $token): void {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if (!$roomConfigEntry) {
			return; // No config to clear
		}
		
		$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
		
		// Remove summary message tracking
		unset($configData['last_summary_message_id']);
		unset($configData['last_summary_timestamp']);
		
		$roomConfigEntry->setDetails(json_encode($configData, JSON_THROW_ON_ERROR));
		$this->logEntryMapper->update($roomConfigEntry);
		
		$this->logger->debug('Cleared summary message ID after cleanup', ['token' => $token]);
	}
	
	/**
	 * Try to find and store the most recent summary message ID by looking for recent bot messages with summary marker
	 * This is a fallback method when direct message ID capture isn't available
	 */
	public function findAndStoreRecentSummaryMessageId(string $token): void {
		try {
			// Query recent bot messages in this room to find the most recent summary
			// Look for messages with both bot emoji (🤖) and cleanup emoji (🧹) as summary indicators
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'message')
				->from('comments')
				->where($qb->expr()->eq('object_type', $qb->createNamedParameter('chat')))
				->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($token)))
				->andWhere($qb->expr()->eq('actor_type', $qb->createNamedParameter('bots')))
				->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter('agenda_bot')))
				->andWhere($qb->expr()->like('message', $qb->createNamedParameter('%🤖%')))
				->andWhere($qb->expr()->like('message', $qb->createNamedParameter('%🧹%')))
				->orderBy('creation_timestamp', 'DESC')
				->setMaxResults(1);
			
			$result = $qb->executeQuery();
			$row = $result->fetch();
			
			if ($row && isset($row['id'])) {
				$messageId = (string)$row['id'];
				$this->setLastSummaryMessageId($token, $messageId);
				$this->logger->debug('Found and stored recent summary message ID', ['token' => $token, 'message_id' => $messageId]);
			} else {
				$this->logger->debug('No recent summary message found', ['token' => $token]);
			}
		} catch (\Exception $e) {
			$this->logger->warning('Failed to find recent summary message: ' . $e->getMessage(), ['token' => $token]);
		}
	}
	
	/**
	 * Get the message ID of the last agenda summary for cleanup reaction tracking
	 */
	public function getLastSummaryMessageId(string $token): ?string {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if (!$roomConfigEntry) {
			return null;
		}
		
		$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
		return $configData['last_summary_message_id'] ?? null;
	}
	
	// Default values for new configuration areas
	public const DEFAULT_AGENDA_LIMITS = [
		'max_items' => 50,
		'max_bulk_items' => 20,
		'default_duration' => 10, // minutes
	];
	
	public const DEFAULT_AUTO_BEHAVIORS = [
		'start_agenda' => true,
		'cleanup' => false,
		'summary' => true,
	];
	
	public const DEFAULT_EMOJIS = [
		'current_item' => '🗣️',
		'completed' => '✅',
		'pending' => '📍',
		'on_time' => '👍',
		'time_warning' => '⏰',
	];
	
	public const DEFAULT_RESPONSE_CONFIG = [
		'response_mode' => 'normal',
	];

	/**
	 * Get room agenda limits configuration
	 */
	public function getAgendaLimitsConfig(string $token): array {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if ($roomConfigEntry) {
			$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
			$limitsConfig = $configData['agenda_limits'] ?? null;
			
			if ($limitsConfig !== null) {
				return [
					'max_items' => $limitsConfig['max_items'] ?? self::DEFAULT_AGENDA_LIMITS['max_items'],
					'max_bulk_items' => $limitsConfig['max_bulk_items'] ?? self::DEFAULT_AGENDA_LIMITS['max_bulk_items'],
					'default_duration' => $limitsConfig['default_duration'] ?? self::DEFAULT_AGENDA_LIMITS['default_duration'],
					'source' => 'room',
					'configured_by' => $configData['configured_by'] ?? null,
					'configured_at' => $configData['configured_at'] ?? null,
				];
			}
		}
		
		return array_merge(self::DEFAULT_AGENDA_LIMITS, ['source' => 'global']);
	}
	
	/**
	 * Set room agenda limits configuration (partial update)
	 */
	public function setAgendaLimitsConfig(string $token, array $config, string $userId = 'system'): void {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if (!$roomConfigEntry) {
			$roomConfigEntry = new LogEntry();
			$roomConfigEntry->setServer('local');
			$roomConfigEntry->setToken($token);
			$roomConfigEntry->setType(LogEntry::TYPE_ROOM_CONFIG);
			$existingData = [];
		} else {
			$existingData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
		}
		
		// Get current limits config and merge with updates
		$currentLimits = $existingData['agenda_limits'] ?? self::DEFAULT_AGENDA_LIMITS;
		$updatedLimits = array_merge($currentLimits, $config);
		
		// Validate limits
		if (isset($updatedLimits['max_items'])) {
			$updatedLimits['max_items'] = max(5, min(100, (int)$updatedLimits['max_items']));
		}
		if (isset($updatedLimits['max_bulk_items'])) {
			$updatedLimits['max_bulk_items'] = max(3, min(50, (int)$updatedLimits['max_bulk_items']));
		}
		if (isset($updatedLimits['default_duration'])) {
			$updatedLimits['default_duration'] = max(1, min(120, (int)$updatedLimits['default_duration']));
		}
		
		$configData = array_merge($existingData, [
			'agenda_limits' => $updatedLimits,
			'configured_by' => $userId,
			'configured_at' => $this->timeFactory->now()->getTimestamp(),
		]);
		
		$roomConfigEntry->setDetails(json_encode($configData, JSON_THROW_ON_ERROR));
		
		if ($roomConfigEntry->getId()) {
			$this->logEntryMapper->update($roomConfigEntry);
		} else {
			$this->logEntryMapper->insert($roomConfigEntry);
		}
		
		$this->logger->info('Updated agenda limits config for token: ' . $token, ['config' => $updatedLimits]);
	}
	
	/**
	 * Get room auto-behaviors configuration
	 */
	public function getAutoBehaviorsConfig(string $token): array {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if ($roomConfigEntry) {
			$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
			$autoConfig = $configData['auto_behaviors'] ?? null;
			
			if ($autoConfig !== null) {
				return [
					'start_agenda' => $autoConfig['start_agenda'] ?? self::DEFAULT_AUTO_BEHAVIORS['start_agenda'],
					'cleanup' => $autoConfig['cleanup'] ?? self::DEFAULT_AUTO_BEHAVIORS['cleanup'],
					'summary' => $autoConfig['summary'] ?? self::DEFAULT_AUTO_BEHAVIORS['summary'],
					'source' => 'room',
					'configured_by' => $configData['configured_by'] ?? null,
					'configured_at' => $configData['configured_at'] ?? null,
				];
			}
		}
		
		return array_merge(self::DEFAULT_AUTO_BEHAVIORS, ['source' => 'global']);
	}
	
	/**
	 * Set room auto-behaviors configuration (partial update)
	 */
	public function setAutoBehaviorsConfig(string $token, array $config, string $userId = 'system'): void {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if (!$roomConfigEntry) {
			$roomConfigEntry = new LogEntry();
			$roomConfigEntry->setServer('local');
			$roomConfigEntry->setToken($token);
			$roomConfigEntry->setType(LogEntry::TYPE_ROOM_CONFIG);
			$existingData = [];
		} else {
			$existingData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
		}
		
		// Get current auto-behaviors config and merge with updates
		$currentAuto = $existingData['auto_behaviors'] ?? self::DEFAULT_AUTO_BEHAVIORS;
		$updatedAuto = array_merge($currentAuto, $config);
		
		// Ensure boolean values
		foreach (['start_agenda', 'cleanup', 'summary'] as $key) {
			if (isset($updatedAuto[$key])) {
				$updatedAuto[$key] = (bool)$updatedAuto[$key];
			}
		}
		
		$configData = array_merge($existingData, [
			'auto_behaviors' => $updatedAuto,
			'configured_by' => $userId,
			'configured_at' => $this->timeFactory->now()->getTimestamp(),
		]);
		
		$roomConfigEntry->setDetails(json_encode($configData, JSON_THROW_ON_ERROR));
		
		if ($roomConfigEntry->getId()) {
			$this->logEntryMapper->update($roomConfigEntry);
		} else {
			$this->logEntryMapper->insert($roomConfigEntry);
		}
		
		$this->logger->info('Updated auto-behaviors config for token: ' . $token, ['config' => $updatedAuto]);
	}
	
	/**
	 * Get room custom emojis configuration
	 */
	public function getEmojisConfig(string $token): array {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if ($roomConfigEntry) {
			$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
			$emojisConfig = $configData['custom_emojis'] ?? null;
			
			if ($emojisConfig !== null) {
				return [
					'current_item' => $emojisConfig['current_item'] ?? self::DEFAULT_EMOJIS['current_item'],
					'completed' => $emojisConfig['completed'] ?? self::DEFAULT_EMOJIS['completed'],
					'pending' => $emojisConfig['pending'] ?? self::DEFAULT_EMOJIS['pending'],
					'on_time' => $emojisConfig['on_time'] ?? self::DEFAULT_EMOJIS['on_time'],
					'time_warning' => $emojisConfig['time_warning'] ?? self::DEFAULT_EMOJIS['time_warning'],
					'source' => 'room',
					'configured_by' => $configData['configured_by'] ?? null,
					'configured_at' => $configData['configured_at'] ?? null,
				];
			}
		}
		
		return array_merge(self::DEFAULT_EMOJIS, ['source' => 'global']);
	}
	
	/**
	 * Set room custom emojis configuration (partial update)
	 */
	public function setEmojisConfig(string $token, array $config, string $userId = 'system'): void {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if (!$roomConfigEntry) {
			$roomConfigEntry = new LogEntry();
			$roomConfigEntry->setServer('local');
			$roomConfigEntry->setToken($token);
			$roomConfigEntry->setType(LogEntry::TYPE_ROOM_CONFIG);
			$existingData = [];
		} else {
			$existingData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
		}
		
		// Get current emojis config and merge with updates
		$currentEmojis = $existingData['custom_emojis'] ?? self::DEFAULT_EMOJIS;
		$updatedEmojis = array_merge($currentEmojis, $config);
		
		// Validate emojis
		foreach ($updatedEmojis as $key => $emoji) {
			if (is_string($emoji)) {
				$emoji = trim($emoji);
				if (empty($emoji) || mb_strlen($emoji) > 10) {
					$updatedEmojis[$key] = self::DEFAULT_EMOJIS[$key] ?? '🔴';
				} else {
					$updatedEmojis[$key] = $emoji;
				}
			}
		}
		
		$configData = array_merge($existingData, [
			'custom_emojis' => $updatedEmojis,
			'configured_by' => $userId,
			'configured_at' => $this->timeFactory->now()->getTimestamp(),
		]);
		
		$roomConfigEntry->setDetails(json_encode($configData, JSON_THROW_ON_ERROR));
		
		if ($roomConfigEntry->getId()) {
			$this->logEntryMapper->update($roomConfigEntry);
		} else {
			$this->logEntryMapper->insert($roomConfigEntry);
		}
		
		$this->logger->info('Updated custom emojis config for token: ' . $token, ['config' => $updatedEmojis]);
	}
	
	/**
	 * Get room response configuration
	 */
	public function getResponseConfig(string $token): array {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if ($roomConfigEntry) {
			$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
			$responseConfig = $configData['response_settings'] ?? null;
			
			if ($responseConfig !== null) {
				return [
					'response_mode' => $responseConfig['response_mode'] ?? self::DEFAULT_RESPONSE_CONFIG['response_mode'],
					'source' => 'room',
					'configured_by' => $configData['configured_by'] ?? null,
					'configured_at' => $configData['configured_at'] ?? null,
				];
			}
		}
		
		return array_merge(self::DEFAULT_RESPONSE_CONFIG, ['source' => 'global']);
	}
	
	/**
	 * Set room response configuration (partial update)
	 */
	public function setResponseConfig(string $token, array $config, string $userId = 'system'): void {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if (!$roomConfigEntry) {
			$roomConfigEntry = new LogEntry();
			$roomConfigEntry->setServer('local');
			$roomConfigEntry->setToken($token);
			$roomConfigEntry->setType(LogEntry::TYPE_ROOM_CONFIG);
			$existingData = [];
		} else {
			$existingData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
		}
		
		// Get current response config and merge with updates
		$currentResponse = $existingData['response_settings'] ?? self::DEFAULT_RESPONSE_CONFIG;
		$updatedResponse = array_merge($currentResponse, $config);
		
		// Validate response mode
		if (isset($updatedResponse['response_mode'])) {
			if (!in_array($updatedResponse['response_mode'], ['normal', 'minimal'], true)) {
				$updatedResponse['response_mode'] = 'normal';
			}
		}
		
		$configData = array_merge($existingData, [
			'response_settings' => $updatedResponse,
			'configured_by' => $userId,
			'configured_at' => $this->timeFactory->now()->getTimestamp(),
		]);
		
		$roomConfigEntry->setDetails(json_encode($configData, JSON_THROW_ON_ERROR));
		
		if ($roomConfigEntry->getId()) {
			$this->logEntryMapper->update($roomConfigEntry);
		} else {
			$this->logEntryMapper->insert($roomConfigEntry);
		}
		
		$this->logger->info('Updated response config for token: ' . $token, ['config' => $updatedResponse]);
	}

	/**
	 * Reset response configuration to global defaults
	 */
	public function resetResponseConfig(string $token): bool {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if (!$roomConfigEntry) {
			return false; // No config to reset
		}
		
		$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
		
		// Remove response_settings section if it exists
		if (!isset($configData['response_settings'])) {
			return false; // No response config to reset
		}
		
		unset($configData['response_settings']);
		
		// If config is now empty (only metadata), delete the entire entry
		if (empty(array_diff_key($configData, ['configured_by', 'configured_at', 'language', 'language_updated_at', 'last_summary_message_id', 'last_summary_timestamp']))) {
			$this->logEntryMapper->delete($roomConfigEntry);
		} else {
			// Update config without response_settings section
			$roomConfigEntry->setDetails(json_encode($configData, JSON_THROW_ON_ERROR));
			$this->logEntryMapper->update($roomConfigEntry);
		}
		
		$this->logger->info('Reset response config for token: ' . $token);
		return true;
	}
	
	/**
	 * Reset custom emojis configuration to global defaults
	 */
	public function resetEmojisConfig(string $token): bool {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if (!$roomConfigEntry) {
			return false; // No config to reset
		}
		
		$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
		
		// Remove custom_emojis section if it exists
		if (!isset($configData['custom_emojis'])) {
			return false; // No emojis config to reset
		}
		
		unset($configData['custom_emojis']);
		
		// If config is now empty (only metadata), delete the entire entry
		if (empty(array_diff_key($configData, ['configured_by', 'configured_at', 'language', 'language_updated_at', 'last_summary_message_id', 'last_summary_timestamp']))) {
			$this->logEntryMapper->delete($roomConfigEntry);
		} else {
			// Update config without custom_emojis section
			$roomConfigEntry->setDetails(json_encode($configData, JSON_THROW_ON_ERROR));
			$this->logEntryMapper->update($roomConfigEntry);
		}
		
		$this->logger->info('Reset custom emojis config for token: ' . $token);
		return true;
	}
	
	/**
	 * Reset auto-behaviors configuration to global defaults
	 */
	public function resetAutoBehaviorsConfig(string $token): bool {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if (!$roomConfigEntry) {
			return false; // No config to reset
		}
		
		$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
		
		// Remove auto_behaviors section if it exists
		if (!isset($configData['auto_behaviors'])) {
			return false; // No auto-behaviors config to reset
		}
		
		unset($configData['auto_behaviors']);
		
		// If config is now empty (only metadata), delete the entire entry
		if (empty(array_diff_key($configData, ['configured_by', 'configured_at', 'language', 'language_updated_at', 'last_summary_message_id', 'last_summary_timestamp']))) {
			$this->logEntryMapper->delete($roomConfigEntry);
		} else {
			// Update config without auto_behaviors section
			$roomConfigEntry->setDetails(json_encode($configData, JSON_THROW_ON_ERROR));
			$this->logEntryMapper->update($roomConfigEntry);
		}
		
		$this->logger->info('Reset auto-behaviors config for token: ' . $token);
		return true;
	}
	
	/**
	 * Reset agenda limits configuration to global defaults
	 */
	public function resetAgendaLimitsConfig(string $token): bool {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if (!$roomConfigEntry) {
			return false; // No config to reset
		}
		
		$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
		
		// Remove agenda_limits section if it exists
		if (!isset($configData['agenda_limits'])) {
			return false; // No agenda limits config to reset
		}
		
		unset($configData['agenda_limits']);
		
		// If config is now empty (only metadata), delete the entire entry
		if (empty(array_diff_key($configData, ['configured_by', 'configured_at', 'language', 'language_updated_at', 'last_summary_message_id', 'last_summary_timestamp']))) {
			$this->logEntryMapper->delete($roomConfigEntry);
		} else {
			// Update config without agenda_limits section
			$roomConfigEntry->setDetails(json_encode($configData, JSON_THROW_ON_ERROR));
			$this->logEntryMapper->update($roomConfigEntry);
		}
		
		$this->logger->info('Reset agenda limits config for token: ' . $token);
		return true;
	}
	
	/**
	 * Get room configuration metadata (who configured it, when)
	 */
	public function getRoomConfigMetadata(string $token): ?array {
		$roomConfigEntry = $this->findRoomConfigEntry($token);
		
		if (!$roomConfigEntry) {
			return null;
		}
		
		$configData = json_decode($roomConfigEntry->getDetails() ?: '{}', true);
		
		return [
			'configured_by' => $configData['configured_by'] ?? 'unknown',
			'configured_at' => $configData['configured_at'] ?? null,
			'language' => $configData['language'] ?? 'en',
			'language_updated_at' => $configData['language_updated_at'] ?? null,
			'last_summary_message_id' => $configData['last_summary_message_id'] ?? null,
			'last_summary_timestamp' => $configData['last_summary_timestamp'] ?? null,
		];
	}
}
