<?php

/**
 * Whether a case note leaves for the neighbouring register, and what it says
 * about itself afterwards.
 *
 * Two rules live here, and both are about a note that did NOT go out reading
 * as one that did.
 *
 * ONLY A NOTE THAT IS NOT INTERNAL TRAVELS. `timeline-entries-default-internal`
 * already decides which side of that line a note is on and defaults to
 * internal, so nothing leaves the municipality by accident. The marker read
 * here is that same one; no second flag is added, because two flags disagree
 * the first time somebody edits one. The note stays, and the case says so:
 * somebody asked for it to go out, and the case is where the next handler
 * looks for what became of it.
 *
 * EVERY WRITE ON THE CASE IS CHECKED. `CaseTimeline::record()` catches its own
 * failures, logs a warning and answers an empty string, so a caller that
 * ignores the answer reports an outcome it recorded nowhere. `answer()` reads
 * it, says `caseRecord: lost` and tells the caller in the same sentence the
 * reason travels in. See the note on `RECORD_LOST`.
 *
 * A DORMANT ADAPTER WRITES NO OUTCOME AT ALL. `LogZgwExternalAdapter` answers
 * `PUSH_DEFERRED` with `dormant: true` and contacts nothing, and it is what an
 * instance with no bound connector has. Recording "sent" for it would be a
 * marker that reads as a success on an adapter that contacted nobody, and
 * recording "failed" would be a marker on every note of every unbound
 * instance, which nobody would read. The honest answer on an unbound case is
 * no marker.
 *
 * WHAT IS STILL BLOCKED, said here rather than discovered later. The
 * informatieobjecttype a note is filed under needs a selectielijst position,
 * and that is a records-management choice: a note filed as a document inherits
 * a retention term, and the term for a working note is not the term for a
 * decision letter. So there is no default. The app config key
 * `note_informatieobjecttype` is unset until somebody answers, and an unset
 * key refuses the push and says which key to set (ADR-102), rather than
 * guessing a term that silently destroys notes or silently keeps them for
 * years.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Zgw
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
 * @spec openspec/changes/a-case-note-reaches-the-neighbouring-register/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Zgw;

use InvalidArgumentException;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pushes one case note to a neighbouring ZGW register, or says why it did not.
 *
 * @spec openspec/changes/a-case-note-reaches-the-neighbouring-register/specs/zgw-api-mapping/spec.md
 */
class NotePush {

	/**
	 * The app-config key naming the reserved informatieobjecttype for a note.
	 */
	public const TYPE_CONFIG_KEY = 'note_informatieobjecttype';

	/**
	 * The app-config key holding this organisation's RSIN.
	 */
	public const RSIN_CONFIG_KEY = 'bronorganisatie_rsin';

	/**
	 * The note is on a case no external register knows about, or the adapter
	 * contacts nothing. NO marker is written for this outcome.
	 */
	public const OUTCOME_NO_REGISTER = 'no-register';

	/**
	 * The note is internal and stays here.
	 */
	public const OUTCOME_NOT_SENT = 'not-sent';

	/**
	 * The note reached the neighbouring register.
	 */
	public const OUTCOME_SENT = 'sent';

	/**
	 * The push was attempted and refused, and the reason says why.
	 */
	public const OUTCOME_FAILED = 'failed';

	/**
	 * Nothing was due on the case, and nothing was written.
	 */
	public const RECORD_NONE = 'none';

	/**
	 * The outcome is on the case timeline.
	 */
	public const RECORD_WRITTEN = 'written';

	/**
	 * The outcome was due on the case and did not get there.
	 *
	 * 🔴 THIS STATE EXISTS BECAUSE THE WRITE CAN FAIL IN SILENCE.
	 * `CaseTimeline::record()` answers an empty string and logs a warning
	 * whenever OpenRegister is absent, the register or the case schema is
	 * unconfigured, the case cannot be read, or the write throws. A caller
	 * that ignores that answer reports an outcome it recorded nowhere, and the
	 * case history is then missing the one line saying a note did not leave.
	 * That is the digital-post evidence loss again, wearing a note.
	 */
	public const RECORD_LOST = 'lost';

	/**
	 * The sentence a caller gets when the case could not be told.
	 */
	private const RECORD_LOST_REASON = 'The case could not record this, so the case history does not show it. '
		. 'Check the dossiq log before you rely on the history.';

