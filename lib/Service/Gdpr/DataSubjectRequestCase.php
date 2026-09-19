<?php

/**
 * Dossiq Data Subject Request Case.
 *
 * The case side of an AVG request. A person writes to the gemeente asking what
 * we hold about them, asking us to correct it, or asking us to erase it, and
 * what arrives is a letter, not an API call. So it is handled as a case: it
 * has a handler, a statutory month, a status, a timeline and an audit trail,
 * exactly like every other thing a gemeente is asked to do.
 *
 * What this class adds to the case is the driving. Preparing a verwijdering
 * asks OpenRegister for an erasure preview and writes what the platform
 * answered onto the case, protected records and all. Running it asks
 * OpenRegister to erase, and writes back what it did and what it could not do.
 * Answering an inzage asks OpenRegister for the subject's own export and
 * offers it while the platform says it can still be taken.
 *
 * 🔑 THE APPROVAL IS AN ACT ON THE CASE, READ FROM THE ENGINE'S OWN CHAIN.
 * {@see run()} does not take anybody's word that the erasure was approved: it
 * reads the case's status records and looks for the approving transition,
 * which is the same chain `FourEyesRule` refuses the preparer from. A flag on
 * the case saying "approved" would be a second truth, and the first thing that
 * happens to a second truth is that somebody sets it directly.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: erase, pseudonymise, assemble an export,
 * or decide what a legal hold means. Those are OpenRegister's, through
 * {@see PlatformDataSubjectRights}. This class holds no destruction path at
 * all, which is why the one it drives is the same one the delete window
 * records.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Gdpr
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
 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Gdpr;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\FourEyesRule;

/**
 * Drives OpenRegister's data subject rights from a dossiq case.
 *
 * @psalm-suppress UnusedClass Injected into DataSubjectRequestController.
 *
 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
 */
class DataSubjectRequestCase {

	/**
	 * The label of the transition that prepares an erasure.
	 *
	 * Shipped by `lib/Settings/templates/avg-verzoek.json` and named here
	 * because the same label is what the approving transition's
	 * `notPerformedBy` points at. Two spellings of one act is how four eyes
	 * becomes a rule that never fires.
	 *
	 * @var string
	 */
	public const ACT_PREPARE = 'Verwijdering voorbereiden';

	/**
	 * The label of the transition that approves an erasure.
	 *
	 * @var string
	 */
	public const ACT_APPROVE = 'Verwijdering goedkeuren';

	/**
	 * The request kind that asks us to erase, AVG art. 17.
	 *
	 * @var string
	 */
	public const KIND_VERWIJDERING = 'verwijdering';

	/**
	 * Constructor.
	 *
	 * @param PlatformDataSubjectRights $platform  OpenRegister's data subject rights.
	 * @param CaseStatusStore           $cases     The case and its status record chain.
	 * @param FourEyesRule              $fourEyes  Who performed which act on this case.
	 * @param CaseTimeline              $timeline  The one timeline on the case.
	 */
	public function __construct(
		private readonly PlatformDataSubjectRights $platform,
		private readonly CaseStatusStore $cases,
		private readonly FourEyesRule $fourEyes,
		private readonly CaseTimeline $timeline,
	) {
	}//end __construct()

