<?php

/**
 * Procest ParafeerActie Controller
 *
 * REST endpoints for recording and listing parafering actions on a voorstel.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/parafering-actions/tasks.md#T03
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\ParafeerActieService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for parafering action operations.
 *
 * Identity for authorization is always derived from IUserSession; the request
 * body is never trusted for actor identity (ADR-005).
 *
 * @spec openspec/changes/parafering-actions/tasks.md#T03
 *
 * @psalm-suppress UnusedClass
 */
class ParafeerActieController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request object.
	 * @param ParafeerActieService $signOffService The parafering action service.
	 * @param IUserSession $userSession The user session (for actor identity).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ParafeerActieService $signOffService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Record a parafering action for the current step of a voorstel.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/parafering-actions/tasks.md#T03
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['message' => 'Not authorized for this parafering step'],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$data = $this->getRequestBody();
			$proposalId = (string)($data['proposal'] ?? '');
			if ($proposalId === '') {
				return new JSONResponse(
					['message' => 'voorstel is required'],
					Http::STATUS_BAD_REQUEST
				);
			}

			$result = $this->signOffService->recordAction($proposalId, $data, $user);

			return new JSONResponse($result, Http::STATUS_CREATED);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(
				['message' => 'Not authorized for this parafering step'],
				Http::STATUS_FORBIDDEN
			);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ParafeerActieController::create failed',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['message' => 'Operation failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end create()

	/**
	 * List parafeeracties for a voorstel.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/parafering-actions/tasks.md#T03
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				['message' => 'Not authenticated'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		try {
			$proposalId = (string)($this->request->getParam('proposal') ?? '');
			if ($proposalId === '') {
				return new JSONResponse(
					['message' => 'voorstel is required'],
					Http::STATUS_BAD_REQUEST
				);
			}

			$results = $this->signOffService->listActions($proposalId);

			return new JSONResponse($results, Http::STATUS_OK);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ParafeerActieController::index failed',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['message' => 'Operation failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end index()

	/**
	 * Parse the JSON request body.
	 *
	 * @return array<string, mixed>
	 */
	private function getRequestBody(): array {
		$body = file_get_contents('php://input');
		if ($body === false || $body === '') {
			return [];
		}

		$decoded = json_decode($body, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end getRequestBody()
}//end class
