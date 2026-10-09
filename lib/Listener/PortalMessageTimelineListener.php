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
 * ONLY THE CREATE, ONLY THE RESIDENT'S DIRECTION. A message the handler sent
 * (`handler_to_citizen`) is recorded by whoever sent it; an update (portaliq's
 * mark-read) is not a new message.
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
 * Records one internal timeline entry per message a resident sends about a case.
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
	 * What the entry reads when the resident left the subject empty.
	 *
	 * @var string
	 */
	public const FALLBACK_MESSAGE = 'Bericht van de indiener';

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
		if ($payload === null || ($payload['direction'] ?? '') !== self::FROM_RESIDENT) {
			return;
		}

		if ($this->slugResolver->resolveFromPayload(payload: $payload) !== self::SCHEMA) {
			return;
		}

		$caseId = trim((string)($payload['caseId'] ?? ''));
		if ($caseId === '') {
			return;
		}

		$subject = trim((string)($payload['subject'] ?? ''));
		if ($subject === '') {
			$subject = self::FALLBACK_MESSAGE;
		}

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
	}//end handle()

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
