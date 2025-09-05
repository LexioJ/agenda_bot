<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Model;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @method LogEntry mapRowToEntity(array $row)
 * @method LogEntry findEntity(IQueryBuilder $query)
 * @method list<LogEntry> findEntities(IQueryBuilder $query)
 * @template-extends QBMapper<LogEntry>
 */
class LogEntryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ab_log_entries', LogEntry::class);
	}

	/**
	 * @return LogEntry[]
	 */
	public function findByConversation(string $token): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)));
		return $this->findEntities($query);
	}

	public function hasActiveCall(string $token): bool {
		$query = $this->db->getQueryBuilder();
		$query->select($query->expr()->literal(1))
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter(LogEntry::TYPE_ATTENDEE)))
			->setMaxResults(1);
		$result = $query->executeQuery();
		$hasAttendee = (bool)$result->fetchOne();
		$result->closeCursor();

		return $hasAttendee;
	}

	public function deleteByConversation(string $token): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)));
		$query->executeStatement();
	}

	/**
	 * @return LogEntry[]
	 */
	public function findAgendaItems(string $token): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter(LogEntry::TYPE_AGENDA_ITEM)))
			->orderBy('order_position', 'ASC');
		return $this->findEntities($query);
	}

	/**
	 * Find current active agenda item
	 */
	public function findCurrentAgendaItem(string $token): ?LogEntry {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter(LogEntry::TYPE_AGENDA_ITEM)))
			->andWhere($query->expr()->isNotNull('start_time'))
			->andWhere($query->expr()->eq('is_completed', $query->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('start_time', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($query);
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Find agenda item by position
	 */
	public function findAgendaItemByPosition(string $token, int $position): ?LogEntry {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter(LogEntry::TYPE_AGENDA_ITEM)))
			->andWhere($query->expr()->eq('order_position', $query->createNamedParameter($position, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity($query);
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Get next available agenda position
	 */
	public function getNextAgendaPosition(string $token): int {
		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->max('order_position'))
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter(LogEntry::TYPE_AGENDA_ITEM)));

		$result = $query->executeQuery();
		$maxPosition = $result->fetchOne();
		$result->closeCursor();

		return $maxPosition ? (int)$maxPosition + 1 : 1;
	}

	/**
	 * Check if agenda position is occupied
	 */
	public function isAgendaPositionOccupied(string $token, int $position): bool {
		return $this->findAgendaItemByPosition($token, $position) !== null;
	}

	/**
	 * Update agenda positions for reordering
	 */
	public function updateAgendaPositions(string $token, array $positionMap): void {
		$this->db->beginTransaction();
		try {
			foreach ($positionMap as $itemId => $newPosition) {
				$query = $this->db->getQueryBuilder();
				$query->update($this->getTableName())
					->set('order_position', $query->createNamedParameter($newPosition, IQueryBuilder::PARAM_INT))
					->where($query->expr()->eq('id', $query->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
					->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)))
					->andWhere($query->expr()->eq('type', $query->createNamedParameter(LogEntry::TYPE_AGENDA_ITEM)));
				$query->executeStatement();
			}
			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Mark agenda item as completed
	 */
	public function markAgendaItemCompleted(int $id, int $completedAt): void {
		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName())
			->set('is_completed', $query->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->set('completed_at', $query->createNamedParameter($completedAt, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter(LogEntry::TYPE_AGENDA_ITEM)));
		$query->executeStatement();
	}

	/**
	 * Find incomplete agenda items
	 */
	public function findIncompleteAgendaItems(string $token): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter(LogEntry::TYPE_AGENDA_ITEM)))
			->andWhere($query->expr()->eq('is_completed', $query->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('order_position', 'ASC');
		return $this->findEntities($query);
	}

	/**
	 * Find room configuration entry
	 */
	public function findRoomConfig(string $token): ?LogEntry {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter(LogEntry::TYPE_ROOM_CONFIG)))
			->setMaxResults(1);

		try {
			return $this->findEntity($query);
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Find completed agenda items
	 */
	public function findCompletedAgendaItems(string $token): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter(LogEntry::TYPE_AGENDA_ITEM)))
			->andWhere($query->expr()->eq('is_completed', $query->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('order_position', 'ASC');
		return $this->findEntities($query);
	}

	/**
	 * Find all active agenda items across all conversations
	 * Used by time monitoring service
	 */
	public function findActiveAgendaItems(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter(LogEntry::TYPE_AGENDA_ITEM)))
			->andWhere($query->expr()->isNotNull('start_time'))
			->andWhere($query->expr()->eq('is_completed', $query->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($query->expr()->gt('duration_minutes', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('start_time', 'ASC');
		return $this->findEntities($query);
	}

	/**
	 * Find warnings for a specific agenda item and warning type
	 */
	public function findWarningsForAgendaItem(int $agendaItemId, string $warningType): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('server', $query->createNamedParameter('local')))
			->andWhere($query->expr()->eq('type', $query->createNamedParameter(LogEntry::TYPE_AGENDA_WARNING)))
			->andWhere($query->expr()->eq('parent_id', $query->createNamedParameter($agendaItemId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->like('details', $query->createNamedParameter('%"warning_type":"' . $warningType . '"%')));
		return $this->findEntities($query);
	}
}
