<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Listener;

use OCA\AgendaBot\AppInfo\Application;
use OCA\AgendaBot\Model\Bot;
use OCA\AgendaBot\Model\LogEntry;
use OCA\AgendaBot\Model\LogEntryMapper;
use OCA\AgendaBot\Service\SummaryService;
use OCA\AgendaBot\Service\AgendaService;
use OCA\AgendaBot\Service\CommandParser;
use OCA\AgendaBot\Service\PermissionService;
use OCA\AgendaBot\Service\RoomConfigService;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Events\BotInvokeEvent;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DBException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class BotInvokeListener implements IEventListener {
	public function __construct(
		protected ITimeFactory $timeFactory,
		protected LogEntryMapper $logEntryMapper,
		protected SummaryService $summaryService,
		protected AgendaService $agendaService,
		protected CommandParser $commandParser,
		protected PermissionService $permissionService,
		protected IConfig $config,
		protected LoggerInterface $logger,
		protected IFactory $l10nFactory,
		protected RoomConfigService $roomConfigService,
		protected ChatManager $chatManager,
		protected Manager $roomManager,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BotInvokeEvent) {
			return;
		}

		if (!str_starts_with($event->getBotUrl(), 'nextcloudapp://' . Application::APP_ID . '/')) {
			return;
		}

		[,, $appId, $lang] = explode('/', $event->getBotUrl(), 4);
		if ($appId !== Application::APP_ID || !in_array($lang, Bot::SUPPORTED_LANGUAGES, true)) {
			return;
		}

		$this->receiveWebhook($lang, $event);
	}

	public function receiveWebhook(string $lang, BotInvokeEvent $event): void {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		$data = $event->getMessage();
		
		
		// Store room language for background job localization
		$token = $this->extractTokenFromEventData($data);
		if ($token) {
			$this->roomConfigService->setRoomLanguage($token, $lang);
		}
		
		if ($data['type'] === 'Like') {
			// Handle reaction events
			$this->handleReactionEvent($data, $event);
			return;
		}
		
		if ($data['type'] === 'Join') {
			// Bot has been activated/enabled in the room - show welcome message
			$token = $data['object']['id'];
			$welcome = $this->getBotWelcomeMessage($lang, $token, $data['actor'] ?? []);
			
			// Send welcome message directly using ChatManager since BotService doesn't process answers for Join events
			$this->sendWelcomeMessage($token, $welcome, $data['actor'] ?? [], $lang);
			return;
		}
		
		if ($data['type'] === 'Create' && $data['object']['name'] === 'message') {
			$messageData = json_decode($data['object']['content'], true);
			$message = $messageData['message'];
			$token = $data['target']['id'];

			// Check for commands first
			$command = $this->commandParser->parseCommand($message, $token);
			if ($command) {
				$response = $this->handleCommand($command, $data['actor'] ?? [], $lang);
				if ($response) {
					$event->addAnswer($response, true);
					return;
				}
			}


			// Check if this is a bulk agenda format first (has priority over single items)
			$bulkAgendaData = $this->agendaService->parseBulkAgendaItems($message, $token);
			if ($bulkAgendaData) {
				$result = $this->agendaService->addBulkAgendaItems($token, $bulkAgendaData, $data['actor'] ?? null, $lang);
				$event->addAnswer($result['message'], true);
				return;
			}
			
			// Check if this is a single agenda item
			$agendaData = $this->agendaService->parseAgendaItem($message, $token);
			if ($agendaData) {
				$result = $this->agendaService->addAgendaItem($token, $agendaData, $data['actor'] ?? null, $lang);
				if ($result['success']) {
					$event->addAnswer($result['message'], true);
				} else {
					$event->addAnswer($result['message'], true);
				}
				return;
			}

			// Process other messages (non-agenda items) - no reaction needed
			$this->summaryService->processMessage($message, $data);

		} elseif ($data['type'] === 'Activity') {
			$token = $data['target']['id'];
			
			// Welcome message when bot is activated in conversation
			if ($data['object']['name'] === 'bot_enabled' ||
				$data['object']['name'] === 'bot_installed') {
				$welcome = $this->getBotWelcomeMessage($lang, $token, $data['actor'] ?? []);
				
				// Send welcome message directly using ChatManager
				$this->sendWelcomeMessage($token, $welcome, $data['actor'] ?? [], $lang);
				return;
			}
			
		if ($data['object']['name'] === 'call_joined' || $data['object']['name'] === 'call_started') {
			if ($data['object']['name'] === 'call_started') {
				$this->summaryService->logCallStart($token);
				
				// Check if the call was started silently by looking at system message content
				$isCallSilent = $this->isCallStartedSilently($data);
				
				// Log the call detection result for debugging
				$this->logger->info('Call started - silent detection result', [
					'token' => $token,
					'is_silent' => $isCallSilent,
					'object_name' => $data['object']['name'] ?? 'unknown'
				]);
				
				// Check if there are agenda items
				$items = $this->agendaService->getAgendaItems($token);
				if (!empty($items)) {
					// Check auto-behaviors configuration for start_agenda setting
					$autoConfig = $this->roomConfigService->getAutoBehaviorsConfig($token);
					
					// Auto-set first incomplete item as current only if enabled
					if ($autoConfig['start_agenda']) {
						$this->autoSetFirstIncompleteItemAsCurrent($token, $lang);
					}
					
					// Show current agenda status
					$status = $this->agendaService->getAgendaStatus($token, $lang);
					
					// For silent calls, send the agenda status silently (no notifications)
					// For regular calls, send with notifications
					$event->addAnswer($status, $isCallSilent);
				}
				// No message when agenda is empty - maintains silent/non-silent behavior
			}

			// Log attendee
			$displayName = $data['actor']['name'];
			if (str_starts_with($data['actor']['id'], 'guests/') || str_starts_with($data['actor']['id'], 'emails/')) {
				if ($displayName === '') {
					return;
				}
				$l = $this->l10nFactory->get(Application::APP_ID, $lang);
				$displayName = $l->t('%s (guest)', [$displayName]);
			} elseif (str_starts_with($data['actor']['id'], 'federated_users/')) {
				$cloudIdServer = explode('@', $data['actor']['id']);
				$displayName .= ' (' . array_pop($cloudIdServer) . ')';
			}

				$this->summaryService->logAttendee($token, $displayName);

			} elseif ($data['object']['name'] === 'call_ended' || $data['object']['name'] === 'call_ended_everyone') {
				$this->summaryService->logCallEnd($token);
				
				// Clear any current agenda items since the call has ended
				$this->agendaService->clearAllCurrentItems($token);
				
				// Check auto-behaviors configuration for summary and cleanup settings
				$autoConfig = $this->roomConfigService->getAutoBehaviorsConfig($token);
				
				// Generate summary only if auto-summary is enabled
				if ($autoConfig['summary']) {
					$summary = $this->summaryService->generateAgendaSummary($token, $data['target']['name'], $lang);
					if ($summary !== null) {
						$event->addAnswer($summary['summary'], false);
						
						// Try to find and store the message ID of the summary we just sent
						// This enables more accurate reaction-based cleanup tracking
						$this->roomConfigService->findAndStoreRecentSummaryMessageId($token);
					}
				}
				
				// Auto-cleanup completed items if enabled (after summary generation)
				if ($autoConfig['cleanup']) {
					$this->logger->info('Auto-cleanup triggered on call end', ['token' => $token]);
					$cleanupResult = $this->agendaService->removeCompletedItems($token, null, $lang);
					$this->logger->info('Auto-cleanup result', ['result' => $cleanupResult]);
					if ($cleanupResult && !str_contains($cleanupResult, 'No completed items')) {
						// Send cleanup result as a separate message (emoji already included)
						$event->addAnswer($cleanupResult, true);
					}
				}
			}
		}
	}

	/**
	 * Check if the call was started silently based on the system message content
	 */
	private function isCallStartedSilently(array $data): bool {
		// Check if we have the system message content that indicates a silent call
		$content = $data['object']['content'] ?? '';
		if (empty($content)) {
			return false;
		}
		
		try {
			$messageData = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
			
			// Check if the system message indicates a call_started event
			if (!isset($messageData['message']) || $messageData['message'] !== 'call_started') {
				return false;
			}
			
			// For call_started messages, look for the silent flag in message parameters
			// This is how Talk internally tracks silent calls
			if (isset($messageData['parameters']['silent']) && $messageData['parameters']['silent'] === true) {
				return true;
			}
			
			// As a fallback, check the translated message content for silent indicators
			// This handles cases where the parsed message contains "silent" keywords
			if (isset($data['object']['name']) && $data['object']['name'] === 'call_started') {
				// Look for silent keywords in translated message parameters
				if (isset($messageData['parameters'])) {
					foreach ($messageData['parameters'] as $key => $value) {
						if (is_string($value) && str_contains(strtolower($value), 'silent')) {
							return true;
						}
					}
				}
			}
			
		} catch (\JsonException $e) {
			// If we can't parse the content, assume it's not silent
			$this->logger->debug('Could not parse call event content as JSON', [
				'content' => $content,
				'error' => $e->getMessage(),
				'object_name' => $data['object']['name'] ?? 'unknown'
			]);
		}
		
		return false;
	}
	
	/**
	 * Auto-set the first incomplete agenda item as current when call starts
	 */
	private function autoSetFirstIncompleteItemAsCurrent(string $token, string $lang): void {
		// Check if there's already a current item
		$currentItem = $this->agendaService->getCurrentAgendaItem($token);
		if ($currentItem !== null) {
			// Already have a current item, don't change it
			return;
		}

		// Find the first incomplete item
		$incompleteItems = $this->logEntryMapper->findIncompleteAgendaItems($token);
		if (!empty($incompleteItems)) {
			$firstItem = $incompleteItems[0];
			$this->agendaService->setCurrentAgendaItem($token, $firstItem->getOrderPosition(), null, $lang);
		}
	}

	/**
	 * Send welcome message directly via ChatManager
	 */
	private function sendWelcomeMessage(string $token, string $message, array $actorData, string $lang = 'en'): void {
		try {
			$room = $this->roomManager->getRoomByToken($token);
			$creationDateTime = $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'));
			
			// Get room language for proper localization
			$roomLang = $this->roomConfigService->getRoomLanguage($token) ?? $lang;
			$l = $this->l10nFactory->get(Application::APP_ID, $roomLang);
			
			// Use localized bot name to match how other bot messages appear
			$botDisplayName = $l->t('Agenda');
			
			$this->chatManager->sendMessage(
				$room,
				null, // participant
				Attendee::ACTOR_BOTS, // actor type  
				$botDisplayName, // Use friendly display name instead of raw actor ID
				$message, // message content
				$creationDateTime, // creation time
				null, // parent comment (no reply)
				'', // reference ID
				false, // not silent
				rateLimitGuestMentions: false
			);
		} catch (\Exception $e) {
			$this->logger->error('Failed to send welcome message via ChatManager', [
				'error' => $e->getMessage(),
				'token' => $token,
				'exception' => $e
			]);
		}
	}
	
	/**
	 * Get bot welcome message with help
	 */
	private function getBotWelcomeMessage(string $lang, string $token = '', array $actorData = []): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
	return "### 👋 **" . $l->t('Hi there! I\'m your agenda assistant') . "** 🤖\n\n" .
			   $l->t("I'm here to help you stay organized and keep your meetings on track with smart agenda management and time tracking.") . "\n\n" .
			   $this->agendaService->getAgendaHelp($token, $actorData ?: null, $lang) . "\n\n" .
			   "🎉 **" . $l->t('Ready to get started? Try adding your first agenda item:') . "**\n" .
			   "• `" . $l->t('agenda: Welcome & introductions (5 min)') . "`\n\n" .
			   "💡 *" . $l->t('Note: You can delete this welcome message if desired.') . "*";
	}

	/**
	 * Handle reaction events
	 */
	private function handleReactionEvent(array $data, BotInvokeEvent $event): void {
		$token = $data['target']['id'];
		$messageId = $data['object']['id'] ?? null;
		$reaction = $data['content'] ?? '';
		$actorData = $data['actor'] ?? [];
		
		
		if (!$messageId) {
			return;
		}
		
		// Check if reaction is a cleanup emoji first
		if (!in_array($reaction, ['🧹', '👍', '✅'])) {
			return;
		}
		
		// Check if the reacted message is the last agenda summary message
		$lastSummaryMessageId = $this->roomConfigService->getLastSummaryMessageId($token);
		$isSummaryMessage = ($lastSummaryMessageId === $messageId);
		
		
		// Fallback: If no stored message ID, check if message content looks like a summary
		if (!$isSummaryMessage && isset($data['object']['content'])) {
			$messageData = json_decode($data['object']['content'], true);
			$messageContent = $messageData['message'] ?? '';
			// Look for summary characteristics: contains bot emoji and cleanup question
			$isSummaryMessage = (str_contains($messageContent, '🤖') && str_contains($messageContent, '🧹'));
		}
		
		// No final fallback - only process reactions on confirmed summary messages
		// This prevents false positives from agenda items, user messages, etc.
		
		// Only process reactions if we can confirm it's likely a summary message reaction
		if (!$isSummaryMessage) {
			return;
		}
		
		// Get stored room language for localized messages
		$roomLanguage = $this->roomConfigService->getRoomLanguage($token) ?? 'en';
		
		$this->logger->info('Processing cleanup reaction', [
			'token' => $token,
			'reaction' => $reaction,
			'actor' => $actorData['name'] ?? 'unknown',
			'room_language' => $roomLanguage
		]);
		
		// For reaction-triggered cleanup, bypass permission check since:
		// 1. Only users with conversation access can react to messages
		// 2. Reactions are typically made by moderators/owners managing the meeting
		// 3. The reaction itself serves as user consent for cleanup
		$this->logger->info('Reaction-triggered cleanup', ['token' => $token, 'reaction' => $reaction]);
		$cleanupResult = $this->agendaService->removeCompletedItems($token, null, $roomLanguage);
		$this->logger->info('Reaction-cleanup result', ['result' => $cleanupResult]);
		if ($cleanupResult) {
			// Clear the stored summary message ID since cleanup was successful
			if ($lastSummaryMessageId === $messageId) {
				$this->roomConfigService->clearLastSummaryMessageId($token);
			}
			
			$event->addAnswer($cleanupResult, true);
		}
	}

	/**
	 * Handle bot commands
	 */
	private function handleCommand(array $command, array $actorData = [], string $lang = 'en'): ?string {
		switch ($command['command']) {
			case 'status':
				return $this->agendaService->getAgendaStatus($command['token'], $lang);

			case 'help':
				return $this->agendaService->getAgendaHelp($command['token'], $actorData ?: null, $lang);

			case 'clear':
				return $this->agendaService->clearAgenda($command['token'], $actorData ?: null, $lang);

			case 'complete':
				return $this->agendaService->completeItem($command['token'], $command['item'], $actorData ?: null, $lang);

			case 'reopen':
				return $this->agendaService->reopenAgendaItem($command['token'], $command['item'], $actorData ?: null, $lang);

			case 'next':
				return $this->agendaService->setCurrentAgendaItem($command['token'], $command['item'], $actorData ?: null, $lang);

			case 'reorder':
				return $this->agendaService->reorderAgendaItems($command['token'], $command['positions'], $actorData ?: null, $lang);

			case 'move':
				return $this->agendaService->moveAgendaItem($command['token'], $command['from'], $command['to'], $actorData ?: null, $lang);

			case 'swap':
				return $this->agendaService->swapAgendaItems($command['token'], $command['item1'], $command['item2'], $actorData ?: null, $lang);

			case 'remove':
				return $this->agendaService->removeAgendaItem($command['token'], $command['item'], $actorData ?: null, $lang);

			case 'change':
				return $this->agendaService->modifyAgendaItem($command['token'], $command['item'], $command['new_title'], $command['new_duration'], $actorData ?: null, $lang);

			// Room-level time monitoring commands
			case 'time_config':
				return $this->agendaService->getTimeMonitoringStatus($command['token'], $lang);

			case 'time_enable':
				$enabled = $command['action'] === 'enable';
				$result = $this->agendaService->setTimeMonitoringConfig(['enabled' => $enabled], $command['token'], $actorData ?: null, $lang);
				return $result['message'];

			case 'time_warning':
				$threshold = $command['threshold'] / 100.0; // Convert percentage to decimal
				$result = $this->agendaService->setTimeMonitoringConfig(['warning_threshold' => $threshold], $command['token'], $actorData ?: null, $lang);
				return $result['message'];

			case 'time_overtime':
				$threshold = $command['threshold'] / 100.0; // Convert percentage to decimal
				$result = $this->agendaService->setTimeMonitoringConfig(['overtime_threshold' => $threshold], $command['token'], $actorData ?: null, $lang);
				return $result['message'];

			case 'time_thresholds':
				$config = [
					'warning_threshold' => $command['warning_threshold'] / 100.0,  // Convert percentage to decimal
					'overtime_threshold' => $command['overtime_threshold'] / 100.0  // Convert percentage to decimal
				];
				$result = $this->agendaService->setTimeMonitoringConfig($config, $command['token'], $actorData ?: null, $lang);
				return $result['message'];

			case 'time_reset':
				// Reset room config by deleting it (will fallback to global config)
				$l = $this->l10nFactory->get(Application::APP_ID, $lang);
				
				// Check moderator permissions
				if (!empty($actorData) && !$this->permissionService->isActorModerator($command['token'], $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure time monitoring settings'), $lang);
				}
				
				// Call reset method via RoomConfigService
				$reset = $this->roomConfigService->resetRoomConfig($command['token']);
				if ($reset) {
					return '✅ ' . $l->t('Room time monitoring reset to global defaults');
				} else {
					return 'ℹ️ ' . $l->t('Room configuration not found') . ' - ' . $l->t('This room is using global defaults. Use time commands to set room-specific configuration.');
				}

			case 'cleanup':
				return $this->agendaService->removeCompletedItems($command['token'], $actorData ?: null, $lang);

			// Unified config commands
			case 'config_show':
				return $this->handleConfigShow($command['token'], $actorData ?: null, $lang);

			case 'config_time':
				return $this->handleConfigTime(
					$command['token'], 
					$command['action'] ?? 'show', 
					$command['param1'] ?? null, 
					$command['param2'] ?? null, 
					$actorData ?: null, 
					$lang
				);

			case 'config_response':
				return $this->handleConfigResponse($command['token'], $command['action'] ?? 'show', $actorData ?: null, $lang);

			case 'config_limits':
				return $this->handleConfigLimits($command['token'], $command['action'] ?? 'show', $command['param1'] ?? null, $actorData ?: null, $lang);

			case 'config_auto':
				return $this->handleConfigAuto($command['token'], $command['action'] ?? 'show', $command['param1'] ?? null, $actorData ?: null, $lang);

			case 'config_emojis':
				return $this->handleConfigEmojis($command['token'], $command['action'] ?? 'show', $command['param1'] ?? null, $command['param2'] ?? null, $actorData ?: null, $lang);

			default:
				return null;
		}
	}
	
	/**
	 * Extract token from various event data structures
	 */
	private function extractTokenFromEventData(array $data): ?string {
		// Try different locations where token might be present based on event type
		if ($data['type'] === 'Create' && isset($data['target']['id'])) {
			// For message creation events, target contains the room token
			return $data['target']['id'];
		}
		if ($data['type'] === 'Activity' && isset($data['target']['id'])) {
			// For activity events, target contains the room token
			return $data['target']['id'];
		}
		if ($data['type'] === 'Like' && isset($data['target']['id'])) {
			// For reaction events, target contains the room token
			return $data['target']['id'];
		}
		if ($data['type'] === 'Join' && isset($data['object']['id'])) {
			// For join events, object contains the room token
			return $data['object']['id'];
		}
		// Fallback: try both locations
		if (isset($data['target']['id'])) {
			return $data['target']['id'];
		}
		if (isset($data['object']['id'])) {
			return $data['object']['id'];
		}
		return null;
	}

	/**
	 * Handle config show command - display all room configuration
	 */
	private function handleConfigShow(string $token, ?array $actorData, string $lang): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Get all configuration areas
		$timeConfig = $this->roomConfigService->getTimeMonitoringConfig($token);
		$responseConfig = $this->roomConfigService->getResponseConfig($token);
		$limitsConfig = $this->roomConfigService->getAgendaLimitsConfig($token);
		$autoConfig = $this->roomConfigService->getAutoBehaviorsConfig($token);
		$emojisConfig = $this->roomConfigService->getEmojisConfig($token);
		
		$output = "### ⚙️ " . $l->t('Room Configuration') . "\n";
		
		// Time monitoring section
		$output .= "\n##### 🕙 " . $l->t('Time Monitoring') . "\n";
		$output .= "• **" . $l->t('Status') . "**: " . ($timeConfig['enabled'] ? "✅ " . $l->t('Enabled') : "❌ " . $l->t('Disabled')) . "\n";
		$output .= "• **" . $l->t('Warning threshold') . "**: " . round($timeConfig['warning_threshold'] * 100) . "% " . $l->t('of planned time') . "\n";
		$output .= "• **" . $l->t('Overtime threshold') . "**: " . round($timeConfig['overtime_threshold'] * 100) . "% " . $l->t('of planned time') . "\n";
		
		if ($timeConfig['source'] === 'room' && ($timeConfig['configured_by'] ?? null)) {
			$configDate = date('Y-m-d H:i', $timeConfig['configured_at'] ?? time());
			$output .= "• **" . $l->t('Configured by') . "**: " . $timeConfig['configured_by'] . " (" . $configDate . ")\n";
		} else {
			$output .= "• **" . $l->t('Configured by') . "**: " . $l->t('Global defaults') . "\n";
		}
		$output .= "💡 " . $l->t('Use `config time` for time configuration help') . "\n";
		
		// Response settings section
		$output .= "\n##### 💬 " . $l->t('Response') . "\n";
		if ($responseConfig['response_mode'] === 'minimal') {
			$output .= "• **" . $l->t('Response mode') . "**: 😴 " . $l->t('Minimal mode') . " — " . $l->t('Emoji reactions only') . "\n";
			$output .= "• **" . $l->t('Text responses') . "**: " . $l->t('Only for help, status, and call notifications') . "\n";
		} else {
			$output .= "• **" . $l->t('Response mode') . "**: 💬 " . $l->t('Normal mode') . " — " . $l->t('Full text responses') . "\n";
			$output .= "• **" . $l->t('Text responses') . "**: " . $l->t('For all commands and operations') . "\n";
		}
		
		if ($responseConfig['source'] === 'room' && ($responseConfig['configured_by'] ?? null)) {
			$configDate = date('Y-m-d H:i', $responseConfig['configured_at'] ?? time());
			$output .= "• **" . $l->t('Configured by') . "**: " . ($responseConfig['configured_by'] ?? 'Unknown') . " (" . $configDate . ")\n";
		} else {
			$output .= "• **" . $l->t('Configured by') . "**: " . $l->t('Global defaults') . "\n";
		}
		$output .= "💡 " . $l->t('Use `config response` for response configuration help') . "\n";
		
		// Agenda limits section
		$output .= "\n##### 📊 " . $l->t('Agenda Limits') . "\n";
		$output .= "• **" . $l->t('Max total items') . "**: " . $limitsConfig['max_items'] . " " . $l->t('items') . "\n";
		$output .= "• **" . $l->t('Max bulk operation') . "**: " . $limitsConfig['max_bulk_items'] . " " . $l->t('items') . "\n";
		$output .= "• **" . $l->t('Default item duration') . "**: " . $limitsConfig['default_duration'] . " " . $l->t('minutes') . "\n";
		
		if ($limitsConfig['source'] === 'room' && ($limitsConfig['configured_by'] ?? null)) {
			$configDate = date('Y-m-d H:i', $limitsConfig['configured_at'] ?? time());
			$output .= "• **" . $l->t('Configured by') . "**: " . $limitsConfig['configured_by'] . " (" . $configDate . ")\n";
		} else {
			$output .= "• **" . $l->t('Configured by') . "**: " . $l->t('Global defaults') . "\n";
		}
		$output .= "💡 " . $l->t('Use `config limits` for limits configuration help') . "\n";
		
		// Auto-behaviors section
		$output .= "\n##### 🤖 " . $l->t('Auto-behaviors') . "\n";
		$output .= "• **" . $l->t('Start agenda on call') . "**: " . ($autoConfig['start_agenda'] ? "✅ " . $l->t('Enabled') : "❌ " . $l->t('Disabled')) . "\n";
		$output .= "• **" . $l->t('Auto-cleanup completed') . "**: " . ($autoConfig['cleanup'] ? "✅ " . $l->t('Enabled') : "❌ " . $l->t('Disabled')) . "\n";
		$output .= "• **" . $l->t('Generate summaries') . "**: " . ($autoConfig['summary'] ? "✅ " . $l->t('Enabled') : "❌ " . $l->t('Disabled')) . "\n";
		
		if ($autoConfig['source'] === 'room' && ($autoConfig['configured_by'] ?? null)) {
			$configDate = date('Y-m-d H:i', $autoConfig['configured_at'] ?? time());
			$output .= "• **" . $l->t('Configured by') . "**: " . $autoConfig['configured_by'] . " (" . $configDate . ")\n";
		} else {
			$output .= "• **" . $l->t('Configured by') . "**: " . $l->t('Global defaults') . "\n";
		}
		$output .= "💡 " . $l->t('Use `config auto` for auto-behaviors configuration help') . "\n";
		
		// Custom emojis section
		$output .= "\n##### 😀 " . $l->t('Custom Emojis') . "\n";
		$output .= "• **" . $l->t('Current agenda item') . "**: " . $emojisConfig['current_item'] . "\n";
		$output .= "• **" . $l->t('Completed agenda item') . "**: " . $emojisConfig['completed'] . "\n";
		$output .= "• **" . $l->t('Pending agenda item') . "**: " . $emojisConfig['pending'] . "\n";
		$output .= "• **" . $l->t('On time icon') . "**: " . $emojisConfig['on_time'] . "\n";
		$output .= "• **" . $l->t('Time warning icon') . "**: " . $emojisConfig['time_warning'] . "\n";
		
		if ($emojisConfig['source'] === 'room' && ($emojisConfig['configured_by'] ?? null)) {
			$configDate = date('Y-m-d H:i', $emojisConfig['configured_at'] ?? time());
			$output .= "• **" . $l->t('Configured by') . "**: " . $emojisConfig['configured_by'] . " (" . $configDate . ")\n";
		} else {
			$output .= "• **" . $l->t('Configured by') . "**: " . $l->t('Global defaults') . "\n";
		}
		$output .= "💡 " . $l->t('Use `config emojis` for custom emojis configuration help') . "\n";
		
		$output .= "\n---\n";
		$output .= "🔒 " . $l->t('Only moderators and owners can modify room configuration') . "\n";
		
		return $output;
	}

	/**
	 * Handle config limits command - display/configure agenda limits
	 */
	private function handleConfigLimits(string $token, string $action, ?int $param1, ?array $actorData, string $lang): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		switch ($action) {
			case 'show':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('view detailed limits configuration'), $lang);
				}
				$limitsConfig = $this->roomConfigService->getAgendaLimitsConfig($token);
				$output = "### 📊 " . $l->t('Agenda Limits Configuration') . "\n\n";
				$output .= "• **" . $l->t('Max total items') . "**: " . $limitsConfig['max_items'] . " " . $l->t('items') . "\n";
				$output .= "• **" . $l->t('Max bulk operation') . "**: " . $limitsConfig['max_bulk_items'] . " " . $l->t('items') . "\n";
				$output .= "• **" . $l->t('Default item duration') . "**: " . $limitsConfig['default_duration'] . " " . $l->t('minutes') . "\n";
				
				if ($limitsConfig['source'] === 'room' && ($limitsConfig['configured_by'] ?? null)) {
					$configDate = date('Y-m-d H:i', $limitsConfig['configured_at'] ?? time());
					$output .= "• **" . $l->t('Configured by') . "**: " . $limitsConfig['configured_by'] . " (" . $configDate . ")\n";
				} else {
					$output .= "• **" . $l->t('Configured by') . "**: " . $l->t('Global defaults') . "\n";
				}
				
				$output .= "\n---\n";
				$output .= "💡 **" . $l->t('Available Commands') . ":**\n";
				$output .= "• `config limits max-items 30` — " . $l->t('Set maximum total agenda items (5-100)') . "\n";
				$output .= "• `config limits max-bulk 15` — " . $l->t('Set maximum bulk operation items (3-50)') . "\n";
				$output .= "• `config limits default-duration 15` — " . $l->t('Set default item duration in minutes (1-120)') . "\n";
				$output .= "• `config limits reset` — " . $l->t('Reset limits to global defaults') . "\n";
				$output .= "\n🔒 " . $l->t('Only moderators/owners can change agenda limits') . "\n";
				
				return $output;
				
			case 'max-items':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure agenda limits'), $lang);
				}
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setAgendaLimitsConfig($token, ['max_items' => $param1], $userId);
				return "✅ " . $l->t('Maximum total items set to: %d', [$param1]);
				
			case 'max-bulk':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure agenda limits'), $lang);
				}
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setAgendaLimitsConfig($token, ['max_bulk_items' => $param1], $userId);
				return "✅ " . $l->t('Maximum bulk operation items set to: %d', [$param1]);
				
			case 'default-duration':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure agenda limits'), $lang);
				}
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setAgendaLimitsConfig($token, ['default_duration' => $param1], $userId);
				return "✅ " . $l->t('Default item duration set to: %d minutes', [$param1]);
				
			case 'reset':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('reset agenda limits'), $lang);
				}
				$resetSuccess = $this->roomConfigService->resetAgendaLimitsConfig($token);
				if ($resetSuccess) {
					return "✅ " . $l->t('Agenda limits reset to global defaults');
				} else {
					return "ℹ️ " . $l->t('No custom agenda limits configuration found') . " - " . $l->t('Using global defaults');
				}
				
			default:
		return "❌ " . $l->t('Unknown limits action') . ": " . $action;
		}
	}

	/**
	 * Extract user ID from actor data
	 */
	private function extractUserIdFromActorData(?array $actorData): string {
		if (!$actorData) {
			return 'system';
		}
		$rawUserId = $actorData['id'] ?? ($actorData['name'] ?? 'unknown');
		return $this->cleanUserId($rawUserId);
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
	 * Handle config auto command - basic implementation
	 */
	private function handleConfigAuto(string $token, string $action, $param1, ?array $actorData, string $lang): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		switch ($action) {
			case 'show':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('view detailed auto-behaviors configuration'), $lang);
				}
				$autoConfig = $this->roomConfigService->getAutoBehaviorsConfig($token);
				$output = "### 🤖 " . $l->t('Auto-behaviors Configuration') . "\n\n";
				$output .= "• **" . $l->t('Start agenda on call') . "**: " . ($autoConfig['start_agenda'] ? "✅ " . $l->t('Enabled') : "❌ " . $l->t('Disabled')) . "\n";
				$output .= "• **" . $l->t('Auto-cleanup completed') . "**: " . ($autoConfig['cleanup'] ? "✅ " . $l->t('Enabled') : "❌ " . $l->t('Disabled')) . "\n";
				$output .= "• **" . $l->t('Generate summaries') . "**: " . ($autoConfig['summary'] ? "✅ " . $l->t('Enabled') : "❌ " . $l->t('Disabled')) . "\n";
				
				if ($autoConfig['source'] === 'room' && ($autoConfig['configured_by'] ?? null)) {
					$configDate = date('Y-m-d H:i', $autoConfig['configured_at'] ?? time());
					$output .= "• **" . $l->t('Configured by') . "**: " . $autoConfig['configured_by'] . " (" . $configDate . ")\n";
				} else {
					$output .= "• **" . $l->t('Configured by') . "**: " . $l->t('Global defaults') . "\n";
				}
				
				$output .= "\n---\n";
				$output .= "💡 **" . $l->t('Available Commands') . ":**\n";
				$output .= "• `config auto start-agenda enable` — " . $l->t('Auto-set first item as current on call start') . "\n";
				$output .= "• `config auto start-agenda disable` — " . $l->t('Disable auto-start agenda behavior') . "\n";
				$output .= "• `config auto cleanup enable` — " . $l->t('Auto-remove completed items') . "\n";
				$output .= "• `config auto cleanup disable` — " . $l->t('Disable auto-cleanup behavior') . "\n";
				$output .= "• `config auto summary enable` — " . $l->t('Generate summaries on call end') . "\n";
				$output .= "• `config auto summary disable` — " . $l->t('Disable automatic summary generation') . "\n";
				$output .= "• `config auto reset` — " . $l->t('Reset auto-behaviors to global defaults') . "\n";
				$output .= "\n🔒 " . $l->t('Only moderators/owners can change auto-behaviors') . "\n";
				
				return $output;
				
			case 'start-agenda':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure auto-behaviors'), $lang);
				}
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setAutoBehaviorsConfig($token, ['start_agenda' => $param1], $userId);
				return "✅ " . $l->t('Auto-start agenda on call: %s', [$param1 ? $l->t('Enabled') : $l->t('Disabled')]);
				
			case 'cleanup':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure auto-behaviors'), $lang);
				}
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setAutoBehaviorsConfig($token, ['cleanup' => $param1], $userId);
				return "✅ " . $l->t('Auto-cleanup completed items: %s', [$param1 ? $l->t('Enabled') : $l->t('Disabled')]);
				
			case 'summary':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure auto-behaviors'), $lang);
				}
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setAutoBehaviorsConfig($token, ['summary' => $param1], $userId);
				return "✅ " . $l->t('Auto-generate summaries: %s', [$param1 ? $l->t('Enabled') : $l->t('Disabled')]);
				
			case 'reset':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('reset auto-behaviors'), $lang);
				}
				$resetSuccess = $this->roomConfigService->resetAutoBehaviorsConfig($token);
				if ($resetSuccess) {
					return "✅ " . $l->t('Auto-behaviors reset to global defaults');
				} else {
					return "ℹ️ " . $l->t('No custom auto-behaviors configuration found') . " - " . $l->t('Using global defaults');
				}
				
			default:
				return "❌ " . $l->t('Unknown auto-behaviors action') . ": " . $action;
		}
	}

	/**
	 * Handle config emojis command - basic implementation
	 */
	private function handleConfigEmojis(string $token, string $action, $param1, $param2, ?array $actorData, string $lang): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		switch ($action) {
			case 'show':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('view detailed emojis configuration'), $lang);
				}
				$emojisConfig = $this->roomConfigService->getEmojisConfig($token);
				$output = "### 😀 " . $l->t('Custom Emojis Configuration') . "\n\n";
				$output .= "• **" . $l->t('Current agenda item') . "**: " . $emojisConfig['current_item'] . "\n";
				$output .= "• **" . $l->t('Completed agenda item') . "**: " . $emojisConfig['completed'] . "\n";
				$output .= "• **" . $l->t('Pending agenda item') . "**: " . $emojisConfig['pending'] . "\n";
				$output .= "• **" . $l->t('On time icon') . "**: " . $emojisConfig['on_time'] . "\n";
				$output .= "• **" . $l->t('Time warning icon') . "**: " . $emojisConfig['time_warning'] . "\n";
				
				if ($emojisConfig['source'] === 'room' && ($emojisConfig['configured_by'] ?? null)) {
					$configDate = date('Y-m-d H:i', $emojisConfig['configured_at'] ?? time());
					$output .= "• **" . $l->t('Configured by') . "**: " . $emojisConfig['configured_by'] . " (" . $configDate . ")\n";
				} else {
					$output .= "• **" . $l->t('Configured by') . "**: " . $l->t('Global defaults') . "\n";
				}
				
				$output .= "\n---\n";
				$output .= "💡 **" . $l->t('Available Commands') . ":**\n";
				$output .= "• `config emojis current-item 🎯` — " . $l->t('Set emoji for current agenda item') . "\n";
				$output .= "• `config emojis completed ✔️` — " . $l->t('Set emoji for completed items') . "\n";
				$output .= "• `config emojis pending ⏳` — " . $l->t('Set emoji for pending items') . "\n";
				$output .= "• `config emojis on-time 👌` — " . $l->t('Set emoji for on-time status') . "\n";
				$output .= "• `config emojis time-warning ⚠️` — " . $l->t('Set emoji for time warnings') . "\n";
				$output .= "• `config emojis reset` — " . $l->t('Reset emojis to global defaults') . "\n";
				$output .= "\n🔒 " . $l->t('Only moderators/owners can change custom emojis') . "\n";
				
				return $output;
				
			case 'set':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure custom emojis'), $lang);
				}
				$emojiKeyMap = [
					'current-item' => 'current_item',
					'completed' => 'completed',
					'pending' => 'pending',
					'on-time' => 'on_time',
					'time-warning' => 'time_warning',
				];
				if (!isset($emojiKeyMap[$param1])) {
					return "❌ " . $l->t('Unknown emoji type') . ": " . $param1;
				}
				$configKey = $emojiKeyMap[$param1];
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setEmojisConfig($token, [$configKey => $param2], $userId);
				return "✅ " . $l->t('Emoji for \"%s\" set to: %s', [str_replace('-', ' ', $param1), $param2]);
				
			case 'reset':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('reset custom emojis'), $lang);
				}
				$resetSuccess = $this->roomConfigService->resetEmojisConfig($token);
				if ($resetSuccess) {
					return "✅ " . $l->t('Custom emojis reset to global defaults');
				} else {
					return "ℹ️ " . $l->t('No custom emojis configuration found') . " - " . $l->t('Using global defaults');
				}
				
			default:
				return "❌ " . $l->t('Unknown emojis action') . ": " . $action;
		}
	}

	/**
	 * Handle config time command - time monitoring configuration
	 */
	private function handleConfigTime(string $token, string $action, $param1, $param2, ?array $actorData, string $lang): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		switch ($action) {
			case 'show':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('view detailed time monitoring configuration'), $lang);
				}
				$timeConfig = $this->roomConfigService->getTimeMonitoringConfig($token);
				$output = "### 🕙 " . $l->t('Time Monitoring Configuration') . "\n\n";
				$output .= "• **" . $l->t('Status') . "**: " . ($timeConfig['enabled'] ? "✅ " . $l->t('Enabled') : "❌ " . $l->t('Disabled')) . "\n";
				$output .= "• **" . $l->t('Warning threshold') . "**: " . round($timeConfig['warning_threshold'] * 100) . "% " . $l->t('of planned time') . "\n";
				$output .= "• **" . $l->t('Overtime threshold') . "**: " . round($timeConfig['overtime_threshold'] * 100) . "% " . $l->t('of planned time') . "\n";
				
				if ($timeConfig['source'] === 'room' && ($timeConfig['configured_by'] ?? null)) {
					$configDate = date('Y-m-d H:i', $timeConfig['configured_at'] ?? time());
					$output .= "• **" . $l->t('Configured by') . "**: " . $timeConfig['configured_by'] . " (" . $configDate . ")\n";
				} else {
					$output .= "• **" . $l->t('Configured by') . "**: " . $l->t('Global defaults') . "\n";
				}
				
				$output .= "\n---\n";
				$output .= "💡 **" . $l->t('Available Commands') . ":**\n";
				$output .= "• `config time enable` — " . $l->t('Enable time monitoring for this room') . "\n";
				$output .= "• `config time disable` — " . $l->t('Disable time monitoring for this room') . "\n";
				$output .= "• `config time warning 75` — " . $l->t('Set warning at 75% of planned time') . "\n";
				$output .= "• `config time overtime 120` — " . $l->t('Set overtime alert at 120% of planned time') . "\n";
				$output .= "• `config time thresholds 75 120` — " . $l->t('Set both warning and overtime thresholds') . "\n";
				$output .= "• `config time reset` — " . $l->t('Reset time monitoring to global defaults') . "\n";
				$output .= "\n🔒 " . $l->t('Only moderators/owners can change time monitoring settings') . "\n";
				
				return $output;
				
			case 'enable':
			case 'disable':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure time monitoring settings'), $lang);
				}
				$enabled = $action === 'enable';
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setRoomTimeMonitoringConfig($token, ['enabled' => $enabled], $userId);
				return "✅ " . $l->t('Time monitoring: %s', [$enabled ? $l->t('Enabled') : $l->t('Disabled')]);
				
			case 'warning':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure time monitoring settings'), $lang);
				}
				$threshold = ($param1 ?? 80) / 100.0;
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setRoomTimeMonitoringConfig($token, ['warning_threshold' => $threshold], $userId);
				return "✅ " . $l->t('Warning threshold set to: %d%%', [$param1]);
				
			case 'overtime':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure time monitoring settings'), $lang);
				}
				$threshold = ($param1 ?? 120) / 100.0;
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setRoomTimeMonitoringConfig($token, ['overtime_threshold' => $threshold], $userId);
				return "✅ " . $l->t('Overtime threshold set to: %d%%', [$param1]);
				
			case 'thresholds':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure time monitoring settings'), $lang);
				}
				$config = [
					'warning_threshold' => ($param1 ?? 80) / 100.0,
					'overtime_threshold' => ($param2 ?? 120) / 100.0
				];
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setRoomTimeMonitoringConfig($token, $config, $userId);
				return "✅ " . $l->t('Thresholds set to: %d%% warning, %d%% overtime', [$param1, $param2]);
				
			case 'reset':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure time monitoring settings'), $lang);
				}
				$resetSuccess = $this->roomConfigService->resetRoomConfig($token);
				if ($resetSuccess) {
					return "✅ " . $l->t('Time monitoring reset to global defaults');
				} else {
					return "ℹ️ " . $l->t('Room configuration not found') . " - " . $l->t('Using global defaults');
				}
				
			default:
				return "❌ " . $l->t('Unknown time monitoring action') . ": " . $action;
		}
	}

	/**
	 * Handle config response command - response behavior configuration
	 */
	private function handleConfigResponse(string $token, string $action, ?array $actorData, string $lang): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		switch ($action) {
			case 'show':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('view detailed response configuration'), $lang);
				}
				$responseConfig = $this->roomConfigService->getResponseConfig($token);
				$output = "### 💬 " . $l->t('Response Configuration') . "\n\n";
				if ($responseConfig['response_mode'] === 'minimal') {
					$output .= "• **" . $l->t('Response mode') . "**: 😴 " . $l->t('Minimal mode') . " — " . $l->t('Emoji reactions only') . "\n";
					$output .= "• **" . $l->t('Text responses') . "**: " . $l->t('Only for help, status, and call notifications') . "\n";
				} else {
					$output .= "• **" . $l->t('Response mode') . "**: 💬 " . $l->t('Normal mode') . " — " . $l->t('Full text responses') . "\n";
					$output .= "• **" . $l->t('Text responses') . "**: " . $l->t('For all commands and operations') . "\n";
				}
				
				if ($responseConfig['source'] === 'room' && ($responseConfig['configured_by'] ?? null)) {
					$configDate = date('Y-m-d H:i', $responseConfig['configured_at'] ?? time());
					$output .= "• **" . $l->t('Configured by') . "**: " . $responseConfig['configured_by'] . " (" . $configDate . ")\n";
				} else {
					$output .= "• **" . $l->t('Configured by') . "**: " . $l->t('Global defaults') . "\n";
				}
				
				$output .= "\n---\n";
				$output .= "💡 **" . $l->t('Available Commands') . ":**\n";
				$output .= "• `config response normal` — " . $l->t('Enable full text responses') . "\n";
				$output .= "• `config response minimal` — " . $l->t('Enable minimal responses (reduce notifications)') . "\n";
				$output .= "• `config response reset` — " . $l->t('Reset to default settings') . "\n";
				$output .= "\n🔒 " . $l->t('Only moderators/owners can change response settings') . "\n";
				
				return $output;
				
			case 'normal':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure response settings'), $lang);
				}
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setResponseConfig($token, ['response_mode' => 'normal'], $userId);
				return "✅ " . $l->t('Response mode set to: Normal (full responses)');
				
			case 'minimal':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure response settings'), $lang);
				}
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setResponseConfig($token, ['response_mode' => 'minimal'], $userId);
				return "✅ " . $l->t('Response mode set to: Minimal (reduced notifications)');
				
			case 'reset':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('reset response settings'), $lang);
				}
				$resetSuccess = $this->roomConfigService->resetResponseConfig($token);
				if ($resetSuccess) {
					return "✅ " . $l->t('Response settings reset to global defaults');
				} else {
					return "ℹ️ " . $l->t('No custom response configuration found') . " - " . $l->t('Using global defaults');
				}
				
			default:
				return "❌ " . $l->t('Unknown response action') . ": " . $action;
		}
	}
}
