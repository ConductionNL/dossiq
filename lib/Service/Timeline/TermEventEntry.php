<?php

/**
 * The timeline entry a term event writes.
 *
 * SPLIT OUT OF `TermijnService` RATHER THAN WRITTEN INSIDE IT. That class owns
 * the TermijnInstance lifecycle and the TermijnGebeurtenis register, and
 * building a Dutch sentence for a handler is neither. Folding it in took its
 * complexity from 47 to 61, which is what phpmd was pointing at.
 * `TermijnService` still decides WHEN an entry is written and resolves the
 * instance, because it is the only class that knows how to read one.
 *
 * WHY THE INSTANCE IS PASSED IN. A TermijnGebeurtenis names its instance, and
 * the instance names the case, so the case is one read away. That read belongs
 * to `TermijnService`, and asking for it here would make this class depend on
 * the class that depends on it.
 *
 * IT NEVER THROWS AT ITS CALLER. The TermijnGebeurtenis is the legal record
 * and it is already written by the time the entry is attempted.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Timeline;

use DateTimeImmutable;
use OCA\Dossiq\Service\TermKind;

/**
 * Writes one `termijngebeurtenis` entry per recorded term event.
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */
class TermEventEntry {

	/**
	 * What a handler reads when a term of each kind starts.
	 *
	 * Four kinds, four sentences, because "Termijn gestart" on all four tells
	 * a handler nothing about which clock moved, and a case carries up to four
	 * of them at once.
	 *
	 * @var array<string, string>
	 */
	private const START_SENTENCES = [
		TermKind::STATUTORY => 'Wettelijke termijn gestart',
		TermKind::PLANNED => 'Geplande einddatum vastgelegd',
		TermKind::INTERNAL => 'Interne streefdatum vastgelegd',
		TermKind::PHASE => 'Fasetermijn gestart',
	];

	/**
	 * Constructor.
	 *
	 * @param CaseTimeline $timeline The one seam that writes a timeline entry.
	 */
	public function __construct(
		private readonly CaseTimeline $timeline,
	) {
	}//end __construct()

	/**
	 * Record one term event on the case of the instance it hangs on.
	 *
	 * THE SENTENCE IS THE RATIONALE, NOT THE TYPE. Every caller already
	 * supplies a Dutch sentence saying what happened, and `$type` is an
	 * identifier (`pause-expired`, `information-requested`) that has no
	 * business being read by a handler.
	 *
	 * @param array<string, mixed>   $instance  The term instance, empty when it could not be read.
	 * @param string                 $type      The event type, as stored.
	 * @param string                 $basis     The legal basis.
	 * @param string                 $rationale The sentence a handler reads.
	 * @param DateTimeImmutable|null $moment    When it happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function recordEvent(
		array $instance,
		string $type,
		string $basis,
		string $rationale,
		?DateTimeImmutable $moment,
	): void {
		$caseId = trim((string)($instance['case'] ?? ''));
		if ($caseId === '') {
			return;
		}

		$message = trim($rationale);
		if ($message === '') {
			$message = 'Termijngebeurtenis vastgelegd';
		}

		$this->write(
			caseId: $caseId,
			message: $message,
			instance: $instance,
			event: $type,
			basis: $basis,
			occurredAt: ($moment ?? new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
		);
	}//end recordEvent()

	/**
	 * Record the start of a term that writes no TermijnGebeurtenis of its own.
	 *
	 * A planned end, an internal target and a phase term are written straight
	 * to the instance schema and raise no event, by design: they are not
	 * statutory clocks and nothing about them has a legal basis to record. So
	 * the event funnel never sees them, and a handler reading the timeline
	 * would see the statutory clock start and nothing about the phase clock
	 * that actually governs their week.
	 *
	 * A REWRITE IS NOT A START. `bindStatutory()` re-binds a term that is
	 * already running when the case type's fixed end date moves, and a
	 * timeline announcing a start each time would report clocks that never
	 * started. The row AS THE CALLER HANDED IT IN is what tells the two apart:
	 * a create carries no id and a rewrite does. It is passed rather than
	 * reduced to a flag, so the caller states a fact instead of a verdict.
	 *
	 * @param array<string, mixed> $instance  The stored term instance, empty when the write failed.
	 * @param array<string, mixed> $requested The instance as the caller handed it in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function recordStart(array $instance, array $requested = []): void {
		$caseId = trim((string)($instance['case'] ?? ''));
		if (isset($requested['id']) === true || $caseId === '') {
			return;
		}

		$kind = (string)($instance['kind'] ?? TermKind::STATUTORY);
		$due = $this->dueAt(instance: $instance);

		$message = self::START_SENTENCES[$kind] ?? 'Termijn gestart';
		if ($due !== '') {
			$message .= ', uiterlijk ' . $due;
		}

		$this->write(
			caseId: $caseId,
			message: $message,
			instance: $instance,
			event: 'start',
			basis: '',
			occurredAt: (string)($instance['startDate'] ?? ''),
		);
	}//end recordStart()

	/**
	 * Write one entry of the term-event kind.
	 *
	 * @param string               $caseId     The case the term hangs on.
	 * @param string               $message    The sentence a handler reads.
	 * @param array<string, mixed> $instance   The term instance, as stored.
	 * @param string               $event      The event type.
	 * @param string               $basis      The legal basis, '' when there is none.
	 * @param string               $occurredAt When it happened.
	 *
	 * @return void
	 */
	private function write(
		string $caseId,
		string $message,
		array $instance,
		string $event,
		string $basis,
		string $occurredAt,
	): void {
		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::TERM_EVENT,
			message: $message,
			fields: [
				'event' => $event,
				'term' => (string)($instance['kind'] ?? TermKind::STATUTORY),
				'occurredAt' => $occurredAt,
				'dueAt' => $this->dueAt(instance: $instance),
				'startedAt' => (string)($instance['startDate'] ?? ''),
				'basis' => $basis,
				'termijnId' => (string)($instance['id'] ?? ''),
			],
			visibility: CaseTimeline::INTERNAL,
		);
	}//end write()

	/**
	 * When the term falls due now, after any pause or extension.
	 *
	 * @param array<string, mixed> $instance The term instance.
	 *
	 * @return string The date, or ''.
	 */
	private function dueAt(array $instance): string {
		return (string)($instance['endDateCurrent'] ?? ($instance['endDateCalculated'] ?? ''));
	}//end dueAt()
}//end class