	/**
	 * Ask the platform what erasing this subject would touch, and record it.
	 *
	 * @param string $caseId The data subject request case.
	 *
	 * @return array<string, mixed> The preview, as the platform recorded it.
	 *
	 * @throws RefusedException When the case names no subject, or the platform refuses.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	public function preview(string $caseId): array {
		$case = $this->requireCase(caseId: $caseId);
		// A preview on an inzage case would be an erasure preview nobody
		// asked for, sitting on a case whose closing transition does not read
		// it. The kind is what the whole case type branches on, so it is
		// checked here rather than left to the screen that offered the button.
		if (trim((string)($case['dataSubjectRequestType'] ?? '')) !== self::KIND_VERWIJDERING) {
			throw new RefusedException(
				rule: 'not-an-erasure-request',
				sentence: 'Only a verwijdering is previewed. This case asks for something else.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		$subject = trim((string)($case['dataSubject'] ?? ''));
		if ($subject === '') {
			throw new RefusedException(
				rule: 'data-subject-missing',
				sentence: 'This case names no data subject, so there is nothing to look for.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$preview = $this->platform->previewErasure(
			subject: $subject,
			type: $this->optional(value: ($case['dataSubjectType'] ?? null)),
			eraseMode: (string)($case['erasureMode'] ?? PlatformDataSubjectRights::MODE_PSEUDONYMISE),
			requestId: $caseId,
		);

		$report = (array)($preview['report'] ?? []);
		$counts = (array)($report['counts'] ?? []);
		$protected = (array)($report['protected'] ?? []);

		$case['erasurePreviewId'] = (string)($preview['uuid'] ?? '');
		$case['erasureDigest'] = (string)($preview['digest'] ?? '');
		$case['erasureCounts'] = $counts;
		$case['erasureProtected'] = $protected;
		// A new preview makes the previous run's report a report about a world
		// that no longer exists, and leaving it would let the close guard read
		// a complete run as covering counts nobody has approved.
		$case['erasureOutcome'] = [];
		$this->cases->saveCase($case);

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::DATA_SUBJECT_REQUEST,
			message: 'The erasure preview was taken: ' . $this->countSentence(counts: $counts),
			fields: [
				'act' => 'preview',
				'previewId' => (string)($preview['uuid'] ?? ''),
				'erasable' => $this->bucketTotal(counts: $counts, bucket: 'erasable'),
				'pseudonymised' => $this->bucketTotal(counts: $counts, bucket: 'pseudonymised'),
				'protected' => count($protected),
			],
		);

		return $preview;
	}//end preview()

	/**
	 * Run the approved erasure, and write back what the platform did.
	 *
	 * @param string $caseId The data subject request case.
	 *
	 * @return array<string, mixed> The run report.
	 *
	 * @throws RefusedException When the approving act was not taken, or the platform refuses.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	public function run(string $caseId): array {
		$case = $this->requireCase(caseId: $caseId);
		$previewId = trim((string)($case['erasurePreviewId'] ?? ''));
		if ($previewId === '') {
			throw new RefusedException(
				rule: 'erasure-preview-unknown',
				sentence: 'This case carries no erasure preview. Prepare the erasure first.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$approval = $this->approvingAct(caseId: $caseId);

		$this->platform->approvePreview(previewId: $previewId);
		$outcome = $this->platform->runErasure(previewId: $previewId);

		$case['erasureOutcome'] = $outcome;
		$case['erasureApprovedBy'] = $approval['actor'];
		$case['erasureApprovedAt'] = $approval['at'];
		$this->cases->saveCase($case);

		$withheld = $this->leftBehind(outcome: $outcome);
		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::DATA_SUBJECT_REQUEST,
			message: $this->runSentence(outcome: $outcome, withheld: $withheld),
			fields: [
				'act' => 'run',
				'previewId' => $previewId,
				'withheld' => $withheld,
				'complete' => (($outcome['complete'] ?? false) === true),
			],
		);

		return $outcome;
	}//end run()

	/**
	 * Ask the platform for this subject's own export, and record it.
	 *
	 * @param string $caseId The data subject request case.
	 *
	 * @return array<string, mixed> The export record.
	 *
	 * @throws RefusedException When the case names no subject, or the platform refuses.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	public function requestExport(string $caseId): array {
		$case = $this->requireCase(caseId: $caseId);
		// A verwijdering is answered by erasing, not by handing the person a
		// copy of what we are about to destroy. Inzage and correctie both
		// need the export: you cannot correct what you have not been shown.
		if (trim((string)($case['dataSubjectRequestType'] ?? '')) === self::KIND_VERWIJDERING) {
			throw new RefusedException(
				rule: 'not-an-access-request',
				sentence: 'A verwijdering is answered by erasing, not by an export.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		$subject = trim((string)($case['dataSubject'] ?? ''));
		if ($subject === '') {
			throw new RefusedException(
				rule: 'data-subject-missing',
				sentence: 'This case names no data subject, so there is nothing to export.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$export = $this->platform->requestExport(
			subject: $subject,
			type: $this->optional(value: ($case['dataSubjectType'] ?? null)),
			requestId: $caseId,
		);

		$this->writeExport(case: $case, export: $export);

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::DATA_SUBJECT_REQUEST,
			message: 'The subject export was asked for. It is ready when the platform says so.',
			fields: [
				'act' => 'export',
				'exportId' => (string)($export['uuid'] ?? ''),
			],
		);

		return $export;
	}//end requestExport()

	/**
	 * What the case can offer the data subject right now.
	 *
	 * 🔑 THE PLATFORM IS ASKED AGAIN RATHER THAN THE CASE BEING READ. The
	 * export has a seven day life and the case is a copy of a reading taken
	 * when it was made. Offering a link off that copy is offering a link that
	 * 404s in front of the person it was promised to.
	 *
	 * @param string $caseId The data subject request case.
	 *
	 * @return array<string, mixed> `{exportId, downloadable, expiresAt, expired}`.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	public function exportState(string $caseId): array {
		$case = $this->requireCase(caseId: $caseId);
		$exportId = trim((string)($case['subjectExportId'] ?? ''));
		if ($exportId === '') {
			return ['exportId' => '', 'downloadable' => false, 'expiresAt' => '', 'expired' => false];
		}

		$export = $this->platform->export(exportId: $exportId);
		if ($export === []) {
			return ['exportId' => $exportId, 'downloadable' => false, 'expiresAt' => '', 'expired' => true];
		}

		$this->writeExport(case: $case, export: $export);

		$downloadable = (($export['downloadable'] ?? false) === true);

		return [
			'exportId' => $exportId,
			'downloadable' => $downloadable,
			'expiresAt' => (string)($export['expiresAt'] ?? ''),
			// Expired is NOT the negation of downloadable: an export still
			// being assembled is neither, and telling a handler it expired
			// would send them to ask for a second one that also has to be
			// assembled.
			'expired' => ($downloadable === false && trim((string)($export['readyAt'] ?? '')) !== ''),
		];
	}//end exportState()

	/**
	 * The approving act on this case, or a refusal naming what is missing.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array{actor: string, at: string} Who approved, and when.
	 *
	 * @throws RefusedException When nobody approved, or the preparer approved themselves.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	private function approvingAct(string $caseId): array {
		$records = (array)($this->cases->findStatusRecords(caseId: $caseId) ?? []);

		$approval = $this->fourEyes->performerOf(records: $records, act: self::ACT_APPROVE);
		if ($approval === null) {
			throw new RefusedException(
				rule: 'erasure-not-approved',
				sentence: 'Nobody has taken "' . self::ACT_APPROVE . '" on this case, so there is nothing to run.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		// The engine already withholds this transition from the preparer. This
		// asks the same question a second time on purpose: a case whose
		// approval was recorded before the declaration shipped carries an
		// approval the engine never checked, and the run is the last moment
		// anything can still refuse it.
		$preparation = $this->fourEyes->performerOf(records: $records, act: self::ACT_PREPARE);
		if ($preparation !== null && $preparation['actor'] === $approval['actor']) {
			throw new RefusedException(
				rule: 'erasure-four-eyes-broken',
				sentence: 'The person who prepared this erasure also approved it. Ask a colleague to approve it.',
				status: RefusedException::STATUS_FORBIDDEN,
			);
		}

		return $approval;
	}//end approvingAct()

	/**
	 * The case, or a refusal that says it could not be read.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	private function requireCase(string $caseId): array {
		$case = $this->cases->loadCase(caseId: $caseId);
		if (is_array($case) === false || $case === []) {
			throw new RefusedException(
				rule: 'case-unreadable',
				sentence: 'This case could not be read, so nothing was done.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return $case;
	}//end requireCase()

	/**
	 * Write the platform's export state onto the case.
	 *
	 * @param array<string, mixed> $case   The case.
	 * @param array<string, mixed> $export The platform's export record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	private function writeExport(array $case, array $export): void {
		$case['subjectExportId'] = (string)($export['uuid'] ?? '');
		$case['subjectExportExpiresAt'] = (string)($export['expiresAt'] ?? '');
		$case['subjectExportReady'] = (($export['downloadable'] ?? false) === true);
		$this->cases->saveCase($case);
	}//end writeExport()

	/**
	 * How many records a run left holding this person.
	 *
	 * @param array<string, mixed> $outcome The run report.
	 *
	 * @return int The withheld, refused and failed together.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	private function leftBehind(array $outcome): int {
		$total = 0;
		foreach (['withheld', 'refused', 'failed'] as $bucket) {
			$total += count((array)($outcome[$bucket] ?? []));
		}

		return $total;
	}//end leftBehind()

	/**
	 * The sentence a handler reads on the timeline after a run.
	 *
	 * @param array<string, mixed> $outcome  The run report.
	 * @param int                  $withheld How many records were left behind.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	private function runSentence(array $outcome, int $withheld): string {
		$destroyed = count((array)($outcome['destroyed'] ?? []));
		$pseudonymised = count((array)($outcome['pseudonymised'] ?? []));
		$sentence = 'The erasure ran: ' . $destroyed . ' destroyed, ' . $pseudonymised . ' pseudonymised';

		if (($outcome['complete'] ?? false) === true) {
			return $sentence . '. The platform reports it complete.';
		}

		return $sentence . ', ' . $withheld . ' left standing. The case stays open until they are answered for.';
	}//end runSentence()

	/**
	 * The sentence a handler reads on the timeline after a preview.
	 *
	 * @param array<string, mixed> $counts The platform's counts.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	private function countSentence(array $counts): string {
		return $this->bucketTotal(counts: $counts, bucket: 'erasable') . ' erasable, '
			. $this->bucketTotal(counts: $counts, bucket: 'pseudonymised') . ' to pseudonymise, '
			. $this->bucketTotal(counts: $counts, bucket: 'protected') . ' protected.';
	}//end countSentence()

	/**
	 * One bucket of the platform's counts, added over its four kinds of thing.
	 *
	 * @param array<string, mixed> $counts The platform's counts.
	 * @param string               $bucket `erasable`, `pseudonymised` or `protected`.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	private function bucketTotal(array $counts, string $bucket): int {
		$total = 0;
		foreach ((array)($counts[$bucket] ?? []) as $value) {
			$total += (int)$value;
		}

		return $total;
	}//end bucketTotal()

	/**
	 * A trimmed value, or null when it is empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	private function optional(mixed $value): ?string {
		$text = trim((string)($value ?? ''));
		if ($text === '') {
			return null;
		}

		return $text;
	}//end optional()
}//end class
