<?php

/**
 * Dossiq Consultation Public Controller
 *
 * Token-based public surface for external advisory bodies to read a
 * consultation and submit their advice, per Awb 3:5-3:9.
 *
 * Split out of ConsultationController along the authentication seam: these
 * are the only consultation endpoints with no local session at all. They
 * carry PublicPage + NoCSRFRequired and are gated exclusively by the secure
 * token, and every access is logged for BIO compliance (Baseline
 * Informatiebeveiliging Overheid).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\ConsultationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for the token-authenticated external consultation surface.
 *
 * Both endpoints carry PublicPage + NoCSRFRequired — access is logged for
 * BIO compliance via LoggerInterface.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
 */
class ConsultationPublicController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request
	 * @param ConsultationService $consultationService The consultation service
	 * @param LoggerInterface $logger The logger for BIO audit events
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConsultationService $consultationService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET endpoint for external body access via secure token.
	 *
	 * Returns consultation details for the external advisory body.
	 * Access is logged for BIO compliance (Baseline Informatiebeveiliging Overheid).
	 *
	 * @param string $token The secure access token
	 *
	 * @return JSONResponse Consultation data or error
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function publicResponseGet(string $token): JSONResponse {
		if (empty($token) === true) {
			return new JSONResponse(['error' => 'Token is required'], Http::STATUS_BAD_REQUEST);
		}

		$consultation = $this->consultationService->findBySecureToken(token: $token);
		if ($consultation === null) {
			return new JSONResponse(['error' => 'Invalid or expired token'], Http::STATUS_NOT_FOUND);
		}

		$this->logger->info(
			'Dossiq BIO: external consultation access via token (GET)',
			[
				'app' => Application::APP_ID,
				'consultationId' => $consultation['id'] ?? '',
				'tokenPrefix' => substr($token, 0, 8) . '...',
			],
		);

		unset($consultation['secureToken']);
		return new JSONResponse($consultation);
	}//end publicResponseGet()

	/**
	 * POST endpoint for external body to submit advice response via secure token.
	 *
	 * Access is logged for BIO compliance.
	 *
	 * Rate-limit rationale: tight — this is an unauthenticated public
	 * consultation response, so an unbounded caller can stuff the consultation
	 * with fabricated submissions.
	 *
	 * @param string $token The secure access token
	 *
	 * @return JSONResponse Updated consultation or error
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	#[AnonRateLimit(limit: 20, period: 60)]
	public function publicResponsePost(string $token): JSONResponse {
		if (empty($token) === true) {
			return new JSONResponse(['error' => 'Token is required'], Http::STATUS_BAD_REQUEST);
		}

		$consultation = $this->consultationService->findBySecureToken(token: $token);
		if ($consultation === null) {
			return new JSONResponse(['error' => 'Invalid or expired token'], Http::STATUS_NOT_FOUND);
		}

		$consultationId = $consultation['id'] ?? '';

		$this->logger->info(
			'Dossiq BIO: external consultation response submitted via token (POST)',
			[
				'app' => Application::APP_ID,
				'consultationId' => $consultationId,
				'tokenPrefix' => substr($token, 0, 8) . '...',
			],
		);

		try {
			$data = $this->getRequestBody();
			$result = $this->consultationService->submitResponse(
				consultationId: $consultationId,
				response: $data,
			);
			return new JSONResponse($result);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end publicResponsePost()

	/**
	 * Parse the request body as JSON and return as array.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	private function getRequestBody(): array {
		$content = $this->request->getContent();
		if ($content === '' || $content === false) {
			$content = '{}';
		}

		$decoded = json_decode((string)$content, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end getRequestBody()
}//end class
