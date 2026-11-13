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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\CaseTimelineInterface;
use OCA\Dossiq\Kernel\Contracts\TimelineKindsInterface;
use OCA\Dossiq\Listener\Support\CreatedCaseRecord;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Records one timeline entry per contact logged on a case.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/portal-contribution/spec.md
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
	 * @param CaseTimelineInterface             $timeline     The one seam that writes a timeline entry.
	 * @param ObjectSchemaSlugResolver $slugResolver Resolves a schema id to its slug.
	 */
	public function __construct(
		private readonly CaseTimelineInterface $timeline,
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
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function handle(Event $event): void {
		$payload = (new CreatedCaseRecord(slugResolver: $this->slugResolver, schema: self::SCHEMA))->of(event: $event);
		if ($payload === null) {
			return;
		}

		$caseId = trim((string)$payload['case']);

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKindsInterface::CONTACTMOMENT,
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
	 * THE TICKED SPELLINGS ARE LISTED, NOT CAST. `(bool)'false'` is TRUE in
	 * PHP, so a cast would publish a contact whose flag says the opposite, and
	 * a strict `=== true` alone would keep a genuinely ticked `1` inside. The
	 * schema declares a boolean and OpenRegister answers one, but this value
	 * arrives from two surfaces and a form is free to post what it likes, so
	 * the four spellings that mean yes are named and everything else is an
	 * unticked box.
	 *
	 * @param array<string, mixed> $payload The stored record.
	 *
	 * @return string `internal` or `public`.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	private function visibility(array $payload): string {
		if (in_array(($payload['visibleToApplicant'] ?? false), [true, 1, '1', 'true'], true) === true) {
			return CaseTimelineInterface::PUBLIC_ENTRY;
		}

		return CaseTimelineInterface::INTERNAL;
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
}//end class
