<?php

/**
 * Puts a resident's portal message on the case timeline, with a follow-up.
 *
 * A RESIDENT'S MESSAGE WAS STORED AND SHOWN TO NOBODY. portaliq writes a
 * `portaalBericht` straight into OpenRegister when a resident replies or asks
 * about their case, and nothing in dossiq read it: the question sat on the
 * case's schema and the handler never learned it was there
 * (communication-portal-conversation-on-the-case). This listener puts it on
 * the one chronology the handler works from.
 *
 * INTERNAL, AND OPEN UNTIL ANSWERED. The entry is internal: the resident has
 * their own message in their inbox and on the case page, and a second copy in
 * the public feed would show it twice. Its kind carries a follow-up, the way
 * inbound mail does, so the case surfaces in the handler's queue until someone
 * answers.
 *
 * THE HANDLER'S ANSWER IS PUBLIC. A message the handler sent
 * (`handler_to_citizen`) is a `portaalbericht` entry the resident may read in
 * the portal's case history (one-timeline-on-the-case D5): they have received
 * it, and a history that says nothing about a letter somebody holds is worse
 * than none. It opens no follow-up.
 *
 * ONLY THE CREATE. An update (portaliq's mark-read) is not a new message.
 *
 * IT NEVER THROWS. {@see CaseTimeline} softens its own failures; a payload this
 * listener cannot read is a message with no entry, not a save that comes undone.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#5-the-handler-side
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Records one timeline entry per portal message on a case: internal with a
 * follow-up from the resident, public from the handler.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#5-the-handler-side
 */
class PortalMessageTimelineListener implements IEventListener {

	/**
	 * The schema whose creates this listener answers to.
	 *
	 * @var string
	 */
	public const SCHEMA = 'portaalBericht';

	/**
	 * The direction of a message the resident sent.
	 *
	 * @var string
	 */
	public const FROM_RESIDENT = 'citizen_to_handler';

	/**
	 * The direction of a message the handler sent.
	 *
	 * @var string
	 */
	public const TO_RESIDENT = 'handler_to_citizen';

	/**
	 * What the entry reads when the resident left the subject empty.
	 *
	 * @var string
	 */
	public const FALLBACK_MESSAGE = 'Bericht van de indiener';

	/**
	 * What the entry reads when the handler left the subject empty.
	 *
	 * @var string
	 */
	public const SENT_MESSAGE = 'Bericht aan de indiener';

	/**
	 * Constructor.
	 *
	 * @param CaseTimeline             $timeline     The one seam that writes a timeline entry.
	 * @param ObjectSchemaSlugResolver $slugResolver Resolves a schema id to its slug.
	 */
	public function __construct(
		private readonly CaseTimeline $timeline,
		private readonly ObjectSchemaSlugResolver $slugResolver,
	) {
	}//end __construct()

	/**
	 * Record the message a completed create carried.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#5-the-handler-side
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$payload = $this->payload(event: $event);
		$direction = (string)($payload['direction'] ?? '');
		if ($payload === null || in_array($direction, [self::FROM_RESIDENT, self::TO_RESIDENT], true) === false) {
			return;
		}

		if ($this->slugResolver->resolveFromPayload(payload: $payload) !== self::SCHEMA) {
			return;
		}

		$caseId = trim((string)($payload['caseId'] ?? ''));
		if ($caseId === '') {
			return;
		}

		if ($direction === self::TO_RESIDENT) {
			$this->recordAnswer(caseId: $caseId, payload: $payload);
			return;
		}

		$this->recordQuestion(caseId: $caseId, payload: $payload);
	}//end handle()

	/**
	 * The handler's message: public, no follow-up.
	 *
	 * @param string               $caseId  The case.
	 * @param array<string, mixed> $payload The stored record.
	 *
	 * @return void
	 */
	private function recordAnswer(string $caseId, array $payload): void {
		$subject = $this->subject(payload: $payload, fallback: self::SENT_MESSAGE);

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::PORTAL_MESSAGE,
			message: $subject,
			fields: [
				'subject' => $subject,
				'messageId' => $this->identifier(payload: $payload),
				'status' => 'sent',
			],
			visibility: CaseTimeline::PUBLIC_ENTRY,
		);
	}//end recordAnswer()

	/**
	 * The resident's message: internal, open until answered.
	 *
	 * @param string               $caseId  The case.
	 * @param array<string, mixed> $payload The stored record.
	 *
	 * @return void
	 */
	private function recordQuestion(string $caseId, array $payload): void {
		$subject = $this->subject(payload: $payload, fallback: self::FALLBACK_MESSAGE);

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::PORTAL_MESSAGE_IN,
			message: $subject,
			fields: [
				'subject' => $subject,
				'messageId' => $this->identifier(payload: $payload),
				'sender' => (string)($payload['senderName'] ?? ''),
				'sentAt' => (string)($payload['sentAt'] ?? ''),
			],
			visibility: CaseTimeline::INTERNAL,
		);
	}//end recordQuestion()

	/**
	 * The message's subject, or the fallback sentence when it has none.
	 *
	 * @param array<string, mixed> $payload  The stored record.
	 * @param string               $fallback What the entry reads without one.
	 *
	 * @return string
	 */
	private function subject(array $payload, string $fallback): string {
		$subject = trim((string)($payload['subject'] ?? ''));
		if ($subject === '') {
			return $fallback;
		}

		return $subject;
	}//end subject()

	/**
	 * The message's own id, so the handler's Reply can open it.
	 *
	 * @param array<string, mixed> $payload The stored record.
	 *
	 * @return string The id, or ''.
	 */
	private function identifier(array $payload): string {
		$fromSelf = (string)($payload['@self']['id'] ?? '');
		if ($fromSelf !== '') {
			return $fromSelf;
		}

		return (string)($payload['id'] ?? '');
	}//end identifier()

	/**
	 * The created object as a plain array.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<string, mixed>|null The payload, or null when it cannot be read.
	 */
	private function payload(Event $event): ?array {
		$object = null;

		if (method_exists($event, 'getObject') === true) {
			$object = $event->getObject();
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return null;
	}//end payload()
}//end class
