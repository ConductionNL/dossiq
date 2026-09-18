<?php

/**
 * The timeline entry a status move writes.
 *
 * SPLIT OUT OF `CaseStatusStore` RATHER THAN WRITTEN INSIDE IT. The store
 * exists to reach OpenRegister for the four schemas a transition touches, and
 * building a sentence, resolving two status names and deciding who the actor
 * was is none of that. Folding it in took the store's complexity from 41 to 53
 * for one concern that has nothing to do with persistence, which is what phpmd
 * was pointing at. The store still decides WHEN a move is recorded: it calls
 * this class once, from the one method all four movers reach.
 *
 * IT NEVER THROWS AT ITS CALLER. The case has already moved by the time the
 * entry is attempted, and refusing the move because the log could not be
 * written would trade a missing line for a lost transition.
 * {@see CaseTimeline::record()} answers rather than throws, and the name
 * lookup around it is guarded the same way.
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

use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCP\IUserSession;
use Throwable;

/**
 * Writes one `statuswijziging` entry per recorded status move.
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */
class StatusMoveEntry {

	/**
	 * Constructor.
	 *
	 * @param CaseTimeline     $timeline    The one seam that writes a timeline entry.
	 * @param StatusTypeLookup $statuses    Resolves a statusType id to its administered name.
	 * @param IUserSession     $userSession Who is signed in, when the caller does not say.
	 */
	public function __construct(
		private readonly CaseTimeline $timeline,
		private readonly StatusTypeLookup $statuses,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Record one status move on the case's timeline.
	 *
	 * THE MESSAGE CARRIES NAMES, THE FIELDS CARRY IDS. A status is a uuid on
	 * the case, and a timeline that read `a1b2c3…` at a handler would be worse
	 * than no line at all, so both statuses are resolved to their administered
	 * names for the sentence while `from` and `to` keep the ids a consumer can
	 * filter on.
	 *
	 * @param string               $caseId     The case that moved.
	 * @param string               $toStatus   The statusType it moved into.
	 * @param string               $fromStatus The statusType it left, '' on a first status.
	 * @param string               $label      The transition's own label.
	 * @param string|null          $comment    What the mover said about it.
	 * @param array<string, mixed> $record     The statusRecord just written.
	 * @param string               $actor      Who the caller says made the move, '' when it did not say.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function record(
		string $caseId,
		string $toStatus,
		string $fromStatus,
		string $label,
		?string $comment,
		array $record,
		string $actor = '',
	): void {
		if ($caseId === '' || $toStatus === '') {
			return;
		}

		$publicLabel = $this->statuses->announcedLabelOf(statusTypeId: $toStatus);

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::STATUS_CHANGE,
			message: $this->sentence(toStatus: $toStatus, fromStatus: $fromStatus, publicLabel: $publicLabel),
			fields: [
				'from' => $fromStatus,
				'to' => $toStatus,
				'actor' => $this->actor(named: $actor),
				'explanation' => (string)($comment ?? ''),
				'label' => $label,
				'statusRecordId' => (string)($record['id'] ?? ($record['@self']['id'] ?? '')),
			],
			visibility: $this->visibility(publicLabel: $publicLabel),
		);
	}//end record()

	/**
	 * Which side of the counter a status move belongs on.
	 *
	 * A status the organisation gave public words to is a status the applicant
	 * is meant to hear about. Every other move stays inside, which is the
	 * default the whole feed runs on.
	 *
	 * @param string $publicLabel The status's public label, '' when it has none.
	 *
	 * @return string `internal` or `public`.
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	private function visibility(string $publicLabel): string {
		if ($publicLabel === '') {
			return CaseTimeline::INTERNAL;
		}

		return CaseTimeline::PUBLIC_ENTRY;
	}//end visibility()

	/**
	 * What a reader sees on the line.
	 *
	 * A PUBLIC ENTRY READS THE PUBLIC WORDS, AND ONLY THOSE. One entry is read
	 * by the handler and by the applicant, so a public move that spelled out
	 * the administered from-and-to names would hand the applicant the internal
	 * vocabulary of a workflow they are not part of. A status that was given
	 * public words is announced in them: "Status: In behandeling". Every other
	 * move keeps the handler's sentence, because nobody outside reads it.
	 *
	 * @param string $toStatus    The statusType entered.
	 * @param string $fromStatus  The statusType left, '' on a first status.
	 * @param string $publicLabel The status's public label, '' when it has none.
	 *
	 * @return string The sentence.
	 */
	private function sentence(string $toStatus, string $fromStatus, string $publicLabel = ''): string {
		if ($publicLabel !== '') {
			return 'Status: ' . $publicLabel;
		}

		$to = $this->statusName(statusTypeId: $toStatus);
		$from = $this->statusName(statusTypeId: $fromStatus);

		if ($from === '') {
			return 'Status gezet op ' . $to;
		}

		return 'Status gewijzigd van ' . $from . ' naar ' . $to;
	}//end sentence()

	/**
	 * The administered name of a statusType, or its id when the name is not readable.
	 *
	 * The id is the honest fallback: a sentence reading "Status gewijzigd naar
	 * a1b2c3" is poor, and one reading "Status gewijzigd naar " is a bug report
	 * nobody can act on.
	 *
	 * @param string $statusTypeId The statusType uuid, or ''.
	 *
	 * @return string The name, the id, or '' when nothing was asked for.
	 */
	private function statusName(string $statusTypeId): string {
		if ($statusTypeId === '') {
			return '';
		}

		try {
			$name = trim($this->statuses->nameFor(statusTypeId: $statusTypeId));
		} catch (Throwable $e) {
			$name = '';
		}

		if ($name === '') {
			return $statusTypeId;
		}

		return $name;
	}//end statusName()

	/**
	 * Who made the move, as a user id, or '' for a move nobody signed.
	 *
	 * THE CALLER'S ANSWER WINS. `writeStatusRecord()` takes the actor, because
	 * the four-eyes rule needs to know who TOOK a step rather than who wrote
	 * the row, and the two are not the same claim. The session is the fallback
	 * for the callers that do not name one yet.
	 *
	 * A background job and a timer both move cases, and '' is the true answer
	 * for those rather than a name invented to fill the field.
	 *
	 * @param string $named Who the caller says made the move, '' when it did not say.
	 *
	 * @return string The uid, or ''.
	 */
	private function actor(string $named = ''): string {
		if (trim($named) !== '') {
			return trim($named);
		}

		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end actor()
}//end class
