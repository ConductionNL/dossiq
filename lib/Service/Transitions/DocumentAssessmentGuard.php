<?php

/**
 * Dossiq document assessment guard evaluator.
 *
 * Guard config shape: `{type: 'documentAssessment', require: 'gathered'}`,
 * `{type: 'documentAssessment', require: 'assessed'}` or
 * `{type: 'documentAssessment', require: 'redacted'}`. Reads the documents
 * under review on the case and the ones still waiting for an assessment, the
 * same answer `/api/cases/{id}/document-assessments/outstanding` gives, and
 * the partly public documents still waiting for a redacted copy.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\Assessment\DocumentSearchOutcome;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guard: the case's documents are gathered, or gathered and every one assessed.
 *
 * Generic: any case type that reviews documents per document (its
 * `documentReview`) can put it on a move. Two requirements:
 *
 * - `gathered`: at least one document is under review on the case.
 * - `assessed`: at least one document is under review and none waits for an
 *   assessment. A case with nothing gathered is not "all assessed", because
 *   an empty set passes every "all" and that is how a case reached its
 *   decision with no documents at all (13 October, Woo request).
 * - `redacted`: every document assessed as partly public has a redacted copy
 *   (`redactedDocumentRef`), the only version the publication delivers.
 *
 * 🔴 FAILS CLOSED. A register that cannot be read is a refusal naming that,
 * never a pass: the move it guards is the one that says the work is done.
 *
 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class DocumentAssessmentGuard implements GuardEvaluatorInterface {
	/**
	 * The requirement: at least one document under review.
	 */
	public const REQUIRE_GATHERED = 'gathered';

	/**
	 * The requirement: documents under review and none outstanding.
	 */
	public const REQUIRE_ASSESSED = 'assessed';

	/**
	 * The requirement: every partly public document has a redacted copy.
	 */
	public const REQUIRE_REDACTED = 'redacted';

	/**
	 * Constructor.
	 *
	 * @param WOODocumentAssessmentService $assessments The documents under review and the outstanding ones.
	 * @param LoggerInterface              $logger      Where an unreadable register is reported.
	 * @param IL10N                        $l10n        Translates the refusals.
	 * @param DocumentSearchOutcome        $searchOutcome Whether the case records that its search found nothing.
	 */
	public function __construct(
		private readonly WOODocumentAssessmentService $assessments,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
		private readonly DocumentSearchOutcome $searchOutcome = new DocumentSearchOutcome(),
	) {
	}//end __construct()

	/**
	 * Evaluate the document assessment guard.
	 *
	 * @param array<string, mixed> $guardConfig Guard configuration
	 * @param array<string, mixed> $case        Case object
	 * @param string               $userId      Current user UID (unused)
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
	 */
	public function evaluate(array $guardConfig, array $case, string $userId): GuardResult {
		$require = (string)($guardConfig['require'] ?? self::REQUIRE_ASSESSED);
		if (in_array($require, [self::REQUIRE_GATHERED, self::REQUIRE_ASSESSED, self::REQUIRE_REDACTED], true) === false) {
			return new GuardResult(
				passed: false,
				failureMessage: $this->l10n->t('Document assessment guard with an unknown requirement: %s', [$require]),
				details: ['require' => $require],
			);
		}

		// D1: a recorded "nothing found", with its note, is the answer for a
		// case whose search found no documents. It counts for both requirements.
		if ($this->searchOutcome->recordsNothingFound(case: $case) === true) {
			return new GuardResult(passed: true, details: ['require' => $require, 'nothingFound' => true]);
		}

		$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ($case['@self']['id'] ?? '')));
		if ($caseId === '') {
			return new GuardResult(passed: false, failureMessage: $this->l10n->t('Case not recognised for the document check'));
		}

		if ($require === self::REQUIRE_REDACTED) {
			return $this->redacted(caseId: $caseId);
		}

		try {
			$underReview = $this->assessments->getDocumentsUnderReview(caseId: $caseId);
			$outstanding = ['count' => 0];
			if ($require === self::REQUIRE_ASSESSED) {
				$outstanding = $this->assessments->getOutstanding(caseId: $caseId);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the document assessment guard could not read the documents of a case',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);

			return new GuardResult(
				passed: false,
				failureMessage: $this->l10n->t('The documents of this case could not be read'),
				details: ['require' => $require, 'unreadable' => true],
			);
		}

		if ($underReview === []) {
			return new GuardResult(
				passed: false,
				failureMessage: $this->l10n->t('No documents have been collected yet. Found nothing? Record that with an explanation'),
				details: ['require' => $require, 'documents' => 0],
			);
		}

		$waiting = (int)($outstanding['count'] ?? 0);
		if ($waiting > 0) {
			return new GuardResult(
				passed: false,
				failureMessage: $this->l10n->t('%1$s of %2$s documents are still waiting for an assessment', [(string)$waiting, (string)count($underReview)]),
				details: ['require' => $require, 'documents' => count($underReview), 'outstanding' => $waiting],
			);
		}

		return new GuardResult(
			passed: true,
			details: ['require' => $require, 'documents' => count($underReview)],
		);
	}//end evaluate()

	/**
	 * Whether every partly public document of the case has a redacted copy.
	 *
	 * @param string $caseId The case.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md#requirement-a-move-may-require-every-partly-public-document-redacted
	 */
	private function redacted(string $caseId): GuardResult {
		try {
			$copies = $this->assessments->partlyPublicWithoutCopy(caseId: $caseId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the document assessment guard could not read the redactions of a case',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);

			return new GuardResult(
				passed: false,
				failureMessage: $this->l10n->t('The documents of this case could not be read'),
				details: ['require' => self::REQUIRE_REDACTED, 'unreadable' => true],
			);
		}

		$waiting = count($copies['waiting']);
		if ($waiting > 0) {
			return new GuardResult(
				passed: false,
				failureMessage: $this->l10n->t(
					'%1$s of %2$s partly public documents are still waiting for a redacted version',
					[(string)$waiting, (string)$copies['partlyPublic']]
				),
				details: ['require' => self::REQUIRE_REDACTED, 'partlyPublic' => $copies['partlyPublic'], 'waiting' => $waiting],
			);
		}

		return new GuardResult(
			passed: true,
			details: ['require' => self::REQUIRE_REDACTED, 'partlyPublic' => $copies['partlyPublic']],
		);
	}//end redacted()
}//end class
