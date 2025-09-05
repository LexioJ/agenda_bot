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
