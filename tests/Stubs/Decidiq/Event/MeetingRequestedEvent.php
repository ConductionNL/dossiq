<?php

/**
 * Decidiq MeetingRequestedEvent test stub.
 *
 * Mirrors the decision app's meeting command contract (decidiq
 * `lib/Event/MeetingRequestedEvent.php`) so dossiq's SessionMeeting can be
 * unit-tested without the app installed. Loaded by tests/bootstrap.php only
 * when the real class is absent.
 *
 * THE CONSTRUCTOR ORDER IS THE CONTRACT: production builds this positionally
 * through a class-string. Keep it identical to decidiq's.
 *
 * @category Tests
 * @package  OCA\Decidiq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://decidiq.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Event;

use OCP\EventDispatcher\Event;

/**
 * A consumer app asks the decision app to hold a meeting for its object.
 */
class MeetingRequestedEvent extends Event {

	/**
	 * The meeting id (result slot).
	 *
	 * @var string
	 */
	private string $meetingId = '';

	/**
	 * Whether the meeting was newly created (result slot).
	 *
	 * @var boolean
	 */
	private bool $created = false;

	/**
	 * Whether the decision app handled the command (result slot).
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * Construct the command.
	 *
	 * @param string               $subjectRegister Register of the producer's object
	 * @param string               $subjectSchema   Schema of the producer's object
	 * @param string               $subjectId       Uuid of the producer's object
	 * @param string               $title           Meeting title
	 * @param string               $scheduledDate   ISO-8601 start
	 * @param array<string, mixed> $attributes      Further meeting fields
	 * @param string               $actorId         Acting Nextcloud UID
	 */
	public function __construct(
		private readonly string $subjectRegister,
		private readonly string $subjectSchema,
		private readonly string $subjectId,
		private readonly string $title,
		private readonly string $scheduledDate,
		private readonly array $attributes = [],
		private readonly string $actorId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * @return string
	 */
	public function getSubjectRegister(): string {
		return $this->subjectRegister;
	}//end getSubjectRegister()

	/**
	 * @return string
	 */
	public function getSubjectSchema(): string {
		return $this->subjectSchema;
	}//end getSubjectSchema()

	/**
	 * @return string
	 */
	public function getSubjectId(): string {
		return $this->subjectId;
	}//end getSubjectId()

	/**
	 * @return string
	 */
	public function getTitle(): string {
		return $this->title;
	}//end getTitle()

	/**
	 * @return string
	 */
	public function getScheduledDate(): string {
		return $this->scheduledDate;
	}//end getScheduledDate()

	/**
	 * @return array<string, mixed>
	 */
	public function getAttributes(): array {
		return $this->attributes;
	}//end getAttributes()

	/**
	 * @return string
	 */
	public function getActorId(): string {
		return $this->actorId;
	}//end getActorId()

	/**
	 * @return string
	 */
	public function getMeetingId(): string {
		return $this->meetingId;
	}//end getMeetingId()

	/**
	 * @param string $meetingId The id
	 *
	 * @return void
	 */
	public function setMeetingId(string $meetingId): void {
		$this->meetingId = $meetingId;
	}//end setMeetingId()

	/**
	 * @return boolean
	 */
	public function isCreated(): bool {
		return $this->created;
	}//end isCreated()

	/**
	 * @param boolean $created The flag
	 *
	 * @return void
	 */
	public function setCreated(bool $created): void {
		$this->created = $created;
	}//end setCreated()

	/**
	 * @return boolean
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * @param boolean $handled The flag
	 *
	 * @return void
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;
	}//end setHandled()

}//end class
