<?php

/**
 * Dossiq Assessment Batch Writer
 *
 * Validates, stamps and stores a batch of document assessments on one case,
 * under the reviewer rule, and marks the judged documents in scope.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Assessment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Assessment;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\DocumentRelevanceInterface;
use OCA\Dossiq\Review\DisclosureAssessmentValidator;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\AssessmentBatchTarget;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Stores a batch of assessments on one case.
 *
 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class AssessmentBatchWriter {

	use SearchesObjects;

	/**
	 * The keys that name a stored object, dropped from each caller assessment
	 * (Support\CallerIdentity::KEYS, written out to keep this class's coupling down).
	 */
	private const CALLER_IDENTITY = ['id' => true, 'uuid' => true, '@self' => true];

	/**
	 * Checks an assessment before it is stored.
	 *
	 * @var DisclosureAssessmentValidator
	 */
	private readonly DisclosureAssessmentValidator $validator;

	/**
	 * Constructor.
	 *
	 * @param SettingsService                 $settingsService Where OpenRegister, the register and the schema are configured.
	 * @param LoggerInterface                 $logger          Logger.
	 * @param WooRefusalGrounds               $refusalGrounds  The settled list a cited ground is checked against.
	 * @param CaseReviewerAccess|null         $reviewerAccess  Who may change an assessment; null lets everyone.
	 * @param DocumentRelevanceInterface|null $reviews         Each document's relevance and pages seen; null skips both.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		WooRefusalGrounds $refusalGrounds,
		private readonly ?CaseReviewerAccess $reviewerAccess = null,
		private readonly ?DocumentRelevanceInterface $reviews = null,
	) {
		$this->validator = new DisclosureAssessmentValidator(refusalGrounds: $refusalGrounds);
	}//end __construct()

	/**
	 * Validate a single assessment payload.
	 *
	 * @param array<string, mixed> $assessment The assessment to validate
	 *
	 * @return array<string, string> Validation errors keyed by field name; empty if valid
	 *
	 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-dossiq-validates-assessments-against-the-list-req-wrg-005
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function validate(array $assessment): array {
		return $this->validator->validate(assessment: $assessment);
	}//end validate()

	/**
	 * Store each assessment of the batch on the case, then reconcile the reviewer
	 * access and mark the judged documents in scope.
	 *
	 * @param string                           $caseId      The case UUID.
	 * @param array<int, array<string, mixed>> $assessments The assessment payloads.
	 * @param string                           $userId      Who gives the verdicts.
	 *
	 * @return array{saved: array<int, mixed>, errors: array<int, mixed>} What was saved and what was refused.
	 *
	 * @throws RuntimeException If OpenRegister is unavailable or the schema is not configured.
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-reviewer-changes-only-the-records-they-made-req-cra-003
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function write(string $caseId, array $assessments, string $userId): array {
		$location = $this->assessmentTarget();
		$target = new AssessmentBatchTarget(
			objectService: $location['objectService'],
			register: $location['register'],
			schema: $location['schema'],
			userId: $userId,
			caseId: $caseId
		);

		$tally = $this->upsertAll(assessments: $assessments, target: $target);

		$this->reviewerAccess?->reconcileAfterCreate(caseId: $caseId, created: $tally['created']);
		$this->markJudgedInScope(target: $target, documentRefs: $tally['judged']);

		return ['saved' => $tally['saved'], 'errors' => $tally['errors']];
	}//end write()

	/**
	 * Upsert each assessment in turn, tallying what was saved, created and judged, and what failed.
	 *
	 * @param array<int, array<string, mixed>> $assessments Array of assessment payloads
	 * @param AssessmentBatchTarget            $target      Where assessments are stored, on which case, and by whom
	 *
	 * @return array{saved: array<int, mixed>, errors: array<int, mixed>, created: int, judged: array<int, mixed>} The tally.
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
	 */
	private function upsertAll(array $assessments, AssessmentBatchTarget $target): array {
		$tally = ['saved' => [], 'errors' => [], 'created' => 0, 'judged' => []];
		foreach ($assessments as $assessment) {
			$result = $this->upsertAssessment(assessment: (array)$assessment, target: $target);
			if (isset($result['error']) === true) {
				$tally['errors'][] = $result['error'];
				continue;
			}

			$tally['saved'][] = $result['saved'];
			$tally['created'] += $result['created'];
			$tally['judged'][] = $result['documentRef'];
		}

		return $tally;
	}//end upsertAll()

	/**
	 * Where assessments are stored: the object service, register and schema.
	 *
	 * @return array<string, mixed> The object service, register and schema.
	 *
	 * @throws RuntimeException If OpenRegister is unavailable or the schema is not configured.
	 */
	private function assessmentTarget(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');

		if (empty($register) === true || empty($assessmentSchema) === true) {
			throw new RuntimeException('WOO assessment schema not configured');
		}

		return ['objectService' => $objectService, 'register' => $register, 'schema' => $assessmentSchema];
	}//end assessmentTarget()

	/**
	 * Validate, stamp and store one assessment.
	 *
	 * @param array<string, mixed>  $assessment The assessment payload.
	 * @param AssessmentBatchTarget $target     The storage target, the case and the acting user id.
	 *
	 * @return array<string, mixed> `{error}` or `{saved, created, documentRef}`.
	 */
	private function upsertAssessment(array $assessment, AssessmentBatchTarget $target): array {
		$caseId = $target->caseId;
		[$assessment, $unseen] = [array_diff_key($assessment, self::CALLER_IDENTITY), []];
		if ($this->reviews !== null) {
			$unseen = $this->reviews->pages()->refusalFor(caseId: $caseId, assessment: $assessment);
		}

		$validationErrors = $this->validate(assessment: $assessment) + $unseen;
		if (empty($validationErrors) === false) {
			return ['error' => ['documentRef' => $assessment['documentRef'] ?? 'unknown', 'errors' => $validationErrors]];
		}

		$assessment['caseRef'] = $caseId;
		$assessment['groundsListVersion'] = WooRefusalGrounds::LIST_VERSION;
		$assessment['assessedBy'] = $target->userId;
		$assessment['assessedAt'] = date('Y-m-d\TH:i:s');

		return $this->storeAssessment(assessment: $assessment, target: $target);
	}//end upsertAssessment()

	/**
	 * Update the case's assessment of the document, or create one.
	 *
	 * The existing assessment is read regardless of the caller, so an
	 * assessment the caller cannot see is never assessed a second time
	 * (REQ-CRA-003); only its uuid and owner are used, nothing of it is answered.
	 *
	 * @param array<string, mixed>  $assessment The stamped assessment.
	 * @param AssessmentBatchTarget $target     The storage target, the case and the acting user id.
	 *
	 * @return array<string, mixed> `{error}` or `{saved, created, documentRef}`.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function storeAssessment(array $assessment, AssessmentBatchTarget $target): array {
		$existing = $this->existingAssessments(assessment: $assessment, target: $target);

		$uuid = null;
		if (count($existing) > 0) {
			$refusal = $this->refusalOn(record: $existing[0], assessment: $assessment, target: $target);
			if ($refusal !== null) {
				return ['error' => $refusal];
			}

			$uuid = (string)($existing[0]['id'] ?? $existing[0]['uuid'] ?? null);
		}

		return $target->save(assessment: $assessment, uuid: $uuid);
	}//end storeAssessment()

	/**
	 * The case's stored assessments of the document, whoever can see them.
	 *
	 * @param array<string, mixed>  $assessment The stamped assessment.
	 * @param AssessmentBatchTarget $target     The storage target, the case and the acting user id.
	 *
	 * @return array<int, array<string, mixed>> At most one assessment.
	 */
	private function existingAssessments(array $assessment, AssessmentBatchTarget $target): array {
		return $this->searchObjectsAsArraysUnscoped(
			objectService: $target->objectService,
			register: $target->register,
			schema: $target->schema,
			filters: ['caseRef' => $target->caseId, 'documentRef' => $assessment['documentRef'], '_limit' => 1],
		);
	}//end existingAssessments()

	/**
	 * Why the acting user may not change the stored assessment, or null when they may.
	 *
	 * @param array<string, mixed>  $record     The stored assessment.
	 * @param array<string, mixed>  $assessment The stamped assessment.
	 * @param AssessmentBatchTarget $target     The storage target, the case and the acting user id.
	 *
	 * @return mixed The refusal, or null.
	 */
	private function refusalOn(array $record, array $assessment, AssessmentBatchTarget $target): mixed {
		return $this->reviewerAccess?->refusalFor(
			caseId: $target->caseId,
			record: $record,
			userId: $target->userId,
			reference: (string)$assessment['documentRef']
		);
	}//end refusalOn()

	/**
	 * Mark the documents that just received a verdict in scope, where nobody marked them yet.
	 *
	 * A review that cannot be written leaves the verdict saved and the
	 * document outstanding, which the answer's outstanding list then says.
	 *
	 * @param AssessmentBatchTarget $target       The case and who gave the verdict.
	 * @param list<string>          $documentRefs The documents with a saved verdict.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-verdict-marks-its-document-in-scope/specs/document-review/spec.md#requirement-a-verdict-on-an-unmarked-document-marks-it-in-scope-req-wrt-006
	 */
	private function markJudgedInScope(AssessmentBatchTarget $target, array $documentRefs): void {
		$caseId = $target->caseId;
		if ($this->reviews === null) {
			return;
		}

		try {
			$this->reviews->markInScopeWhereUnmarked(caseId: $caseId, documentRefs: $documentRefs, userId: $target->userId);
		} catch (RefusedException $e) {
			$this->logger->warning(
				'WOO bulk-upsert: the judged documents could not be marked in scope for case ' . $caseId,
				['app' => AppIdentity::APP_ID, 'exception' => $e->getMessage()],
			);
		}
	}//end markJudgedInScope()
}//end class