	/**
	 * Constructor.
	 *
	 * @param ZgwExternalAdapterInterface $adapter  The outbound ZGW seam.
	 * @param NoteEnvelope                $envelope Shapes a note as a document.
	 * @param CaseTimeline                $timeline Records the outcome on the case.
	 * @param IAppConfig                  $config   Holds the reserved type and the RSIN.
	 * @param LoggerInterface             $logger   Logger.
	 */
	public function __construct(
		private readonly ZgwExternalAdapterInterface $adapter,
		private readonly NoteEnvelope $envelope,
		private readonly CaseTimeline $timeline,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send one note, or say why it stayed here.
	 *
	 * @param string               $caseId The case the note is on.
	 * @param array<string, mixed> $note   The note as OpenRegister answers it.
	 *
	 * @return array{outcome: string, reason: string, receiverUrl: string, caseRecord: string} What happened.
	 *
	 * @spec openspec/changes/a-case-note-reaches-the-neighbouring-register/specs/zgw-api-mapping/spec.md
	 */
	public function push(string $caseId, array $note): array {
		if ($this->adapter->isDormant() === true) {
			// No marker: see the class docblock. This is the outcome a case
			// bound to nothing has, and it is not a failure.
			return $this->outcome(
				outcome: self::OUTCOME_NO_REGISTER,
				reason: 'This case is not bound to an external register, so nothing was sent '
					. 'and nothing is recorded.',
				caseRecord: self::RECORD_NONE,
			);
		}

		if ($this->isExternal(note: $note) === false) {
			// The DEFAULT, and deliberately so: nothing leaves by accident.
			// It is still recorded: somebody asked for this note to go out and
			// it stayed, and an answer only the caller's tab saw is an answer
			// nobody finds again.
			return $this->answer(
				caseId: $caseId,
				note: $note,
				message: 'Note kept here rather than sent to the neighbouring register',
				outcome: self::OUTCOME_NOT_SENT,
				reason: 'This note is internal, so it stays here. Make it external to send it.',
			);
		}

		try {
			$envelope = $this->envelope->build(
				note: $note,
				caseId: $caseId,
				informatieobjecttype: trim($this->config->getValueString('dossiq', self::TYPE_CONFIG_KEY, '')),
				bronorganisatie: trim($this->config->getValueString('dossiq', self::RSIN_CONFIG_KEY, '')),
			);
		} catch (InvalidArgumentException $e) {
			return $this->recordFailure(caseId: $caseId, note: $note, reason: $e->getMessage());
		}

		try {
			$result = $this->adapter->submitDocument(
				documentEnvelope: $envelope,
				context: ['caseId' => $caseId, 'noteId' => (string)($note['id'] ?? '')]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a case note could not be pushed to the neighbouring register',
				['caseId' => $caseId, 'error' => $e->getMessage()]
			);

			return $this->recordFailure(
				caseId: $caseId,
				note: $note,
				reason: 'The neighbouring register could not be reached.'
			);
		}

		if ($result->pushStatus !== 'PUSHED') {
			// 🔴 ANYTHING THAT IS NOT `PUSHED` IS A FAILURE, INCLUDING
			// `PUSH_DEFERRED`. A deferred push on a non-dormant adapter is a
			// note that did not arrive, and the dormant case was already
			// answered above; treating deferred as a success here would put a
			// sent marker on a note that stayed home, which is the single
			// thing this whole change exists to prevent.
			return $this->recordFailure(
				caseId: $caseId,
				note: $note,
				reason: $this->reasonOf(result: $result)
			);
		}

		return $this->answer(
			caseId: $caseId,
			note: $note,
			message: 'Note sent to the neighbouring register',
			outcome: self::OUTCOME_SENT,
			reason: '',
			receiverUrl: $result->receiverUrl
		);
	}//end push()

	/**
	 * Whether this note is one that may leave.
	 *
	 * Reads the marker `timeline-entries-default-internal` writes, and treats
	 * an absent or unreadable value as internal. Failing towards keeping a
	 * note here is the only safe direction: the other way sends a colleague's
	 * working note to another organisation.
	 *
	 * @param array<string, mixed> $note The note.
	 *
	 * @return bool True when the note is external.
	 */
	private function isExternal(array $note): bool {
		return (strtolower(trim((string)($note['visibility'] ?? ''))) === CaseTimeline::PUBLIC_ENTRY);
	}//end isExternal()

	/**
	 * The sentence a refused push leaves behind.
	 *
	 * The adapter's own `rejectionReason` whenever it gave one, because a
	 * handler who is told which field the receiver rejected can fix it, and a
	 * handler told "the push failed" cannot.
	 *
	 * @param ZgwPushResult $result The push result.
	 *
	 * @return string The reason.
	 */
	private function reasonOf(ZgwPushResult $result): string {
		$given = trim((string)($result->extras['rejectionReason'] ?? $result->extras['reason'] ?? ''));
		if ($given !== '') {
			return $given;
		}

		return 'The neighbouring register answered ' . $result->pushStatus . ' and gave no reason.';
	}//end reasonOf()

	/**
	 * Record a failure on the case and answer it.
	 *
	 * @param string               $caseId The case.
	 * @param array<string, mixed> $note   The note.
	 * @param string               $reason Why it failed.
	 *
	 * @return array{outcome: string, reason: string, receiverUrl: string, caseRecord: string} The outcome.
	 */
	private function recordFailure(string $caseId, array $note, string $reason): array {
		return $this->answer(
			caseId: $caseId,
			note: $note,
			message: 'Note not sent to the neighbouring register',
			outcome: self::OUTCOME_FAILED,
			reason: $reason
		);
	}//end recordFailure()

	/**
	 * Write the outcome on the case, and answer whether it got there.
	 *
	 * 🔴 THE TIMELINE WRITE IS CHECKED, NOT FIRED AND FORGOTTEN.
	 * `CaseTimeline::record()` catches its own failures, logs a warning and
	 * answers an empty string: no OpenRegister, no configured register or case
	 * schema, an unreadable case and a throwing write all look identical to a
	 * caller that ignores the return. Ignoring it here would answer `failed`
	 * to the person pushing and write nothing on the case, so tomorrow the
	 * history reads as though this note was never pushed at all. The one thing
	 * this change exists to prevent is a note that did not travel looking like
	 * one that did, and a lost record is that same failure one day later.
	 *
	 * @param string               $caseId      The case.
	 * @param array<string, mixed> $note        The note.
	 * @param string               $message     The sentence.
	 * @param string               $outcome     The outcome code.
	 * @param string               $reason      The reason, when there is one.
	 * @param string               $receiverUrl The receiver's url, on a send.
	 *
	 * @return array{outcome: string, reason: string, receiverUrl: string, caseRecord: string} The outcome.
	 */
	private function answer(
		string $caseId,
		array $note,
		string $message,
		string $outcome,
		string $reason,
		string $receiverUrl = '',
	): array {
		$written = $this->record(
			caseId: $caseId,
			note: $note,
			message: $message,
			outcome: $outcome,
			reason: $reason
		);

		if ($written === true) {
			return $this->outcome(
				outcome: $outcome,
				reason: $reason,
				receiverUrl: $receiverUrl,
				caseRecord: self::RECORD_WRITTEN
			);
		}

		$this->logger->error(
			'Dossiq: the outcome of a note push could not be recorded on the case',
			[
				'caseId' => $caseId,
				'noteId' => (string)($note['id'] ?? ''),
				'outcome' => $outcome,
			]
		);

		$told = self::RECORD_LOST_REASON;
		if ($reason !== '') {
			$told = ($reason . ' ' . self::RECORD_LOST_REASON);
		}

		return $this->outcome(
			outcome: $outcome,
			reason: $told,
			receiverUrl: $receiverUrl,
			caseRecord: self::RECORD_LOST
		);
	}//end answer()

	/**
	 * Write the outcome on the case timeline.
	 *
	 * INTERNAL, always: whether our push to another organisation worked is
	 * about our sending and not about what the citizen was told.
	 *
	 * @param string               $caseId  The case.
	 * @param array<string, mixed> $note    The note.
	 * @param string               $message The sentence.
	 * @param string               $outcome The outcome code.
	 * @param string               $reason  The reason, when there is one.
	 *
	 * @return bool True when an entry reached the case.
	 */
	private function record(string $caseId, array $note, string $message, string $outcome, string $reason): bool {
		$sentence = $message;
		if ($reason !== '') {
			$sentence = ($message . ': ' . $reason);
		}

		$entryId = $this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::MAIL_OUT,
			message: $sentence,
			fields: [
				'noteId' => (string)($note['id'] ?? ''),
				'status' => $outcome,
			],
			visibility: CaseTimeline::INTERNAL,
		);

		return (trim($entryId) !== '');
	}//end record()

	/**
	 * One outcome, in the shape every caller reads.
	 *
	 * @param string $outcome     The outcome code.
	 * @param string $reason      The reason, when there is one.
	 * @param string $receiverUrl The receiver's url, on a send.
	 * @param string $caseRecord  Whether the case was told: written, lost or none.
	 *
	 * @return array{outcome: string, reason: string, receiverUrl: string, caseRecord: string} The outcome.
	 */
	private function outcome(
		string $outcome,
		string $reason,
		string $receiverUrl = '',
		string $caseRecord = self::RECORD_NONE,
	): array {
		return [
			'outcome' => $outcome,
			'reason' => $reason,
			'receiverUrl' => $receiverUrl,
			'caseRecord' => $caseRecord,
		];
	}//end outcome()
}//end class
