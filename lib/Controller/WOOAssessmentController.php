<?php

/**
 * Dossiq WOO Assessment Controller
 *
 * REST API for WOO-specific case operations: per-document disclosure
 * assessment, deadline extension, and besluit assembly.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-5
 * @spec openspec/changes/woo-case-type/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\WOOAnonymisationAssistService;
use OCA\Dossiq\Service\WOODeadlineService;
use OCA\Dossiq\Service\WOODecisionService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\WooPublicationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for WOO document assessment, deadline extension, besluit, and publication.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — one focused service per WOO
 * sub-capability (assessment/deadline/decision/publication); each dependency is
 * used, none is a redundant pass-through (ADR-022).
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) — constructor DI: every
 * parameter is a distinct, independently-used collaborator (same rationale
 * as CouplingBetweenObjects above); `woo-llm-anonymisation` adds one more
 * (`WOOAnonymisationAssistService`) to an already-wide, long-established list.
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-5
 * @spec openspec/changes/woo-case-type/tasks.md#task-7
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */
class WOOAssessmentController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request
	 * @param WOODocumentAssessmentService $assessmentService Document assessment service
	 * @param WOODeadlineService $deadlineService Deadline service
	 * @param WOODecisionService $decisionService Decision service
	 * @param WooPublicationService $publicationService WOO publication (via OpenCatalogi) service
	 * @param WOOAnonymisationAssistService $anonymisationAssist LLM-assisted redaction-span proposal
	 *                                                           service (woo-llm-anonymisation)
	 * @param IUserSession $userSession Current user session
	 * @param CaseAccessGuard $caseAccessGuard Per-case mutation authorization (fails closed)
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WOODocumentAssessmentService $assessmentService,
		private readonly WOODeadlineService $deadlineService,
		private readonly WOODecisionService $decisionService,
		private readonly WooPublicationService $publicationService,
		private readonly WOOAnonymisationAssistService $anonymisationAssist,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Bulk-upsert document assessments for a WOO case.
	 *
	 * @param string $id The case UUID
	 *
	 * @return JSONResponse Saved assessments and outstanding document count
	 *
	 * @throws OCSForbiddenException If user is not authenticated or not authorized
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-5
	 */
	#[NoAdminRequired]
	public function bulkAssess(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$this->requireCaseMutationAccess(caseId: $id, user: $user);

		$assessments = $this->request->getParam('assessments', []);
		if (is_string($assessments) === true) {
			$assessments = json_decode($assessments, true) ?? [];
		}

		try {
			$result = $this->assessmentService->bulkUpsert(
				caseId: $id,
				assessments: $assessments,
			);
			return new JSONResponse($result);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end bulkAssess()

	/**
	 * Extend the WOO deadline for a case.
	 *
	 * @param string $id The case UUID
	 *
	 * @return JSONResponse Updated deadline info
	 *
	 * @throws OCSForbiddenException If user is not authenticated or not authorized
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-4
	 */
	#[NoAdminRequired]
	public function extendDeadline(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$this->requireCaseMutationAccess(caseId: $id, user: $user);

		$reason = $this->request->getParam('reason', '');

		try {
			$result = $this->deadlineService->extendDeadline(caseId: $id, reason: $reason);
			return new JSONResponse($result);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end extendDeadline()

	/**
	 * Assemble the formal WOO besluit for a case.
	 *
	 * @param string $id The case UUID
	 *
	 * @return JSONResponse Created decision with assessment summary
	 *
	 * @throws OCSForbiddenException If user is not authenticated or not authorized
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-7
	 */
	#[NoAdminRequired]
	public function createDecision(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$this->requireCaseMutationAccess(caseId: $id, user: $user);

		$decisionData = $this->request->getParam('decision', []);
		if (is_string($decisionData) === true) {
			$decisionData = json_decode($decisionData, true) ?? [];
		}

		try {
			$result = $this->decisionService->assembleDecision(
				caseId: $id,
				decisionData: $decisionData,
			);
			return new JSONResponse($result);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\RuntimeException $e) {
			$this->logger->error(
				'WOO besluit assembly failed: ' . $e->getMessage(),
				['app' => 'dossiq','caseId' => $id],
			);
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end createDecision()

	/**
	 * Publish an assembled WOO decision to OpenCatalogi.
	 *
	 * @param string $id The case UUID
	 *
	 * @return JSONResponse `{available, reason?, publicationId?, publicationUrl?}`
	 *
	 * @throws OCSForbiddenException If user is not authenticated or not authorized
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	#[NoAdminRequired]
	public function publishDecision(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$this->requireCaseMutationAccess(caseId: $id, user: $user);

		$decisionId = (string)$this->request->getParam('decisionId', '');
		if ($decisionId === '') {
			return new JSONResponse(['error' => 'decisionId is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->publicationService->publish(caseId: $id, decisionId: $decisionId);
			return new JSONResponse($result);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end publishDecision()

	/**
	 * Withdraw (depublish) a previously published WOO decision.
	 *
	 * @param string $id The case UUID
	 *
	 * @return JSONResponse `{available, reason?}`
	 *
	 * @throws OCSForbiddenException If user is not authenticated or not authorized
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	#[NoAdminRequired]
	public function withdrawPublication(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$this->requireCaseMutationAccess(caseId: $id, user: $user);

		$decisionId = (string)$this->request->getParam('decisionId', '');
		if ($decisionId === '') {
			return new JSONResponse(['error' => 'decisionId is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->publicationService->withdraw(decisionId: $decisionId);
			return new JSONResponse($result);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end withdrawPublication()

	/**
	 * Request an LLM-assisted redaction-span proposal for a document
	 * (woo-llm-anonymisation). ASSISTS the existing `WOORedactionService` —
	 * never replaces it, never publishes, never marks anything
	 * "anonymised". Always returns a proposal (rules-only when Hermiq is
	 * unavailable or fails) awaiting human review.
	 *
	 * @param string $id The case UUID
	 * @param string $documentRef The document UUID
	 *
	 * @return JSONResponse The proposal `{spans, source, llmAvailable, llmError?, status}`
	 *
	 * @throws OCSForbiddenException If user is not authenticated or not authorized
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-4
	 */
	#[NoAdminRequired]
	public function proposeRedaction(string $id, string $documentRef): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$this->requireCaseMutationAccess(caseId: $id, user: $user);

		$text = (string)$this->request->getParam('text', '');

		try {
			$result = $this->anonymisationAssist->proposeSpans(
				caseId: $id,
				documentRef: $documentRef,
				text: $text,
				userId: $user->getUID(),
			);
			return new JSONResponse($result);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			$this->logger->warning(
				'WOO redaction proposal failed: ' . $e->getMessage(),
				['app' => 'dossiq','caseId' => $id, 'documentRef' => $documentRef],
			);
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end proposeRedaction()

	/**
	 * Record a human reviewer's approve/reject decision on a pending
	 * redaction proposal. On approve, hands the reviewed spans to the
	 * EXISTING, unchanged `WOORedactionService` pipeline as guidance — the
	 * redaction execution itself is entirely unaffected by this feature.
	 *
	 * @param string $id The case UUID
	 * @param string $documentRef The document UUID
	 *
	 * @return JSONResponse The updated proposal record
	 *
	 * @throws OCSForbiddenException If user is not authenticated or not authorized
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-4
	 */
	#[NoAdminRequired]
	public function reviewRedactionProposal(string $id, string $documentRef): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$this->requireCaseMutationAccess(caseId: $id, user: $user);

		$decision = (string)$this->request->getParam('decision', '');
		$editedSpans = $this->request->getParam('spans', null);
		if (is_array($editedSpans) === false) {
			$editedSpans = null;
		}

		try {
			$result = $this->anonymisationAssist->reviewProposal(
				caseId: $id,
				documentRef: $documentRef,
				decision: $decision,
				reviewerId: $user->getUID(),
				editedSpans: $editedSpans,
			);
			return new JSONResponse($result);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end reviewRedactionProposal()

	/**
	 * Require that the current user can mutate the given case.
	 *
	 * Delegates to CaseAccessGuard, which enforces a real per-case relationship
	 * (admin or `case.assignee`) and fails closed.
	 *
	 * This previously gated on `groupExists('procest-gebruikers')`, a group that
	 * exists nowhere in the codebase — so the `&&` short-circuited, nothing was
	 * thrown, and every authenticated user was authorized on all five
	 * `#[NoAdminRequired]` endpoints below (including statutory deadline
	 * extension). Group existence is deliberately no longer part of the
	 * decision: an absent group must never grant access.
	 *
	 * Satisfies OWASP A01:2021 per-object authorization (ADR-005 Rule 3); RBAC
	 * is consumed from OpenRegister per ADR-022.
	 *
	 * @param string $caseId The case UUID to check
	 * @param IUser $user The current user
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException If the user is not authorized
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	private function requireCaseMutationAccess(string $caseId, IUser $user): void {
		$this->caseAccessGuard->assertCaseMutationAccess(caseId: $caseId, user: $user);
	}//end requireCaseMutationAccess()
}//end class
