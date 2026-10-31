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

use OCA\Dossiq\Service\Ai\AiAuditService;
use OCA\Dossiq\Service\AiService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class AiController extends Controller {
	/**
	 * What a person can do with a suggestion.
	 *
	 * @var string[]
	 */
	private const USER_ACTIONS = ['accepted', 'rejected', 'modified', 'ignored'];

	/**
	 * Why a shown suggestion went unused.
	 *
	 * @var string[]
	 */
	private const UNUSED_BECAUSE = ['closed', 'expired', 'superseded'];

	/**
	 * Constructor for AiController.
	 *
	 * @param string $appName The application name
	 * @param IRequest $request The request object
	 * @param AiService $aiService The AI service
	 * @param AiAuditService $auditService The AI oversight audit service
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger interface
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed)
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private AiService $aiService,
		private AiAuditService $auditService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Classify a document using AI.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function classify(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');
		$documentId = $this->request->getParam('documentId', '');

		if (empty($caseId) === true || empty($documentId) === true) {
			return new JSONResponse(
				['error' => 'caseId and documentId are required'],
				Http::STATUS_BAD_REQUEST
			);
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function extract(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');
		$documentId = $this->request->getParam('documentId');

		if (empty($caseId) === true) {
			return new JSONResponse(
				['error' => 'caseId is required'],
				Http::STATUS_BAD_REQUEST
			);
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function ask(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');
		$question = $this->request->getParam('question', '');

		if (empty($caseId) === true || empty($question) === true) {
			return new JSONResponse(
				['error' => 'caseId and question are required'],
				Http::STATUS_BAD_REQUEST
			);
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
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
			return new JSONResponse(
				['error' => 'caseId is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$validTypes = ['case', 'document', 'timeline'];
		if (in_array($type, $validTypes, true) === false) {
			return new JSONResponse(
				['error' => 'type must be one of: ' . implode(', ', $validTypes)],
				Http::STATUS_BAD_REQUEST
			);
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function suggestRouting(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');

		if (empty($caseId) === true) {
			return new JSONResponse(
				['error' => 'caseId is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$userId = $user->getUID();
		$result = $this->aiService->suggestRouting($caseId, $userId);

		return new JSONResponse($result);
	}//end suggestRouting()

	/**
	 * Get next-step suggestions for a case.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function suggestNext(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');

		if (empty($caseId) === true) {
			return new JSONResponse(
				['error' => 'caseId is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$userId = $user->getUID();
		$result = $this->aiService->suggestNextStep($caseId, $userId);

		return new JSONResponse($result);
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
	 */
	public function recordAction(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = (string)$this->request->getParam('caseId', '');
		$type = (string)$this->request->getParam('type', '');
		$userAction = (string)$this->request->getParam('userAction', '');
		$unusedBecause = (string)$this->request->getParam('unusedBecause', '');

		if ($caseId === '' || $type === '' || $userAction === '') {
			return new JSONResponse(
				['error' => 'caseId, type, and userAction are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$refusal = $this->refuseAction(userAction: $userAction, unusedBecause: $unusedBecause);
		if ($refusal !== null) {
			return new JSONResponse(['error' => $refusal], Http::STATUS_BAD_REQUEST);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		$reason = $this->request->getParam('reason');
		if (is_string($reason) === false) {
			$reason = null;
		}

		$result = $this->auditService->recordUserAction(
			caseId: $caseId,
			type: $type,
			userAction: $userAction,
			suggestion: $this->asPayload(value: $this->request->getParam('suggestion', [])) ?? [],
			actualValue: $this->asPayload(value: $this->request->getParam('actualValue')),
			reason: $reason,
			userId: $user->getUID(),
			context: [
				'unusedBecause' => $unusedBecause,
				'prompt' => (string)$this->request->getParam('prompt', ''),
				'model' => (string)$this->request->getParam('model', ''),
				'suggestionId' => (string)$this->request->getParam('suggestionId', ''),
				'documentId' => (string)$this->request->getParam('documentId', ''),
			],
		);

		return new JSONResponse($result);
	}//end recordAction()

	/**
	 * Why a reported action is refused, or null when it is accepted.
	 *
	 * @param string $userAction    The reported action.
	 * @param string $unusedBecause Why an ignored suggestion went unused.
	 *
	 * @return string|null The refusal, or null.
	 */
	private function refuseAction(string $userAction, string $unusedBecause): ?string {
		if (in_array($userAction, self::USER_ACTIONS, true) === false) {
			return 'userAction must be one of: ' . implode(', ', self::USER_ACTIONS);
		}

		if ($userAction === 'ignored' && in_array($unusedBecause, self::UNUSED_BECAUSE, true) === false) {
			return 'unusedBecause must be one of: ' . implode(', ', self::UNUSED_BECAUSE);
		}

		return null;
	}//end refuseAction()

	/**
	 * A suggestion or applied value as the audit entry stores it.
	 *
	 * The schema holds an object; a surface that shows text sends a string,
	 * which is kept under `text`.
	 *
	 * @param mixed $value The request value.
	 *
	 * @return array|null The value as an array, or null when absent.
	 */
	private function asPayload(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_string($value) === true && $value !== '') {
			return ['text' => $value];
		}

		return null;
	}//end asPayload()

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
	 * @spec openspec/changes/ai-oversight-log/tasks.md#1.2
	 */
	public function auditIndex(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = (string)$this->request->getParam('caseId', '');
		$type = $this->request->getParam('type');
		$limit = (int)$this->request->getParam('limit', '50');
		$offset = (int)$this->request->getParam('offset', '0');

		// `caseId` used to be optional and the filter was built with
		// `array_filter()`, so omitting it dropped the key entirely and the
		// response was every AI decision record on the instance. It is now
		// mandatory, and the caller must work on that case.
		if ($caseId === '') {
			return new JSONResponse(['error' => 'caseId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->auditService->listAuditEntries(
				filters: array_filter(['caseId' => $caseId, 'type' => $type]),
				limit: $limit,
				offset: $offset,
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
		} catch (\Exception $e) {
			$this->logger->error(
				'AI audit trail query failed',
				['error' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'AI audit trail query failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end auditIndex()
}//end class
