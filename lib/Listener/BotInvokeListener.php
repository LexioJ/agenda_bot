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
			$bulkAgendaData = $this->agendaService->parseBulkAgendaItems($message);
			if ($bulkAgendaData) {
				$result = $this->agendaService->addBulkAgendaItems($token, $bulkAgendaData, $data['actor'] ?? null, $lang);
				$event->addAnswer($result['message'], true);
				return;
			}
			
			// Check if this is a single agenda item
			$agendaData = $this->agendaService->parseAgendaItem($message);
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
					// Auto-set first incomplete item as current
					$this->autoSetFirstIncompleteItemAsCurrent($token, $lang);
					
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
				
			$summary = $this->summaryService->generateAgendaSummary($token, $data['target']['name'], $lang);
				if ($summary !== null) {
					$event->addAnswer($summary['summary'], false);
					
					// Try to find and store the message ID of the summary we just sent
					// This enables more accurate reaction-based cleanup tracking
					$this->roomConfigService->findAndStoreRecentSummaryMessageId($token);
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
			
			// Use localized bot name to match how other bot messages appear - this will show as "Agenda Bot (Bot)"
			$botDisplayName = $l->t('Agenda bot') . ' (Bot)';
			
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
		
		return "## 🤖 **" . $l->t('Welcome to Agenda Bot!') . "**\n\n" .
			   $l->t("I'm here to help you manage your meeting agenda and track time.") . "\n\n" .
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
				if (!empty($actorData) && !$this->agendaService->getPermissionService()->isActorModerator($command['token'], $actorData)) {
					return $this->agendaService->getPermissionService()->getPermissionDeniedMessage($l->t('configure time monitoring settings'), $lang);
				}
				
				// Call reset method via RoomConfigService
				$reset = $this->agendaService->getRoomConfigService()->resetRoomConfig($command['token']);
				if ($reset) {
					return '✅ ' . $l->t('Room time monitoring reset to global defaults');
				} else {
					return 'ℹ️ ' . $l->t('Room configuration not found') . ' - ' . $l->t('This room is using global defaults. Use time commands to set room-specific configuration.');
				}

			case 'cleanup':
				return $this->agendaService->removeCompletedItems($command['token'], $actorData ?: null, $lang);

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
}
