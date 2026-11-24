<?php

/**
 * Decidiq MeetingRequestedEvent test stub.
 *
 * Mirrors the decision app's meeting command contract (decidiq
 * `lib/Event/MeetingRequestedEvent.php`) so dossiq's SessionMeeting can be
 * unit-tested without the app installed. Loaded by tests/bootstrap.php only
 * when the real class is absent.
 *
 * THE CONSTRUCTOR IS THE CONTRACT: production builds it through a class-string.
 * Keep it identical to decidiq's.
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
	 * The answer, null until the decision app answers.
	 *
	 * @var array{meetingId: string, created: bool}|null
	 */
	private ?array $answer = null;

	/**
	 * Construct the command.
	 *
	 * @param array<string, mixed> $subject The producer's object: register, schema, id
	 * @param array<string, mixed> $meeting Title, scheduledDate and further meeting fields
	 * @param string               $actorId Acting Nextcloud UID
	 */
	public function __construct(
		private readonly array $subject,
		private readonly array $meeting,
		private readonly string $actorId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * @return array<string, mixed>
	 */
	public function getSubject(): array {
		return $this->subject;
	}//end getSubject()

	/**
	 * @return array<string, mixed>
	 */
	public function getMeeting(): array {
		return $this->meeting;
	}//end getMeeting()

	/**
	 * @return string
	 */
	public function getActorId(): string {
		return $this->actorId;
	}//end getActorId()

	/**
	 * @param string $meetingId The meeting
	 * @param bool   $created   Whether this command minted it
	 *
	 * @return void
	 */
	public function answer(string $meetingId, bool $created): void {
		$this->answer = ['meetingId' => $meetingId, 'created' => $created];
	}//end answer()

	/**
	 * @return bool
	 */
	public function isHandled(): bool {
		return $this->answer !== null;
	}//end isHandled()

	/**
	 * @return string
	 */
	public function getMeetingId(): string {
		return ($this->answer['meetingId'] ?? '');
	}//end getMeetingId()

	/**
	 * @return bool
	 */
	public function isCreated(): bool {
		return ($this->answer['created'] ?? false);
	}//end isCreated()

}//end class
