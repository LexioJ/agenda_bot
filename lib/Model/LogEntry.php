<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Agenda Bot Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgendaBot\Model;

use OCP\AppFramework\Db\Entity;

/**
 * @method void setServer(string $server)
 * @method string getServer()
 * @method void setToken(string $token)
 * @method string getToken()
 * @method void setType(string $type)
 * @method string getType()
 * @method void setDetails(?string $details)
 * @method string|null getDetails()
 * @method void setOrderPosition(?int $orderPosition)
 * @method int|null getOrderPosition()
 * @method void setDurationMinutes(?int $durationMinutes)
 * @method int|null getDurationMinutes()
 * @method void setStartTime(?int $startTime)
 * @method int|null getStartTime()
 * @method void setParentId(?int $parentId)
 * @method int|null getParentId()
 * @method void setConflictResolved(bool $conflictResolved)
 * @method bool getConflictResolved()
 * @method void setWarningSent(bool $warningSent)
 * @method bool getWarningSent()
 * @method void setIsCompleted(bool $isCompleted)
 * @method bool getIsCompleted()
 * @method void setCompletedAt(?int $completedAt)
 * @method int|null getCompletedAt()
 */
class LogEntry extends Entity {
	public const TYPE_ATTENDEE = 'attendee';
	public const TYPE_START = 'start';
	public const TYPE_END = 'end';
	public const TYPE_MESSAGE = 'message';
	public const TYPE_AGENDA = 'agenda';
	public const TYPE_AGENDA_ITEM = 'agenda_item';
	public const TYPE_AGENDA_TRANSITION = 'agenda_transition';
	public const TYPE_AGENDA_WARNING = 'agenda_warning';
	public const TYPE_AGENDA_STATUS = 'agenda_status';
	public const TYPE_TODO = 'todo';
	public const TYPE_SOLVED = 'solved';
	public const TYPE_NOTE = 'note';
	public const TYPE_REPORT = 'report';
	public const TYPE_DECISION = 'decision';

	/** @var string */
	protected $server;

	/** @var string */
	protected $token;

	/** @var string */
	protected $type;

	/** @var ?string */
	protected $details;

	/** @var ?int */
	protected $orderPosition;

	/** @var ?int */
	protected $durationMinutes;

	/** @var ?int */
	protected $startTime;

	/** @var ?int */
	protected $parentId;

	/** @var bool */
	protected $conflictResolved;

	/** @var bool */
	protected $warningSent;

	/** @var bool */
	protected $isCompleted;

	/** @var ?int */
	protected $completedAt;

	public function __construct() {
		$this->addType('server', 'string');
		$this->addType('token', 'string');
		$this->addType('type', 'string');
		$this->addType('details', 'string');
		$this->addType('orderPosition', 'integer');
		$this->addType('durationMinutes', 'integer');
		$this->addType('startTime', 'integer');
		$this->addType('parentId', 'integer');
		$this->addType('conflictResolved', 'boolean');
		$this->addType('warningSent', 'boolean');
		$this->addType('isCompleted', 'boolean');
		$this->addType('completedAt', 'integer');
	}
}
