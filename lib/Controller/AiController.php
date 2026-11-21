<?php

/**
 * Dossiq AI Controller
 *
 * Controller for AI-assisted case processing endpoints.
 * Provides document classification, data extraction, knowledge base Q&A,
 * summarization, routing suggestions, and audit trail access.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-assistance/spec.md
 * @spec openspec/specs/ai-assistance/spec.md
 * @spec openspec/specs/ai-assistance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\SuggestionActionReport;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\AiAuditServiceInterface;
use OCA\Dossiq\Kernel\Contracts\AiServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * AI-assisted processing API controller.
 *
 * All endpoints require authenticated Nextcloud user.
 * AI features must be enabled in settings. The admin-gated configuration and
 * health endpoints live on {@see AiSettingsController}.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 */
class AiController extends Controller {
	/**
	 * Constructor for AiController.
	 *
	 * @param IRequest $request The request object
	 * @param AiServiceInterface $aiService The AI service
	 * @param AiAuditServiceInterface $auditService The AI oversight audit service
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger interface
	 * @param CaseAccessGuardInterface $caseAccessGuard Per-case authorization (fails closed)
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private AiServiceInterface $aiService,
		private AiAuditServiceInterface $auditService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private readonly CaseAccessGuardInterface $caseAccessGuard,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Classify a document using AI.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function classify(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');
		$documentId = $this->request->getParam('documentId', '');

		if (empty($caseId) === true || empty($documentId) === true) {
			return $this->badRequest(error: 'caseId and documentId are required');
		}

		$userId = $user->getUID();
		$result = $this->aiService->classifyDocument($caseId, $documentId, $userId);

		return new JSONResponse($result);
	}//end classify()

	/**
	 * Extract structured data from case documents.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function extract(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');
		$documentId = $this->request->getParam('documentId');

		if (empty($caseId) === true) {
			return $this->badRequest(error: 'caseId is required');
		}

		$userId = $user->getUID();
		$result = $this->aiService->extractData($caseId, $documentId, $userId);

		return new JSONResponse($result);
	}//end extract()

	/**
	 * Ask a knowledge base question in case context.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function ask(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');
		$question = $this->request->getParam('question', '');

		if (empty($caseId) === true || empty($question) === true) {
			return $this->badRequest(error: 'caseId and question are required');
		}

		$userId = $user->getUID();
		$result = $this->aiService->askQuestion($caseId, $question, $userId);

		return new JSONResponse($result);
	}//end ask()

	/**
	 * Generate a summary for a case, document, or timeline.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function summarize(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');
		$type = $this->request->getParam('type', 'case');
		$documentId = $this->request->getParam('documentId');

		if (empty($caseId) === true) {
			return $this->badRequest(error: 'caseId is required');
		}

		$validTypes = ['case', 'document', 'timeline'];
		if (in_array($type, $validTypes, true) === false) {
			return $this->badRequest(error: 'type must be one of: ' . implode(', ', $validTypes));
		}

		$userId = $user->getUID();
		$result = $this->aiService->summarize($caseId, $type, $documentId, $userId);

		return new JSONResponse($result);
	}//end summarize()

	/**
	 * Get case routing suggestions.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function suggestRouting(): JSONResponse {
		return $this->caseSuggestion(suggest: fn (string $caseId, string $userId): array => $this->aiService->suggestRouting($caseId, $userId));
	}//end suggestRouting()

	/**
	 * Get next-step suggestions for a case.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function suggestNext(): JSONResponse {
		return $this->caseSuggestion(suggest: fn (string $caseId, string $userId): array => $this->aiService->suggestNextStep($caseId, $userId));
	}//end suggestNext()

	/**
	 * Record what a person did with an AI suggestion on a case.
	 *
	 * `userAction` is accepted, rejected, modified or ignored. An ignored
	 * suggestion was shown and left: `unusedBecause` says whether its surface
	 * was closed, it expired, or a newer suggestion replaced it. The caller
	 * must be able to read the case; the check runs before anything is written.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/ai-oversight-dispatch/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function recordAction(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$report = new SuggestionActionReport(request: $this->request);
		$error = $report->refusal();
		if ($error !== null) {
			return $this->badRequest(error: $error);
		}

		$caseId = (string)$this->request->getParam('caseId', '');
		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($this->recordedAction(report: $report, caseId: $caseId, user: $user));
	}//end recordAction()

	/**
	 * Write the oversight entry the request reports.
	 *
	 * @param SuggestionActionReport $report The reported action.
	 * @param string                 $caseId The case the suggestion was on.
	 * @param IUser                  $user   The person who acted on it.
	 *
	 * @return array<string, mixed> The stored entry.
	 *
	 * @spec openspec/specs/ai-oversight-dispatch/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function recordedAction(SuggestionActionReport $report, string $caseId, IUser $user): array {
		return $this->auditService->recordUserAction(
			caseId: $caseId,
			type: (string)$this->request->getParam('type', ''),
			userAction: (string)$this->request->getParam('userAction', ''),
			suggestion: $report->suggestion(),
			actualValue: $report->actualValue(),
			reason: $report->reason(),
			userId: $user->getUID(),
			context: $report->context(),
		);
	}//end recordedAction()

	/**
	 * Get AI audit trail entries.
	 *
	 * Queries the recorded `aiAuditEntry` objects from OpenRegister via
	 * {@see AiAuditService::listAuditEntries()} — filterable by `caseId`/`type`,
	 * paged via `limit`/`offset`, newest first.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-07-12-ai-oversight-log/tasks.md#1.2
	 */
	public function auditIndex(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = (string)$this->request->getParam('caseId', '');
		$refusal = $this->auditRefusal(caseId: $caseId, user: $user);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->auditPage(caseId: $caseId);
		} catch (\Exception $e) {
			return $this->auditFailed(exception: $e);
		}
	}//end auditIndex()

	/**
	 * Refuse a trail read without a case (400) or on a case the caller may not read (403).
	 *
	 * `caseId` used to be optional and the filter was built with
	 * `array_filter()`, so omitting it dropped the key entirely and the
	 * response was every AI decision record on the instance. It is now
	 * mandatory, and the caller must work on that case.
	 *
	 * @param string $caseId The case.
	 * @param IUser  $user   The caller.
	 *
	 * @return JSONResponse|null The refusal, or null when the trail may be read.
	 *
	 * @spec openspec/changes/archive/2026-07-12-ai-oversight-log/tasks.md#1.2
	 */
	private function auditRefusal(string $caseId, IUser $user): ?JSONResponse {
		if ($caseId === '') {
			return $this->badRequest(error: 'caseId is required');
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end auditRefusal()

	/**
	 * One page of the case's trail, filtered by type, newest first.
	 *
	 * @param string $caseId The case.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-07-12-ai-oversight-log/tasks.md#1.2
	 */
	private function auditPage(string $caseId): JSONResponse {
		$result = $this->auditService->listAuditEntries(
			filters: array_filter(['caseId' => $caseId, 'type' => $this->request->getParam('type')]),
			limit: (int)$this->request->getParam('limit', '50'),
			offset: (int)$this->request->getParam('offset', '0'),
		);

		return new JSONResponse(
			[
				'success' => true,
				'entries' => $result['entries'],
				'total' => $result['total'],
				'limit' => $result['limit'],
				'offset' => $result['offset'],
			]
		);
	}//end auditPage()

	/**
	 * The logged 500 a failed trail read answers with.
	 *
	 * @param \Exception $exception The failure.
	 *
	 * @return JSONResponse
	 */
	private function auditFailed(\Exception $exception): JSONResponse {
		$this->logger->error(
			'AI audit trail query failed',
			['error' => $exception->getMessage()]
		);
		return new JSONResponse(
			['error' => 'AI audit trail query failed: ' . $exception->getMessage()],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end auditFailed()

	/**
	 * A 400 carrying the error.
	 *
	 * @param string $error What the request lacks or got wrong.
	 *
	 * @return JSONResponse
	 */
	private function badRequest(string $error): JSONResponse {
		return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
	}//end badRequest()

	/**
	 * Answer a suggestion for the requested case, for a signed-in caller.
	 *
	 * @param callable(string, string): array<string, mixed> $suggest The suggestion, given the case id and the caller's uid.
	 *
	 * @return JSONResponse
	 */
	private function caseSuggestion(callable $suggest): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');

		if (empty($caseId) === true) {
			return $this->badRequest(error: 'caseId is required');
		}

		return new JSONResponse($suggest($caseId, $user->getUID()));
	}//end caseSuggestion()
}//end class
