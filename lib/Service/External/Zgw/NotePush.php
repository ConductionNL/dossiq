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
 * the first time somebody edits one.
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
	 * @return array{outcome: string, reason: string, receiverUrl: string} What happened.
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
			);
		}

		if ($this->isExternal(note: $note) === false) {
			// The DEFAULT, and deliberately so: nothing leaves by accident.
			return $this->outcome(
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

		$this->record(
			caseId: $caseId,
			note: $note,
			message: 'Note sent to the neighbouring register',
			outcome: self::OUTCOME_SENT,
			reason: ''
		);

		return $this->outcome(
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
	 * @return array{outcome: string, reason: string, receiverUrl: string} The outcome.
	 */
	private function recordFailure(string $caseId, array $note, string $reason): array {
		$this->record(
			caseId: $caseId,
			note: $note,
			message: 'Note not sent to the neighbouring register',
			outcome: self::OUTCOME_FAILED,
			reason: $reason
		);

		return $this->outcome(outcome: self::OUTCOME_FAILED, reason: $reason);
	}//end recordFailure()

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
	 * @return void
	 */
	private function record(string $caseId, array $note, string $message, string $outcome, string $reason): void {
		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::MAIL_OUT,
			message: ($reason === '') ? $message : ($message . ': ' . $reason),
			fields: [
				'noteId' => (string)($note['id'] ?? ''),
				'status' => $outcome,
			],
			visibility: CaseTimeline::INTERNAL,
		);
	}//end record()

	/**
	 * One outcome, in the shape every caller reads.
	 *
	 * @param string $outcome     The outcome code.
	 * @param string $reason      The reason, when there is one.
	 * @param string $receiverUrl The receiver's url, on a send.
	 *
	 * @return array{outcome: string, reason: string, receiverUrl: string} The outcome.
	 */
	private function outcome(string $outcome, string $reason, string $receiverUrl = ''): array {
		return ['outcome' => $outcome, 'reason' => $reason, 'receiverUrl' => $receiverUrl];
	}//end outcome()
}//end class
