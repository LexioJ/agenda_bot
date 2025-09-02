<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Service;

use OCA\AgendaBot\AppInfo\Application;
use OCA\Talk\Manager;
use OCA\Talk\Participant;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

class PermissionService {
	public function __construct(
		private Manager $talkManager,
		private LoggerInterface $logger,
		private IFactory $l10nFactory,
	) {
	}

	/**
	 * Check if a user has moderator permissions in a room
	 */
	public function isUserModerator(string $token, string $userId): bool {
		try {
			// Get the room for this token
			$room = $this->talkManager->getRoomByToken($token);
			if (!$room) {
				$this->logger->warning('Could not find room for token: ' . $token);
				return false;
			}

			// Get participant for this user
			$participant = $room->getParticipant($userId, false);
			if (!$participant) {
				$this->logger->debug('Could not find participant for user: ' . $userId . ' in room: ' . $token);
				return false;
			}

			// Check if participant has moderator permissions
			$participantType = $participant->getAttendee()->getParticipantType();
			
			return in_array($participantType, [
				Participant::OWNER,
				Participant::MODERATOR,
			], true);

		} catch (\Exception $e) {
			$this->logger->error('Failed to check moderator permissions: ' . $e->getMessage(), [
				'token' => $token,
				'userId' => $userId,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Check if a user has moderator permissions based on actor data from bot event
	 */
	public function isActorModerator(string $token, array $actorData): bool {
		// Extract user ID from actor data
		$actorId = $actorData['id'] ?? '';
		
		// Note: We don't skip guests here since type 6 (Guest with moderator permissions) should be allowed
		// The talkParticipantType will determine actual permissions

		// Use talkParticipantType directly from actor data if available
		if (isset($actorData['talkParticipantType'])) {
			$participantType = (int)$actorData['talkParticipantType'];
			
			$this->logger->debug(sprintf('Actor %s talkParticipantType %d -> checking moderator permissions', $actorId, $participantType));
			
			// Check if participant type indicates moderator or owner
			// Type 1 = Owner, Type 2 = Moderator, Type 6 = Guest with moderator permissions
			$isModerator = in_array($participantType, [
				1, // Participant::OWNER - Owner
				2, // Participant::MODERATOR - Moderator  
				6, // Participant::GUEST_MODERATOR - Guest with moderator permissions
			], true);
			
			$this->logger->debug(sprintf('Actor %s talkParticipantType %d -> isModerator: %s', $actorId, $participantType, $isModerator ? 'true' : 'false'));
			return $isModerator;
		}

		// Fallback to Talk API if talkParticipantType not available
		try {
			$result = $this->isUserModerator($token, $actorId);
			return $result;
		} catch (\Exception $e) {
			$this->logger->error('Failed to check actor permissions: ' . $e->getMessage(), [
				'token' => $token,
				'actorId' => $actorId,
				'actorData' => $actorData,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Check if an actor can add agenda items.
	 * Can add items: Owner (1), Moderator (2), User (3), Guest with moderator permissions (6)
	 *
	 * @param string $token The conversation token
	 * @param array $actorData Actor data containing 'talkParticipantType'
	 * @return bool True if the actor can add agenda items
	 * @throws \Exception If the permission check fails
	 */
	public function canAddAgendaItems(string $token, array $actorData): bool {
		$actorId = $actorData['id'] ?? null;
		$participantType = $actorData['talkParticipantType'] ?? null;

		if ($participantType === null) {
			$this->logger->error('Missing talkParticipantType in actor data for canAddAgendaItems', ['actorData' => $actorData]);
			throw new \Exception('Missing talkParticipantType in actor data');
		}

		// Cast to integer to handle both string and integer participant types
		$participantType = (int)$participantType;

		$canAdd = in_array($participantType, [
			1, // Participant::OWNER - Owner
			2, // Participant::MODERATOR - Moderator
			3, // Participant::USER - User
			6, // Participant::GUEST_MODERATOR - Guest with moderator permissions
		], true);
		
		return $canAdd;
	}

	/**
	 * Check if an actor can view agenda items.
	 * Can view: All participant types (1-6)
	 *
	 * @param string $token The conversation token
	 * @param array $actorData Actor data containing 'talkParticipantType'
	 * @return bool True if the actor can view agenda items (always true for valid participants)
	 * @throws \Exception If the permission check fails
	 */
	public function canViewAgenda(string $token, array $actorData): bool {
		$participantType = $actorData['talkParticipantType'] ?? null;

		if ($participantType === null) {
			throw new \Exception('Missing talkParticipantType in actor data');
		}

		// Cast to integer to handle both string and integer participant types
		$participantType = (int)$participantType;

		// All valid participant types can view agenda
		return in_array($participantType, [
			1, // Participant::OWNER - Owner
			2, // Participant::MODERATOR - Moderator
			3, // Participant::USER - User
			4, // Participant::GUEST - Guest
			5, // Participant::USER_FOLLOWING_LINK - User following a public link
			6, // Participant::GUEST_MODERATOR - Guest with moderator permissions
		], true);
	}

	/**
	 * Get user-friendly error message for permission denied
	 */
	public function getPermissionDeniedMessage(string $action = 'perform this action', string $lang = 'en'): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		return "🔒 **" . $l->t('Permission Denied') . "**\n\n" .
			   $l->t('Only room moderators and owners can %s.', [$action]);
	}

	/**
	 * Get permission denied message for agenda adding commands
	 */
	public function getAddAgendaDeniedMessage(string $lang = 'en'): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		return "🔒 **" . $l->t('Permission Denied') . "**\n\n" .
			   $l->t('Only moderators, owners, and regular users can add agenda items.');
	}

	/**
	 * Get permission denied message for view-only users
	 */
	public function getViewOnlyDeniedMessage(string $lang = 'en'): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);
		return "🔒 **" . $l->t('Permission Denied') . "**\n\n" .
			   $l->t('You can only view the agenda status and help.');
	}
}
