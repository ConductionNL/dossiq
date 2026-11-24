<?php

/**
 * Dossiq session meeting.
 *
 * Opens a planned session as a meeting in the decision app, when that app is
 * installed. The meeting points back at the session through its subject
 * fields (`subjectRegister`, `subjectSchema`, `subjectId`); the session keeps
 * the meeting's id in `meeting`. The command travels as the decision app's
 * typed `MeetingRequestedEvent` (ADR-041), built positionally through a
 * class-string so dossiq stays installable without it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Session
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-planned-session-opens-as-a-meeting-in-the-decision-app-req-css-6
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Session;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks the decision app to hold a meeting for a planned session.
 *
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-planned-session-opens-as-a-meeting-in-the-decision-app-req-css-6
 */
class SessionMeeting {

	/**
	 * The decision app's meeting command event.
	 *
	 * One spelling only: it was added after the OCA\Decidesk -> OCA\Decidiq
	 * rename, so no older namespace ever shipped it.
	 *
	 * @var string
	 */
	private const MEETING_REQUESTED_EVENT = '\\OCA\\Decidiq\\Event\\MeetingRequestedEvent';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Nextcloud typed event dispatcher.
	 * @param LoggerInterface  $logger          Logger.
	 * @param string           $eventClass      The command event class; a test names an absent one.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
		private readonly string $eventClass = self::MEETING_REQUESTED_EVENT,
	) {
	}//end __construct()

	/**
	 * Open a stored session as a meeting, and answer the meeting's id.
	 *
	 * Fails open: without the decision app, or when it does not handle the
	 * command, the session stays planned in dossiq and the answer is ''. A
	 * session is the case's own record; the meeting is where the decision app
	 * shows it, so its absence must not refuse the plan.
	 *
	 * @param array<string, mixed> $session  The stored session, with its id.
	 * @param array{0: string, 1: string} $location The register and the session schema it is stored in.
	 *
	 * @return string The meeting id, or '' when no meeting was opened.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-planned-session-opens-as-a-meeting-in-the-decision-app-req-css-6
	 */
	public function open(array $session, array $location): string {
		$sessionId = $this->text(value: ($session['id'] ?? ($session['@self']['id'] ?? '')));
		if ($sessionId === '' || class_exists($this->eventClass) === false) {
			return '';
		}

		$eventClass = $this->eventClass;
		try {
			// Positional ctor args (decision-app contract): subjectRegister,
			// subjectSchema, subjectId, title, scheduledDate, attributes, actorId.
			$event = new $eventClass(
				$location[0],
				$location[1],
				$sessionId,
				$this->text(value: ($session['title'] ?? '')),
				$this->text(value: ($session['scheduledDate'] ?? '')),
				$this->attributesOf(session: $session),
				''
			);
			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq sessions: the meeting could not be requested; the session stays planned without one',
				['session' => $sessionId, 'error' => $e->getMessage()]
			);
			return '';
		}

		if ((bool)$event->isHandled() === false) {
			$this->logger->warning(
				'Dossiq sessions: the decision app did not hold a meeting for the session',
				['session' => $sessionId]
			);
			return '';
		}

		return (string)$event->getMeetingId();
	}//end open()

	/**
	 * The meeting fields a session carries: where and how people attend.
	 *
	 * @param array<string, mixed> $session The stored session.
	 *
	 * @return array<string, mixed> The attributes, without empty values.
	 */
	private function attributesOf(array $session): array {
		$location = $this->text(value: ($session['location'] ?? ''));
		$videoUrl = $this->text(value: ($session['videoCallUrl'] ?? ''));

		$attributes = [
			'location'        => $location,
			'virtualLocation' => $videoUrl,
			'meetingMode'     => $this->modeOf(location: $location, videoUrl: $videoUrl),
		];

		return array_filter($attributes, static fn (string $value): bool => $value !== '');
	}//end attributesOf()

	/**
	 * How people attend: in person, by video, or both.
	 *
	 * @param string $location The place, or ''.
	 * @param string $videoUrl The video call link, or ''.
	 *
	 * @return string The meeting mode, or '' to leave the decision app's default.
	 */
	private function modeOf(string $location, string $videoUrl): string {
		if ($videoUrl === '') {
			return '';
		}

		if ($location === '') {
			return 'digital';
		}

		return 'hybrid';
	}//end modeOf()

	/**
	 * A trimmed string, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()

}//end class
