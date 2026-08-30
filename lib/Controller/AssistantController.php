<?php

/**
 * Dossiq Assistant Controller (case-assistant-via-hermiq).
 *
 * The thin consumer surface: enriches an incoming case-assistant message
 * with a bounded, authorization-scoped case-context summary and forwards it
 * to Hermiq's case-assistant surface. Carries NO LLM/prompt logic itself —
 * see `CaseAssistantService`'s docblock for the fleet rule this follows.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use Exception;
use OCA\Dossiq\Service\Assistant\CaseAssistantService;
use OCA\Dossiq\Service\Assistant\HermiqAssistantClient;
use OCA\Dossiq\Service\Assistant\HermiqAssistantException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * AssistantController handles the case-assistant-via-hermiq endpoints.
 *
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */
class AssistantController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The application name.
	 * @param IRequest $request The request object.
	 * @param CaseAssistantService $caseAssistantService Turn orchestration.
	 * @param HermiqAssistantClient $hermiqClient Availability check for the UI gate.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param IL10N $l10n Localization service for translations.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseAssistantService $caseAssistantService,
		private readonly HermiqAssistantClient $hermiqClient,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Whether the case-assistant UI panel should render — absent/disabled
	 * Hermiq hides the panel rather than showing a permanently-erroring one.
	 *
	 * Whether an LLM backend is wired up is deployment information, so the
	 * probe is answered only for an authenticated session — the same
	 * fail-closed shape `converse()` uses.
	 *
	 * @return JSONResponse `{available: bool}`.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function availability(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		return new JSONResponse(['available' => $this->hermiqClient->isAvailable()]);
	}//end availability()

	/**
	 * Run one conversational turn against a case.
	 *
	 * @return JSONResponse `{reply, usage}` on success, or a mapped error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function converse(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		$caseId = (string)$this->request->getParam('caseId', '');
		$message = (string)$this->request->getParam('message', '');

		if ($caseId === '') {
			return new JSONResponse(
				['error' => $this->l10n->t('caseId is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->caseAssistantService->converse(
				userId: $user->getUID(),
				caseId: $caseId,
				message: $message
			);

			return new JSONResponse($result);
		} catch (HermiqAssistantException $e) {
			return $this->mapFailure(statusCode: $e->getStatusCode(), message: $e->getMessage(), errorCode: $e->getErrorCode());
		} catch (Exception $e) {
			$statusCode = (int)$e->getCode();
			if ($statusCode < 400 || $statusCode >= 600) {
				$statusCode = 500;
			}

			return $this->mapFailure(statusCode: $statusCode, message: $e->getMessage(), errorCode: null);
		}//end try
	}//end converse()

	/**
	 * Map a coded failure to a translated `JSONResponse`, logging at the
	 * level matching its severity (client 4xx as a warning without a stack
	 * trace; 5xx at error).
	 *
	 * @param int $statusCode The HTTP status to return.
	 * @param string $message The detail message.
	 * @param string|null $errorCode A stable machine-readable code, when present.
	 *
	 * @return JSONResponse
	 */
	private function mapFailure(int $statusCode, string $message, ?string $errorCode): JSONResponse {
		$level = 'error';
		if ($statusCode < 500) {
			// Client 4xx is expected user/caller error, not a server fault.
			$level = 'warning';
		}

		$this->logger->log(
			$level,
			'[AssistantController] Message not processed: ' . $message,
			['statusCode' => $statusCode]
		);

		$errorType = match ($statusCode) {
			400 => $this->l10n->t('Invalid request'),
			401 => $this->l10n->t('Authentication required'),
			403 => $this->l10n->t('Access denied'),
			404 => $this->l10n->t('Case not found'),
			422 => $this->l10n->t('Message blocked by the organisation\'s guardrail policy'),
			default => $this->l10n->t('The case assistant is currently unavailable'),
		};

		$data = ['error' => $errorType, 'message' => $message];
		if ($errorCode !== null) {
			$data['errorCode'] = $errorCode;
		}

		return new JSONResponse($data, $statusCode);
	}//end mapFailure()
}//end class
