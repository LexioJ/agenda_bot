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
					// Check response mode and convert to emoji reaction if minimal mode
					$reactionEmoji = $this->getEmojiReactionForCommand($command, $token);
					
					if ($reactionEmoji) {
						// Use emoji reaction instead of text response
						$this->sendEmojiReaction($event, $reactionEmoji);
					} else {
						// Use normal text response
						$event->addAnswer($response, true);
					}
				return;
				}
			}


			// Check if this is a bulk agenda format first (has priority over single items)
			$bulkAgendaData = $this->agendaService->parseBulkAgendaItems($message, $token);
			if ($bulkAgendaData) {
				$result = $this->agendaService->addBulkAgendaItems($token, $bulkAgendaData, $data['actor'] ?? null, $lang);
				
				// Check if we should use emoji reaction for bulk agenda in minimal mode
				$reactionEmoji = $this->getEmojiReactionForBulkAgenda($token, $result);
				if ($reactionEmoji) {
					$this->sendEmojiReaction($event, $reactionEmoji);
				} else {
					$event->addAnswer($result['message'], true);
				}
				return;
			}
			
			// Check if this is a single agenda item
			$agendaData = $this->agendaService->parseAgendaItem($message, $token);
			if ($agendaData) {
				$result = $this->agendaService->addAgendaItem($token, $agendaData, $data['actor'] ?? null, $lang);
				
				// Check if we should use emoji reaction for single agenda in minimal mode
				$reactionEmoji = $this->getEmojiReactionForSingleAgenda($token, $result);
				if ($reactionEmoji) {
					$this->sendEmojiReaction($event, $reactionEmoji);
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
					$cleanupResult = $this->agendaService->removeCompletedItems($token, null, $lang);
					if ($cleanupResult && !str_contains($cleanupResult, 'No completed items to remove')) {
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
		
		// For reaction-triggered cleanup, bypass permission check since:
		// 1. Only users with conversation access can react to messages
		// 2. Reactions are typically made by moderators/owners managing the meeting
		// 3. The reaction itself serves as user consent for cleanup
		$cleanupResult = $this->agendaService->removeCompletedItems($token, null, $roomLanguage);
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
				// Reset time monitoring config only (not entire room config)
				$l = $this->l10nFactory->get(Application::APP_ID, $lang);
				
				// Check moderator permissions
				if (!empty($actorData) && !$this->permissionService->isActorModerator($command['token'], $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure time monitoring settings'), $lang);
				}
				
				// Call specific time monitoring reset method
				$reset = $this->roomConfigService->resetTimeMonitoringConfig($command['token']);
				if ($reset) {
					return '✅ ' . $l->t('Room time monitoring reset to global defaults');
				} else {
					return 'ℹ️ ' . $l->t('No time monitoring configuration found') . ' - ' . $l->t('This room is using global defaults. Use time commands to set room-specific configuration.');
				}

			case 'cleanup':
				return $this->agendaService->removeCompletedItems($command['token'], $actorData ?: null, $lang);

			case 'reset':
				return $this->agendaService->resetAllItems($command['token'], $actorData ?: null, $lang);

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

	case 'config_template':
		return $this->handleConfigTemplate($command['token'], $command['action'] ?? 'show', $command['param1'] ?? null, $actorData ?: null, $lang);

	case 'config_export':
		return $this->handleConfigExport($command['token'], $actorData ?: null, $lang);

	case 'bulk_config':
	return $this->handleBulkConfig($command['token'], $command['message'], $actorData ?: null, $lang);

	case 'config_reset':
		return $this->handleConfigReset($command['token'], $command['section'] ?? null, $actorData ?: null, $lang);

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
		$templateConfig = $this->roomConfigService->getTemplateConfig($token);
		$timeConfig = $this->roomConfigService->getTimeMonitoringConfig($token);
		$responseConfig = $this->roomConfigService->getResponseConfig($token);
		$limitsConfig = $this->roomConfigService->getAgendaLimitsConfig($token);
		$autoConfig = $this->roomConfigService->getAutoBehaviorsConfig($token);
		$emojisConfig = $this->roomConfigService->getEmojisConfig($token);
		
		$output = "### ⚙️ " . $l->t('Room Configuration') . "\n";
		
		// Template section (first)
		$output .= "\n##### 📋 " . $l->t('Configuration Templates') . "\n";
		if ($templateConfig && isset($templateConfig['template_name'])) {
			$templateDisplayName = $this->getTemplateDisplayName($templateConfig['template_name'], $l);
			$output .= "• **" . $l->t('Active Template') . "**: ✅ " . $templateDisplayName . " (`" . $templateConfig['template_name'] . "`)\n";
			if ($templateConfig['configured_by'] ?? null) {
				$configDate = date('Y-m-d H:i', $templateConfig['applied_at'] ?? time());
				$output .= "• **" . $l->t('Configured by') . "**: ✏️ " . $templateConfig['configured_by'] . " (" . $configDate . ")\n";
			}
		} else {
			$output .= "• **" . $l->t('Active Template') . "**: ❌ " . $l->t('None - using individual settings') . "\n";
			$output .= "• **" . $l->t('Configured by') . "**: 🔧 " . $l->t('Individual configuration') . "\n";
		}
		$output .= "💡 " . $l->t('Use `config template list` for available templates') . "\n";
		
		// Time monitoring section
		$output .= "\n##### 🕙 " . $l->t('Time Monitoring') . "\n";
		$output .= "• **" . $l->t('Status') . "**: " . ($timeConfig['enabled'] ? "✅ " . $l->t('Enabled') : "❌ " . $l->t('Disabled')) . "\n";
		$output .= "• **" . $l->t('Warning threshold') . "**: " . round($timeConfig['warning_threshold'] * 100) . "% " . $l->t('of planned time') . "\n";
		$output .= "• **" . $l->t('Overtime threshold') . "**: " . round($timeConfig['overtime_threshold'] * 100) . "% " . $l->t('of planned time') . "\n";
		
		if ($timeConfig['source'] === 'room' && ($timeConfig['configured_by'] ?? null)) {
			$configDate = date('Y-m-d H:i', $timeConfig['configured_at'] ?? time());
			$output .= "• **" . $l->t('Configured by') . "**: ✏️ " . $timeConfig['configured_by'] . " (" . $configDate . ")\n";
		} else {
			$output .= "• **" . $l->t('Configured by') . "**: 🌐 " . $l->t('Global defaults') . "\n";
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
			$output .= "• **" . $l->t('Configured by') . "**: ✏️ " . ($responseConfig['configured_by'] ?? 'Unknown') . " (" . $configDate . ")\n";
		} else {
			$output .= "• **" . $l->t('Configured by') . "**: 🌐 " . $l->t('Global defaults') . "\n";
		}
		$output .= "💡 " . $l->t('Use `config response` for response configuration help') . "\n";
		
		// Agenda limits section
		$output .= "\n##### 🚧 " . $l->t('Agenda Limits') . "\n";
		$output .= "• **" . $l->t('Max total items') . "**: " . $limitsConfig['max_items'] . " " . $l->t('items') . "\n";
		$output .= "• **" . $l->t('Max bulk operation') . "**: " . $limitsConfig['max_bulk_items'] . " " . $l->t('items') . "\n";
		$output .= "• **" . $l->t('Default item duration') . "**: " . $limitsConfig['default_duration'] . " " . $l->t('minutes') . "\n";
		
		if ($limitsConfig['source'] === 'room' && ($limitsConfig['configured_by'] ?? null)) {
			$configDate = date('Y-m-d H:i', $limitsConfig['configured_at'] ?? time());
			$output .= "• **" . $l->t('Configured by') . "**: ✏️ " . $limitsConfig['configured_by'] . " (" . $configDate . ")\n";
		} else {
			$output .= "• **" . $l->t('Configured by') . "**: 🌐 " . $l->t('Global defaults') . "\n";
		}
		$output .= "💡 " . $l->t('Use `config limits` for limits configuration help') . "\n";
		
		// Auto-behaviors section
		$output .= "\n##### 🤖 " . $l->t('Auto-behaviors') . "\n";
		$output .= "• **" . $l->t('Start agenda on call') . "**: " . ($autoConfig['start_agenda'] ? "✅ " . $l->t('Enabled') : "❌ " . $l->t('Disabled')) . "\n";
		$output .= "• **" . $l->t('Auto-cleanup completed') . "**: " . ($autoConfig['cleanup'] ? "✅ " . $l->t('Enabled') : "❌ " . $l->t('Disabled')) . "\n";
		$output .= "• **" . $l->t('Generate summaries') . "**: " . ($autoConfig['summary'] ? "✅ " . $l->t('Enabled') : "❌ " . $l->t('Disabled')) . "\n";
		
		if ($autoConfig['source'] === 'room' && ($autoConfig['configured_by'] ?? null)) {
			$configDate = date('Y-m-d H:i', $autoConfig['configured_at'] ?? time());
			$output .= "• **" . $l->t('Configured by') . "**: ✏️ " . $autoConfig['configured_by'] . " (" . $configDate . ")\n";
		} else {
			$output .= "• **" . $l->t('Configured by') . "**: 🌐 " . $l->t('Global defaults') . "\n";
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
			$output .= "• **" . $l->t('Configured by') . "**: ✏️ " . $emojisConfig['configured_by'] . " (" . $configDate . ")\n";
		} else {
			$output .= "• **" . $l->t('Configured by') . "**: 🌐 " . $l->t('Global defaults') . "\n";
		}
		$output .= "💡 " . $l->t('Use `config emojis` for custom emojis configuration help') . "\n";
		
		$output .= "\n---\n";
		// Add export hint for moderators
		if (!empty($actorData) && $this->permissionService->isActorModerator($token, $actorData)) {
			$output .= "📋 " . $l->t('Use `config export` to copy this configuration to another room') . "\n";
		}
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
				$output = "### 🚧 " . $l->t('Agenda Limits Configuration') . "\n\n";
				$output .= "• **" . $l->t('Max total items') . "**: " . $limitsConfig['max_items'] . " " . $l->t('items') . "\n";
				$output .= "• **" . $l->t('Max bulk operation') . "**: " . $limitsConfig['max_bulk_items'] . " " . $l->t('items') . "\n";
				$output .= "• **" . $l->t('Default item duration') . "**: " . $limitsConfig['default_duration'] . " " . $l->t('minutes') . "\n";
				
				if ($limitsConfig['source'] === 'room' && ($limitsConfig['configured_by'] ?? null)) {
					$configDate = date('Y-m-d H:i', $limitsConfig['configured_at'] ?? time());
					$output .= "• **" . $l->t('Configured by') . "**: ✏️ " . $limitsConfig['configured_by'] . " (" . $configDate . ")\n";
				} else {
					$output .= "• **" . $l->t('Configured by') . "**: 🌐 " . $l->t('Global defaults') . "\n";
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
				// Get current value before changing
				$currentConfig = $this->roomConfigService->getAgendaLimitsConfig($token);
				$previousValue = $currentConfig['max_items'];
				
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setAgendaLimitsConfig($token, ['max_items' => $param1], $userId);
				
				// Get the actual stored value (after validation/clamping)
				$newConfig = $this->roomConfigService->getAgendaLimitsConfig($token);
				$actualValue = $newConfig['max_items'];
				
				$response = "";
				if ($actualValue != $param1) {
					$response = "⚠️ " . $l->t('Value clamped to valid range (5-100).') . " ";
				}
				
				if ($previousValue != $actualValue) {
					return $response . "✅ " . $l->t('Maximum total items set to: %d', [$actualValue]) . " *(" . $l->t('before: %d', [$previousValue]) . ")*";
				} else {
					return $response . "✅ " . $l->t('Maximum total items: %d', [$actualValue]) . " *(" . $l->t('unchanged') . ")*";
				}
				
			case 'max-bulk':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure agenda limits'), $lang);
				}
				// Get current value before changing
				$currentConfig = $this->roomConfigService->getAgendaLimitsConfig($token);
				$previousValue = $currentConfig['max_bulk_items'];
				
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setAgendaLimitsConfig($token, ['max_bulk_items' => $param1], $userId);
				
				// Get the actual stored value (after validation/clamping)
				$newConfig = $this->roomConfigService->getAgendaLimitsConfig($token);
				$actualValue = $newConfig['max_bulk_items'];
				
				$response = "";
				if ($actualValue != $param1) {
					$response = "⚠️ " . $l->t('Value clamped to valid range (3-50).') . " ";
				}
				
				if ($previousValue != $actualValue) {
					return $response . "✅ " . $l->t('Maximum bulk operation items set to: %d', [$actualValue]) . " *(" . $l->t('before: %d', [$previousValue]) . ")*";
				} else {
					return $response . "✅ " . $l->t('Maximum bulk operation items: %d', [$actualValue]) . " *(" . $l->t('unchanged') . ")*";
				}
				
			case 'default-duration':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure agenda limits'), $lang);
				}
				// Get current value before changing
				$currentConfig = $this->roomConfigService->getAgendaLimitsConfig($token);
				$previousValue = $currentConfig['default_duration'];
				
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setAgendaLimitsConfig($token, ['default_duration' => $param1], $userId);
				
				// Get the actual stored value (after validation/clamping)
				$newConfig = $this->roomConfigService->getAgendaLimitsConfig($token);
				$actualValue = $newConfig['default_duration'];
				
				$response = "";
				if ($actualValue != $param1) {
					$response = "⚠️ " . $l->t('Value clamped to valid range (1-120 minutes).') . " ";
				}
				
				if ($previousValue != $actualValue) {
					return $response . "✅ " . $l->t('Default item duration set to: %d minutes', [$actualValue]) . " *(" . $l->t('before: %d', [$previousValue]) . ")*";
				} else {
					return $response . "✅ " . $l->t('Default item duration: %d minutes', [$actualValue]) . " *(" . $l->t('unchanged') . ")*";
				}
				
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
					$output .= "• **" . $l->t('Configured by') . "**: ✏️ " . $autoConfig['configured_by'] . " (" . $configDate . ")\n";
				} else {
					$output .= "• **" . $l->t('Configured by') . "**: 🌐 " . $l->t('Global defaults') . "\n";
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
				// Get current value before changing
				$currentConfig = $this->roomConfigService->getAutoBehaviorsConfig($token);
				$previousValue = $currentConfig['start_agenda'];
				
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setAutoBehaviorsConfig($token, ['start_agenda' => $param1], $userId);
				
				if ($previousValue != $param1) {
					$newStatus = $param1 ? $l->t('Enabled') : $l->t('Disabled');
					$oldStatus = $previousValue ? $l->t('Enabled') : $l->t('Disabled');
					return "✅ " . $l->t('Auto-start agenda on call: %s', [$newStatus]) . " *(" . $l->t('before: %s', [$oldStatus]) . ")*";
				} else {
					$status = $param1 ? $l->t('Enabled') : $l->t('Disabled');
					return "✅ " . $l->t('Auto-start agenda on call: %s', [$status]) . " *(" . $l->t('unchanged') . ")*";
				}
				
			case 'cleanup':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure auto-behaviors'), $lang);
				}
				// Get current value before changing
				$currentConfig = $this->roomConfigService->getAutoBehaviorsConfig($token);
				$previousValue = $currentConfig['cleanup'];
				
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setAutoBehaviorsConfig($token, ['cleanup' => $param1], $userId);
				
				if ($previousValue != $param1) {
					$newStatus = $param1 ? $l->t('Enabled') : $l->t('Disabled');
					$oldStatus = $previousValue ? $l->t('Enabled') : $l->t('Disabled');
					return "✅ " . $l->t('Auto-cleanup completed items: %s', [$newStatus]) . " *(" . $l->t('before: %s', [$oldStatus]) . ")*";
				} else {
					$status = $param1 ? $l->t('Enabled') : $l->t('Disabled');
					return "✅ " . $l->t('Auto-cleanup completed items: %s', [$status]) . " *(" . $l->t('unchanged') . ")*";
				}
				
			case 'summary':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure auto-behaviors'), $lang);
				}
				// Get current value before changing
				$currentConfig = $this->roomConfigService->getAutoBehaviorsConfig($token);
				$previousValue = $currentConfig['summary'];
				
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setAutoBehaviorsConfig($token, ['summary' => $param1], $userId);
				
				if ($previousValue != $param1) {
					$newStatus = $param1 ? $l->t('Enabled') : $l->t('Disabled');
					$oldStatus = $previousValue ? $l->t('Enabled') : $l->t('Disabled');
					return "✅ " . $l->t('Generate summaries on call end: %s', [$newStatus]) . " *(" . $l->t('before: %s', [$oldStatus]) . ")*";
				} else {
					$status = $param1 ? $l->t('Enabled') : $l->t('Disabled');
					return "✅ " . $l->t('Generate summaries on call end: %s', [$status]) . " *(" . $l->t('unchanged') . ")*";
				}
				
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
					$output .= "• **" . $l->t('Configured by') . "**: ✏️ " . $emojisConfig['configured_by'] . " (" . $configDate . ")\n";
				} else {
					$output .= "• **" . $l->t('Configured by') . "**: 🌐 " . $l->t('Global defaults') . "\n";
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
				
				// Get current value before changing
				$currentConfig = $this->roomConfigService->getEmojisConfig($token);
				$previousEmoji = $currentConfig[$configKey];
				
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setEmojisConfig($token, [$configKey => $param2], $userId);
				
				if ($previousEmoji !== $param2) {
					return "✅ " . $l->t('Emoji for \"%s\" set to: %s', [str_replace('-', ' ', $param1), $param2]) . " *(" . $l->t('before: %s', [$previousEmoji]) . ")*";
				} else {
					return "✅ " . $l->t('Emoji for \"%s\": %s', [str_replace('-', ' ', $param1), $param2]) . " *(" . $l->t('unchanged') . ")*";
				}
				
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
					$output .= "• **" . $l->t('Configured by') . "**: ✏️ " . $timeConfig['configured_by'] . " (" . $configDate . ")\n";
				} else {
					$output .= "• **" . $l->t('Configured by') . "**: 🌐 " . $l->t('Global defaults') . "\n";
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
				// Get current value before changing
				$currentConfig = $this->roomConfigService->getTimeMonitoringConfig($token);
				$previousEnabled = $currentConfig['enabled'];
				
				$enabled = $action === 'enable';
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setRoomTimeMonitoringConfig($token, ['enabled' => $enabled], $userId);
				
				if ($previousEnabled != $enabled) {
					$newStatus = $enabled ? $l->t('Enabled') : $l->t('Disabled');
					$oldStatus = $previousEnabled ? $l->t('Enabled') : $l->t('Disabled');
					return "✅ " . $l->t('Time monitoring: %s', [$newStatus]) . " *(" . $l->t('before: %s', [$oldStatus]) . ")*";
				} else {
					$status = $enabled ? $l->t('Enabled') : $l->t('Disabled');
					return "✅ " . $l->t('Time monitoring: %s', [$status]) . " *(" . $l->t('unchanged') . ")*";
				}
				
			case 'warning':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure time monitoring settings'), $lang);
				}
				// Get current value before changing
				$currentConfig = $this->roomConfigService->getTimeMonitoringConfig($token);
				$previousThreshold = round($currentConfig['warning_threshold'] * 100);
				
				$threshold = ($param1 ?? 80) / 100.0;
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setRoomTimeMonitoringConfig($token, ['warning_threshold' => $threshold], $userId);
				
				// Get the actual stored value (after validation/clamping)
				$newConfig = $this->roomConfigService->getTimeMonitoringConfig($token);
				$actualThreshold = round($newConfig['warning_threshold'] * 100);
				
				$response = "";
				if ($actualThreshold != $param1) {
					$response = "⚠️ " . $l->t('Value clamped to valid range (10-95%%).') . " ";
				}
				
				if ($previousThreshold != $actualThreshold) {
					return $response . "✅ " . $l->t('Warning threshold set to: %d%%', [$actualThreshold]) . " *(" . $l->t('before: %d%%', [$previousThreshold]) . ")*";
				} else {
					return $response . "✅ " . $l->t('Warning threshold: %d%%', [$actualThreshold]) . " *(" . $l->t('unchanged') . ")*";
				}
				
			case 'overtime':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure time monitoring settings'), $lang);
				}
				// Get current value before changing
				$currentConfig = $this->roomConfigService->getTimeMonitoringConfig($token);
				$previousThreshold = round($currentConfig['overtime_threshold'] * 100);
				
				$threshold = ($param1 ?? 120) / 100.0;
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setRoomTimeMonitoringConfig($token, ['overtime_threshold' => $threshold], $userId);
				
				// Get the actual stored value (after validation/clamping)
				$newConfig = $this->roomConfigService->getTimeMonitoringConfig($token);
				$actualThreshold = round($newConfig['overtime_threshold'] * 100);
				
				$response = "";
				if ($actualThreshold != $param1) {
					$response = "⚠️ " . $l->t('Value clamped to valid range (105-300%%).') . " ";
				}
				
				if ($previousThreshold != $actualThreshold) {
					return $response . "✅ " . $l->t('Overtime threshold set to: %d%%', [$actualThreshold]) . " *(" . $l->t('before: %d%%', [$previousThreshold]) . ")*";
				} else {
					return $response . "✅ " . $l->t('Overtime threshold: %d%%', [$actualThreshold]) . " *(" . $l->t('unchanged') . ")*";
				}
				
			case 'thresholds':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure time monitoring settings'), $lang);
				}
				// Get current values before changing
				$currentConfig = $this->roomConfigService->getTimeMonitoringConfig($token);
				$previousWarning = round($currentConfig['warning_threshold'] * 100);
				$previousOvertime = round($currentConfig['overtime_threshold'] * 100);
				
				$config = [
					'warning_threshold' => ($param1 ?? 80) / 100.0,
					'overtime_threshold' => ($param2 ?? 120) / 100.0
				];
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setRoomTimeMonitoringConfig($token, $config, $userId);
				
				// Get the actual stored values (after validation/clamping)
				$newConfig = $this->roomConfigService->getTimeMonitoringConfig($token);
				$actualWarning = round($newConfig['warning_threshold'] * 100);
				$actualOvertime = round($newConfig['overtime_threshold'] * 100);
				
				$response = "";
				if ($actualWarning != $param1 || $actualOvertime != $param2) {
					$response = "⚠️ " . $l->t('Values clamped to valid ranges (warning: 10-95%%, overtime: 105-300%%).') . " ";
				}
				
				if ($previousWarning != $actualWarning || $previousOvertime != $actualOvertime) {
					return $response . "✅ " . $l->t('Thresholds set to: %d%% warning, %d%% overtime', [$actualWarning, $actualOvertime]) . " *(" . $l->t('before: %d%%/%d%%', [$previousWarning, $previousOvertime]) . ")*";
				} else {
					return $response . "✅ " . $l->t('Thresholds set to: %d%% warning, %d%% overtime', [$actualWarning, $actualOvertime]) . " *(" . $l->t('unchanged') . ")*";
				}
				
			case 'reset':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure time monitoring settings'), $lang);
				}
				$resetSuccess = $this->roomConfigService->resetTimeMonitoringConfig($token);
				if ($resetSuccess) {
					return "✅ " . $l->t('Time monitoring reset to global defaults');
				} else {
					return "ℹ️ " . $l->t('No time monitoring configuration found') . " - " . $l->t('Using global defaults');
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
					$output .= "• **" . $l->t('Configured by') . "**: ✏️ " . $responseConfig['configured_by'] . " (" . $configDate . ")\n";
				} else {
					$output .= "• **" . $l->t('Configured by') . "**: 🌐 " . $l->t('Global defaults') . "\n";
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
				// Get current value before changing
				$currentConfig = $this->roomConfigService->getResponseConfig($token);
				$previousMode = $currentConfig['response_mode'];
				
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setResponseConfig($token, ['response_mode' => 'normal'], $userId);
				
				if ($previousMode !== 'normal') {
					$oldModeText = $previousMode === 'minimal' ? $l->t('Minimal (reduced notifications)') : $l->t('Normal (full responses)');
					return "✅ " . $l->t('Response mode set to: Normal (full responses)') . " *(" . $l->t('before: %s', [$oldModeText]) . ")*";
				} else {
					return "✅ " . $l->t('Response mode set to: Normal (full responses)') . " *(" . $l->t('unchanged') . ")*";
				}
				
			case 'minimal':
				if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
					return $this->permissionService->getPermissionDeniedMessage($l->t('configure response settings'), $lang);
				}
				// Get current value before changing
				$currentConfig = $this->roomConfigService->getResponseConfig($token);
				$previousMode = $currentConfig['response_mode'];
				
				$userId = $this->extractUserIdFromActorData($actorData);
				$this->roomConfigService->setResponseConfig($token, ['response_mode' => 'minimal'], $userId);
				
				if ($previousMode !== 'minimal') {
					$oldModeText = $previousMode === 'minimal' ? $l->t('Minimal (reduced notifications)') : $l->t('Normal (full responses)');
					return "✅ " . $l->t('Response mode set to: Minimal (reduced notifications)') . " *(" . $l->t('before: %s', [$oldModeText]) . ")*";
				} else {
					return "✅ " . $l->t('Response mode set to: Minimal (reduced notifications)') . " *(" . $l->t('unchanged') . ")*";
				}
				
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

	/**
	 * Handle bulk configuration commands with dynamic grouped output
	 */
	private function handleBulkConfig(string $token, string $message, ?array $actorData, string $lang): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Parse the bulk commands
		$commands = $this->commandParser->parseBulkCommands($message, $token);
		
		if (empty($commands)) {
			return "❌ " . $l->t('No valid configuration commands found');
		}
		
		// Dynamic groups based on config commands
		$groups = [];
		$errors = [];
		$successCount = 0;
		$errorCount = 0;
		
		// Process each command sequentially
		foreach ($commands as $cmd) {
			if ($cmd['command'] === 'invalid') {
				$errorCount++;
				$errorMessage = $cmd['error'];
				
				// Add suggestion if available
				if (!empty($cmd['suggestion'])) {
					$errorMessage .= " — " . $cmd['suggestion'];
				}
				
				$errors[] = "❌ " . $l->t('Line %d', [$cmd['line_number']]) . ": " . $errorMessage . "\n   `" . $cmd['original_line'] . "`";
				continue;
			}
			
			try {
				// Handle individual command
				$response = $this->handleCommand($cmd, $actorData, $lang);
				
				if ($response !== null && !str_starts_with($response, '❌')) {
					$successCount++;
					$cleanResponse = trim(str_replace(['✅', '❌', 'ℹ️'], '', $response));
					
					// Extract group name from command or original line
					$groupName = $this->extractGroupName($cmd);
					$groups[$groupName][] = '• ' . $cleanResponse;
				} else {
					$errorCount++;
					$errorMessage = $response ? trim(str_replace(['✅', '❌', 'ℹ️'], '', $response)) : $l->t('Command failed');
					$errors[] = "❌ " . $l->t('Line %d', [$cmd['line_number']]) . ": " . $errorMessage;
				}
			} catch (\Exception $e) {
				$errorCount++;
				$errors[] = "❌ " . $l->t('Line %d', [$cmd['line_number']]) . ": " . $l->t('Error processing command') . " - " . $e->getMessage();
			}
		}
		
		// Build grouped output
		return $this->buildDynamicGroupOutput($groups, $errors, $successCount, $errorCount, $l);
	}
	
	/**
	 * Extract group name from command structure
	 */
	private function extractGroupName(array $cmd): string {
		// For config_xxx commands, extract the xxx part
		if (str_starts_with($cmd['command'], 'config_')) {
			return substr($cmd['command'], 7); // Remove 'config_' prefix
		}
		
		// For legacy time commands, group under 'time'
		if (str_starts_with($cmd['command'], 'time_')) {
			return 'time';
		}
		
		// Extract from original line - look for "config [groupname]"
		if (isset($cmd['original_line']) && preg_match('/^config\s+(\w+)/', $cmd['original_line'], $matches)) {
			return $matches[1];
		}
		
		// Fallback
		return 'other';
	}
	
	/**
	 * Build dynamic group output with proper localized headers
	 */
	private function buildDynamicGroupOutput(array $groups, array $errors, int $successCount, int $errorCount, $l): string {
		$totalCommands = $successCount + $errorCount;
		$output = "✅ **" . $l->t('Bulk Configuration Applied') . "** (" . $l->t('%d commands processed', [$totalCommands]) . ")\n\n";
		
		// Group name to localized title mapping
		$groupTitles = [
			'limits' => $l->t('Agenda Limits'),
			'time' => $l->t('Time Monitoring'),
			'auto' => $l->t('Auto-Behaviors'),
			'response' => $l->t('Response Mode'),
			'emojis' => $l->t('Custom Emojis'),
			'other' => $l->t('Other Settings')
		];
		
		// Display groups in preferred order
		$preferredOrder = ['time', 'response', 'limits', 'auto', 'emojis', 'other'];
		
		foreach ($preferredOrder as $groupName) {
			if (!empty($groups[$groupName])) {
				$title = $groupTitles[$groupName] ?? ucfirst($groupName);
				$output .= "**" . $title . "**:\n" . implode("\n", $groups[$groupName]) . "\n\n";
			}
		}
		
		// Add any remaining groups not in preferred order
		foreach ($groups as $groupName => $items) {
			if (!in_array($groupName, $preferredOrder) && !empty($items)) {
				$title = $groupTitles[$groupName] ?? ucfirst($groupName);
				$output .= "**" . $title . "**:\n" . implode("\n", $items) . "\n\n";
			}
		}
		
		// Show errors if any
		if (!empty($errors)) {
			$output .= "**" . $l->t('Errors') . "**:\n" . implode("\n", $errors) . "\n\n";
		}
		
		// Summary
		if ($errorCount === 0) {
			$output .= "🎉 " . $l->t('All configuration commands completed successfully!');
		} elseif ($successCount > 0) {
			$output .= "⚠️ " . $l->t('Mixed results: %d succeeded, %d failed', [$successCount, $errorCount]);
			if ($errorCount > 0) {
				$output .= "\n💡 " . $l->t('Tip: Use `config show` to view current configuration and `agenda help` for command syntax');
			}
		} else {
			$output .= "❌ " . $l->t('All configuration commands failed');
			$output .= "\n💡 " . $l->t('Tip: Use `config show` to view current configuration and `agenda help` for command syntax');
		}
		
		return $output;
	}
	
	/**
	 * Handle config template commands (standalone, not part of bulk config)
	 */
	private function handleConfigTemplate(string $token, string $action, ?string $templateName, ?array $actorData, string $lang): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		switch ($action) {
			case 'list':
				return $this->getTemplateList($l);
				
			case 'show':
				return $this->getTemplateConfiguration($token, $l, $lang);
				
			case 'apply':
				if (!$templateName) {
					return "❌ " . $l->t('Template name required') . ". " . $l->t('Use `config template list` to see available templates');
				}
				if ($templateName === 'none') {
					return $this->resetTemplate($token, $actorData, $l, $lang);
				}
				return $this->applyConfigurationTemplate($token, $templateName, $actorData, $l, $lang);
				
			default:
				return "❌ " . $l->t('Unknown template action') . ": " . $action;
		}
	}
	
	/**
	 * Get list of available configuration templates in simple list format
	 */
	private function getTemplateList($l): string {
		$output = "### 📋 " . $l->t('Available Configuration Templates') . "\n\n";
		
		$templates = $this->getTemplateCommandLists();
		$descriptions = [
			'formal' => $l->t('Professional business meetings with structured time management'),
			'jour-fixe' => $l->t('Regular recurring meetings with balanced settings'),
			'workshop' => $l->t('Extended collaborative sessions with flexible timing'),
			'brainstorm' => $l->t('Creative ideation meetings with minimal constraints'),
			'training' => $l->t('Educational sessions with structured progress tracking')
		];
		
		$counter = 1;
		foreach ($templates as $name => $commandList) {
			$displayName = $this->getTemplateDisplayName($name, $l);
			$description = $descriptions[$name];
			$command = "`config template $name`";
			
			// Build settings summary from commands
			$settingsSummary = $this->buildSettingsSummaryFromCommands($commandList, $l);
			
			// Create entry
			$output .= "**$counter. $displayName**\n";
			$output .= "📝 *$description*\n";
			$output .= "⚙️ $settingsSummary\n";
			$output .= "💻 $command\n\n";
			
			$counter++;
		}
		
		// Add the reset option
		$output .= "**$counter. " . $l->t('Reset Template') . "**\n";
		$output .= "📝 *" . $l->t('Remove current template configuration') . "*\n";
		$output .= "⚙️ " . $l->t('Clears template') . "\n";
		$output .= "💻 `config template none`\n\n";
		
		return $output;
	}
	
	/**
	 * Reset/remove template configuration
	 */
	private function resetTemplate(string $token, ?array $actorData, $l, string $lang): string {
		// Check moderator permissions
		if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
			return $this->permissionService->getPermissionDeniedMessage($l->t('reset template configuration'), $lang);
		}
		
		// Reset template configuration
		$reset = $this->roomConfigService->resetTemplateConfig($token);
		if ($reset) {
			return '✅ ' . $l->t('Template configuration reset - room now uses individual settings');
		} else {
			return 'ℹ️ ' . $l->t('No active template found to reset');
		}
	}
	
	/**
	 * Get current template configuration status (similar to other config commands)
	 */
	private function getTemplateConfiguration(string $token, $l, string $lang): string {
		$templateConfig = $this->roomConfigService->getTemplateConfig($token);
		$output = "### 📋 " . $l->t('Configuration Templates') . "\n\n";
		
		if ($templateConfig && isset($templateConfig['template_name'])) {
			$templateDisplayName = $this->getTemplateDisplayName($templateConfig['template_name'], $l);
			$output .= "• **" . $l->t('Active Template') . "**: " . $templateDisplayName . "\n";
			
			if ($templateConfig['configured_by'] ?? null) {
				$configDate = date('Y-m-d H:i', $templateConfig['applied_at'] ?? time());
				$output .= "• **" . $l->t('Configured by') . "**: ✏️ " . $templateConfig['configured_by'] . " (" . $configDate . ")\n";
			} else {
				$output .= "• **" . $l->t('Configured by') . "**: 🌐 " . $l->t('Global defaults') . "\n";
			}
		} else {
			$output .= "• **" . $l->t('Active Template') . "**: " . $l->t('None - using individual settings') . "\n";
			$output .= "• **" . $l->t('Configured by') . "**: " . $l->t('Individual configuration') . "\n";
		}
		
		$output .= "\n---\n";
		$output .= "💡 **" . $l->t('Available Commands') . ":**\n";
		$output .= "• `config template list` — " . $l->t('Show all available templates') . "\n";
		$output .= "• `config template formal` — " . $l->t('Apply a specific template') . "\n";
		$output .= "• `config template none` — " . $l->t('Remove current template configuration') . "\n";
		$output .= "\n🔒 " . $l->t('Only moderators/owners can apply configuration templates') . "\n";
		$output .= "\n💡 " . $l->t('Use `config template list` for available templates') . "\n";
		
		return $output;
	}
	
	/**
	 * Get template help and usage information
	 */
	private function getTemplateHelp($l): string {
		return "### 📋 " . $l->t('Configuration Templates') . "\n\n" .
			   "**" . $l->t('Usage') . ":**\n" .
			   "• `config template list` - " . $l->t('Show all available templates') . "\n" .
			   "• `config template [name]` - " . $l->t('Apply a specific template') . "\n\n" .
			   "💡 " . $l->t('Use `config template list` to see available templates and their descriptions');
	}
	
	/**
	 * Apply a configuration template to the room by converting it to bulk commands
	 */
	private function applyConfigurationTemplate(string $token, string $templateName, ?array $actorData, $l, string $lang): string {
		// Check moderator permissions
		if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
			return $this->permissionService->getPermissionDeniedMessage($l->t('apply configuration templates'), $lang);
		}
		
		$templates = $this->getTemplateCommandLists();
		
		if (!isset($templates[$templateName])) {
			return "❌ " . $l->t('Unknown template') . ": $templateName. " . $l->t('Use `config template list` to see available templates');
		}
		
		// Get the command list for this template
		$commandList = $templates[$templateName];
		
		// Convert command list to bulk configuration message
		$bulkMessage = implode("\n", $commandList);
		
		// Use existing bulk config handler with proper language
		$result = $this->handleBulkConfig($token, $bulkMessage, $actorData, $lang);
		
		// Store the template information after successful application
		$userId = $this->extractUserIdFromActorData($actorData);
		$this->roomConfigService->setTemplateConfig($token, $templateName, $userId);
		
		// Modify the bulk config output to show it was from a template
		$templateDisplayName = $this->getTemplateDisplayName($templateName, $l);
		$result = str_replace(
			'✅ **' . $l->t('Bulk Configuration Applied') . '**',
			'✅ **' . $l->t('Template Applied') . '**: ' . $templateDisplayName,
			$result
		);
		
		return $result;
	}
	
	/**
	 * Build settings summary from command list for template display
	 */
	private function buildSettingsSummaryFromCommands(array $commandList, $l): string {
		// Initialize defaults
		$timeEnabled = false;
		$timeThresholds = [80, 120];
		$responseMode = 'normal';
		$maxItems = 50;
		$maxBulk = 25;
		$defaultDuration = 10;
		$startAgenda = false;
		$autoCleanup = false;
		$autoSummary = false;
		
		// Parse commands to extract all settings
		foreach ($commandList as $command) {
			if (str_contains($command, 'config time enable')) {
				$timeEnabled = true;
			} elseif (str_contains($command, 'config time disable')) {
				$timeEnabled = false;
			} elseif (preg_match('/config time thresholds (\d+) (\d+)/', $command, $matches)) {
				$timeThresholds = [(int)$matches[1], (int)$matches[2]];
			} elseif (str_contains($command, 'config response minimal')) {
				$responseMode = 'minimal';
			} elseif (str_contains($command, 'config response normal')) {
				$responseMode = 'normal';
			} elseif (preg_match('/config limits max-items (\d+)/', $command, $matches)) {
				$maxItems = (int)$matches[1];
			} elseif (preg_match('/config limits max-bulk (\d+)/', $command, $matches)) {
				$maxBulk = (int)$matches[1];
			} elseif (preg_match('/config limits default-duration (\d+)/', $command, $matches)) {
				$defaultDuration = (int)$matches[1];
			} elseif (str_contains($command, 'config auto start-agenda enable')) {
				$startAgenda = true;
			} elseif (str_contains($command, 'config auto cleanup enable')) {
				$autoCleanup = true;
			} elseif (str_contains($command, 'config auto summary enable')) {
				$autoSummary = true;
			}
		}
		
		// Build comprehensive settings summary with proper section names
		$parts = [];
		
		// Time monitoring section
		if ($timeEnabled) {
			$parts[] = $l->t('Time: Enabled+%d%%/%d%%', $timeThresholds);
		} else {
			$parts[] = $l->t('Time: Disabled');
		}
		
		// Response section
		$parts[] = $l->t('Response: %s', [ucfirst($responseMode)]);
		
		// Limits section (show comprehensive limits info)
		$limitsInfo = [];
		if ($maxItems != 50) $limitsInfo[] = $maxItems . ' items';
		if ($maxBulk != 25) $limitsInfo[] = $maxBulk . ' bulk';
		if ($defaultDuration != 10) $limitsInfo[] = $defaultDuration . 'min default';
		
		if (!empty($limitsInfo)) {
			$parts[] = $l->t('Limits: %s', [implode('+', $limitsInfo)]);
		}
		
		// Auto-behaviors section
		$autoIndicators = [];
		if ($startAgenda) $autoIndicators[] = 'Start';
		if ($autoCleanup) $autoIndicators[] = 'Clean';
		if ($autoSummary) $autoIndicators[] = 'Summary';
		
		if (!empty($autoIndicators)) {
			$parts[] = $l->t('Auto: %s', [implode('+', $autoIndicators)]);
		} else {
			// Check if any auto-behaviors are explicitly disabled
			$disabledAuto = [];
			foreach ($commandList as $command) {
				if (str_contains($command, 'config auto') && str_contains($command, 'disable')) {
					if (str_contains($command, 'start-agenda')) $disabledAuto[] = 'Start';
					if (str_contains($command, 'cleanup')) $disabledAuto[] = 'Clean';
					if (str_contains($command, 'summary')) $disabledAuto[] = 'Summary';
				}
			}
			if (!empty($disabledAuto)) {
				$parts[] = $l->t('Auto: Disabled(%s)', [implode('+', $disabledAuto)]);
			}
		}
		
		// Extract custom emojis from commands
		$emojis = [];
		$emojiOrder = ['current-item', 'completed', 'pending', 'on-time', 'time-warning'];
		foreach ($emojiOrder as $type) {
			foreach ($commandList as $command) {
				if (preg_match('/config emojis ' . preg_quote($type, '/') . ' (.+)/', $command, $matches)) {
					$emojis[] = trim($matches[1]);
					break;
				}
			}
		}
		if (!empty($emojis)) {
			$parts[] = $l->t('Emojis: %s', [implode(', ', $emojis)]);
		}
		
		return implode(' • ', $parts);
	}
	
	/**
	 * Get template command lists (templates defined as bulk config commands)
	 * 
	 * TODO: Move these hardcoded templates into app_config so admins can adapt/modify
	 * the executed commands using occ config commands for better customization
	 */
	private function getTemplateCommandLists(): array {
		return [
			'formal' => [
				'config time enable',
				'config time thresholds 75 105',
				'config response normal',
				'config limits max-items 12',
				'config limits max-bulk 12',
				'config limits default-duration 10',
				'config auto start-agenda enable',
				'config auto cleanup disable',
				'config auto summary enable',
				// Professional, clear status indicators
				'config emojis current-item 📋',
				'config emojis completed ✅',
				'config emojis pending ⏸️',
				'config emojis on-time 👌',
				'config emojis time-warning 🚨'
			],
			'jour-fixe' => [
				'config time enable',
				'config time thresholds 80 115',
				'config response normal',
				'config limits max-items 10',
				'config limits max-bulk 10',
				'config limits default-duration 15',
				'config auto start-agenda enable',
				'config auto cleanup enable',
				'config auto summary enable',
				// Balanced, routine-friendly indicators
				'config emojis current-item 👉',
				'config emojis completed ✔️',
				'config emojis pending 📅',
				'config emojis on-time 👍',
				'config emojis time-warning ⏰'
			],
			'workshop' => [
				'config time enable',
				'config time thresholds 85 125',
				'config response minimal',
				'config limits max-items 20',
				'config limits max-bulk 10',
				'config limits default-duration 20',
				'config auto start-agenda enable',
				'config auto cleanup disable',
				'config auto summary enable',
				// Dynamic, engaging, collaborative feel
				'config emojis current-item 🎯',
				'config emojis completed 🎉',
				'config emojis pending 🔧',
				'config emojis on-time 💪',
				'config emojis time-warning 📢'
			],
			'brainstorm' => [
				'config time disable',
				'config response minimal',
				'config limits max-items 25',
				'config limits max-bulk 15',
				'config limits default-duration 15',
				'config auto start-agenda disable',
				'config auto cleanup disable',
				'config auto summary disable',
				// Creative, inspirational, fun atmosphere
				'config emojis current-item 💭',
				'config emojis completed 🌟',
				'config emojis pending 💡',
				'config emojis on-time 🚀',
				'config emojis time-warning 🎪'
			],
			'training' => [
				'config time enable',
				'config time thresholds 70 110',
				'config response normal',
				'config limits max-items 30',
				'config limits max-bulk 15',
				'config limits default-duration 12',
				'config auto start-agenda enable',
				'config auto cleanup disable',
				'config auto summary enable',
				// Learning-focused, educational progress
				'config emojis current-item 📖',
				'config emojis completed 🎓',
				'config emojis pending 📝',
				'config emojis on-time ✨',
				'config emojis time-warning 📚'
			]
		];
	}
	
	/**
	 * Get localized display name for template
	 */
	private function getTemplateDisplayName(string $templateName, $l): string {
		$displayNames = [
			'formal' => $l->t('Formal Business Meeting'),
			'jour-fixe' => $l->t('Regular Jour Fixe'),
			'workshop' => $l->t('Collaborative Workshop'),
			'brainstorm' => $l->t('Creative Brainstorming'),
			'training' => $l->t('Educational Training')
		];
		
	return $displayNames[$templateName] ?? ucfirst($templateName);
	}

	/**
	 * Handle config reset command - reset all or specific configuration areas
	 */
	private function handleConfigReset(string $token, ?string $section, ?array $actorData, string $lang): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Check moderator permissions
		if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
			return $this->permissionService->getPermissionDeniedMessage($l->t('reset room configuration'), $lang);
		}
		
		// If specific section requested, handle individual section reset
		if ($section) {
			switch ($section) {
				case 'time':
					return $this->handleConfigTime($token, 'reset', null, null, $actorData, $lang);
				case 'response':
					return $this->handleConfigResponse($token, 'reset', $actorData, $lang);
				case 'limits':
					return $this->handleConfigLimits($token, 'reset', null, $actorData, $lang);
				case 'auto':
					return $this->handleConfigAuto($token, 'reset', null, $actorData, $lang);
				case 'emojis':
					return $this->handleConfigEmojis($token, 'reset', null, null, $actorData, $lang);
				case 'template':
					return $this->resetTemplate($token, $actorData, $l, $lang);
				default:
					return "❌ " . $l->t('Unknown configuration section') . ": $section. " . $l->t('Available sections: time, response, limits, auto, emojis, template');
			}
		}
		
		// Global reset - reset all configuration areas
		$results = [];
		$errors = [];
		$successCount = 0;
		$errorCount = 0;
		
		// List of configuration areas to reset
		$configAreas = [
			'template' => $l->t('Configuration Templates'),
			'time' => $l->t('Time Monitoring'),
			'response' => $l->t('Response Mode'),
			'limits' => $l->t('Agenda Limits'),
			'auto' => $l->t('Auto-Behaviors'),
			'emojis' => $l->t('Custom Emojis')
		];
		
		// Reset each configuration area
		foreach ($configAreas as $area => $title) {
			try {
				switch ($area) {
					case 'template':
						$resetResult = $this->roomConfigService->resetTemplateConfig($token);
						break;
					case 'time':
						$resetResult = $this->roomConfigService->resetTimeMonitoringConfig($token);
						break;
					case 'response':
						$resetResult = $this->roomConfigService->resetResponseConfig($token);
						break;
					case 'limits':
						$resetResult = $this->roomConfigService->resetAgendaLimitsConfig($token);
						break;
					case 'auto':
						$resetResult = $this->roomConfigService->resetAutoBehaviorsConfig($token);
						break;
					case 'emojis':
						$resetResult = $this->roomConfigService->resetEmojisConfig($token);
						break;
					default:
						$resetResult = false;
				}
				
				if ($resetResult) {
					$results[] = "• $title: " . $l->t('Reset to global defaults');
					$successCount++;
				} else {
					$results[] = "• $title: " . $l->t('No custom configuration found') . " (" . $l->t('already using defaults') . ")";
					$successCount++; // Count as success since it's already at defaults
				}
			} catch (\Exception $e) {
				$errors[] = "• $title: " . $l->t('Reset failed') . " - " . $e->getMessage();
				$errorCount++;
			}
		}
		
		// Build output
		$totalAreas = count($configAreas);
		$output = "✅ **" . $l->t('Global Configuration Reset') . "** (" . $l->t('%d areas processed', [$totalAreas]) . ")\n\n";
		
		if (!empty($results)) {
			$output .= implode("\n", $results) . "\n";
		}
		
		if (!empty($errors)) {
			$output .= "\n**" . $l->t('Errors') . ":**\n" . implode("\n", $errors) . "\n";
		}
		
		// Summary
		if ($errorCount === 0) {
			$output .= "\n🎉 " . $l->t('All room configurations reset to global defaults!');
		} elseif ($successCount > 0) {
			$output .= "\n⚠️ " . $l->t('Mixed results: %d succeeded, %d failed', [$successCount, $errorCount]);
		} else {
			$output .= "\n❌ " . $l->t('All configuration resets failed');
		}
		
	return $output;
	}

	/**
	 * Get emoji reaction for command in minimal response mode
	 * Returns null if normal text response should be used
	 */
	private function getEmojiReactionForCommand(array $command, string $token): ?string {
		// Check if room is in minimal response mode
		$responseConfig = $this->roomConfigService->getResponseConfig($token);
		
		if ($responseConfig['response_mode'] !== 'minimal') {
			return null; // Use normal text responses
		}
		
		// Get custom emojis for this room
		$emojis = $this->roomConfigService->getEmojisConfig($token);
		
		// Commands that should always use text responses even in minimal mode
		$alwaysTextCommands = [
			'status', 'help', 'config_show', 'config_time', 'config_response', 
			'config_limits', 'config_auto', 'config_emojis', 'config_template',
			'config_export', 'bulk_config', 'config_reset'
		];
		
		if (in_array($command['command'], $alwaysTextCommands)) {
			return null; // Always use text for these commands
		}
		
		// Map commands to appropriate emoji reactions
		switch ($command['command']) {
			case 'complete':
			case 'next':
				return $emojis['completed']; // Use completed emoji
				
			case 'reopen':
				return $emojis['pending']; // Use pending emoji
				
			case 'remove':
			case 'cleanup':
				return '🧹'; // Cleanup emoji
				
			case 'clear':
			case 'reset':
				return '🔄'; // Reset emoji
				
			case 'reorder':
			case 'move':
			case 'swap':
				return '🔀'; // Shuffle emoji
				
			case 'change':
				return '✏️'; // Edit emoji
				
			default:
				return '👍'; // Generic success emoji
		}
	}
	
	/**
	 * Send emoji reaction to user's message
	 */
	private function sendEmojiReaction(BotInvokeEvent $event, string $emoji): void {
		try {
			// Send bot identifier emoji first (🤖)
			$event->addReaction('🤖');
			
			// Send the action-specific emoji
			$event->addReaction($emoji);
			
		} catch (\Exception $e) {
			// If reaction fails, silently continue - don't break the bot
			$this->logger->debug('Failed to send emoji reaction: ' . $e->getMessage());
		}
	}
	
	/**
	 * Get emoji reaction for bulk agenda items in minimal response mode
	 */
	private function getEmojiReactionForBulkAgenda(string $token, array $result): ?string {
		$responseConfig = $this->roomConfigService->getResponseConfig($token);
		if ($responseConfig['response_mode'] !== 'minimal') {
			return null;
		}
		
		if ($result['success']) {
			return '📝'; // Memo/list emoji for bulk agenda
		} else {
			return '❌'; // Cross mark for errors
		}
	}
	
	/**
	 * Get emoji reaction for single agenda item in minimal response mode
	 */
	private function getEmojiReactionForSingleAgenda(string $token, array $result): ?string {
		$responseConfig = $this->roomConfigService->getResponseConfig($token);
		if ($responseConfig['response_mode'] !== 'minimal') {
			return null;
		}
		
		if ($result['success']) {
			return '➕'; // Plus sign for single agenda item
		} else {
		return '❌'; // Cross mark for errors
		}
	}

	/**
	 * Handle config export command - export room configuration as bulk commands
	 */
	private function handleConfigExport(string $token, ?array $actorData, string $lang): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		
		// Check moderator permissions (view configuration requires moderator permissions)
		if (!empty($actorData) && !$this->permissionService->isActorModerator($token, $actorData)) {
			return $this->permissionService->getPermissionDeniedMessage($l->t('export room configuration'), $lang);
		}
		
		return $this->agendaService->exportConfiguration($token, $lang);
	}
}
