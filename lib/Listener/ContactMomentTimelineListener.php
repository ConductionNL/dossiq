<?php

/**
 * Puts a logged contact on the case timeline, whichever surface logged it.
 *
 * THE WRITE MOVED HERE FROM `ContactMomentService` BECAUSE THE SERVICE DOES NOT
 * ALWAYS RUN. A contact logged from the KCC werkplek goes through
 * `ContactMomentController` and therefore through the service. A contact logged
 * from the Communication tab on the case page goes through the manifest's
 * `log-contact` open-form action, which saves straight to
 * `/apps/openregister/api/objects` and runs no dossiq service at all. Those two
 * surfaces wrote the same record and only one of them reached the timeline, so
 * the Communication tab's own entries were missing from the one chronology, and
 * the visibility flag the form now offers would have decided nothing there.
 *
 * ONE WRITER, NOT TWO. The service no longer records the entry itself. An
 * `ObjectCreatedEvent` fires on both paths, so binding the write to the event
 * covers both exactly once. A second writer beside this one would put the same
 * contact on the timeline twice on the KCC path and once on the case page,
 * which is worse than the gap it would close.
 *
 * IT NEVER THROWS. {@see CaseTimeline} already softens its own failures, and
 * this listener adds nothing that can fail loudly: a payload it cannot read is
 * a contact with no entry, not a save that comes undone.
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
 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
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
 * Records one timeline entry per contact logged on a case.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
 */
class ContactMomentTimelineListener implements IEventListener {

	/**
	 * The schema whose creates this listener answers to.
	 *
	 * @var string
	 */
	public const SCHEMA = 'contactmoment';

	/**
	 * What the entry reads when the handler left the summary empty.
	 *
	 * @var string
	 */
	public const FALLBACK_MESSAGE = 'Contactmoment geregistreerd';

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
	 * Record the contact a completed create carried.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$payload = $this->payload(event: $event);
		if ($payload === null) {
			return;
		}

		if ($this->slugResolver->resolveFromPayload(payload: $payload) !== self::SCHEMA) {
			return;
		}

		$caseId = trim((string)($payload['case'] ?? ''));
		if ($caseId === '') {
			return;
		}

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::CONTACTMOMENT,
			message: $this->message(payload: $payload),
			fields: [
				'channel' => (string)($payload['notificationChannel'] ?? ''),
				'direction' => (string)($payload['direction'] ?? 'inbound'),
				'nature' => (string)($payload['nature'] ?? ''),
				'contactmomentId' => $this->identifier(payload: $payload),
			],
			visibility: $this->visibility(payload: $payload),
			relatedCaseIds: $this->relatedCaseIds(payload: $payload, caseId: $caseId),
		);
	}//end handle()

	/**
	 * Which side of the counter this contact belongs on.
	 *
	 * INTERNAL UNLESS THE HANDLER SAID OTHERWISE. A logged call is written for
	 * the handler, and the applicant reads it only when someone ticked the box
	 * on the form. An absent field is an unticked box, not an unknown answer.
	 *
	 * @param array<string, mixed> $payload The stored record.
	 *
	 * @return string `internal` or `public`.
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	private function visibility(array $payload): string {
		if (($payload['visibleToApplicant'] ?? false) === true) {
			return CaseTimeline::PUBLIC_ENTRY;
		}

		return CaseTimeline::INTERNAL;
	}//end visibility()

	/**
	 * What a reader sees on the line.
	 *
	 * @param array<string, mixed> $payload The stored record.
	 *
	 * @return string The sentence.
	 */
	private function message(array $payload): string {
		$summary = trim((string)($payload['summary'] ?? ''));
		if ($summary === '') {
			return self::FALLBACK_MESSAGE;
		}

		return $summary;
	}//end message()

	/**
	 * The contactmoment's own id, so the full log is one hop away.
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
	 * The further cases the same contact belongs on.
	 *
	 * The case itself is dropped: `relatedCases` is seeded with it on both
	 * surfaces, and {@see CaseTimeline::record()} would otherwise be asked to
	 * write the entry on a case it is already writing it on.
	 *
	 * @param array<string, mixed> $payload The stored record.
	 * @param string               $caseId  The case the entry already hangs on.
	 *
	 * @return array<int, string> The further case ids.
	 */
	private function relatedCaseIds(array $payload, string $caseId): array {
		$related = [];

		foreach ((array)($payload['relatedCases'] ?? []) as $candidate) {
			$candidate = trim((string)$candidate);
			if ($candidate === '' || $candidate === $caseId) {
				continue;
			}

			$related[] = $candidate;
		}

		return $related;
	}//end relatedCaseIds()

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
