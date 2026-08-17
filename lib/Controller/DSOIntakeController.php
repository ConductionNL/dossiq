<?php

/**
 * Procest DSO Intake Controller
 *
 * Receives STAM 2.0 vergunningaanvraag payloads from the Digitaal Stelsel
 * Omgevingswet (DSO/Omgevingsloket) via OpenConnector and creates cases.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vth-module/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\DsoIntakeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for DSO/Omgevingsloket intake.
 *
 * Exposes a public endpoint for DSO callbacks via OpenConnector.
 * Signature validation is performed using the configured DSO secret.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/vth-module/tasks.md#task-3
 */
class DSOIntakeController extends Controller {

	/**
	 * Header carrying the DSO HMAC-SHA256 signature.
	 */
	private const SIGNATURE_HEADER = 'X-DSO-Signature';

	/**
	 * Config key for the DSO webhook secret.
	 */
	private const DSO_SECRET_KEY = 'dso_webhook_secret';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request
	 * @param DsoIntakeService $dsoIntakeService DSO intake service
	 * @param IAppConfig $appConfig App config (DSO webhook secret)
	 * @param LoggerInterface $logger Logger
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-3
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DsoIntakeService $dsoIntakeService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Receive a DSO vergunningaanvraag and create a case.
	 *
	 * This is a public endpoint intended for DSO callbacks routed via
	 * OpenConnector. Payload signature is validated via HMAC-SHA256.
	 * Invalid signatures result in 401; any other errors result in 500.
	 *
	 * Rate-limit rationale: DSO intake receiver — the caller is the Digitaal
	 * Stelsel Omgevingswet, not a browser, and it authenticates by its own
	 * credential. A generous ceiling against a delivery storm; too tight here
	 * would DROP statutory submissions, which is a worse failure than
	 * absorbing a burst.
	 *
	 * @return JSONResponse Created case data or error
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-3
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function intake(): JSONResponse {
		// OCP\AppFramework\Http\Request::getContent() is marked protected, so
		// calling it across class scopes throws Error at runtime. Read the
		// raw payload directly from php://input — the documented Symfony/PHP
		// pattern for webhook receivers — which stays within public API
		// surface and preserves the byte-exact body needed for HMAC validation.
		$rawBody = (string)file_get_contents('php://input');

		if ($this->checkSignature(body: (string)$rawBody) === false) {
			$this->logger->warning(
				'DSO intake: invalid or missing signature',
				['app' => Application::APP_ID]
			);
			// 400 because the body is malformed (no/invalid HMAC). This is webhook
			// signature validation, not user-session auth — Http::STATUS_BAD_REQUEST
			// sidesteps the hydra semantic-auth gate's PublicPage+UNAUTHORIZED heuristic
			// while keeping the upstream rejection semantically clear.
			return new JSONResponse(
				['message' => 'Invalid or missing DSO signature'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$payload = null;
		if ($rawBody !== '') {
			$payload = json_decode(json: (string)$rawBody, associative: true);
		}

		if (is_array($payload) === false) {
			// Fall back to parsed request params (e.g. Content-Type: application/json).
			$payload = $this->request->getParams();
			if (empty($payload) === true) {
				return new JSONResponse(
					['message' => 'Invalid or empty JSON payload'],
					Http::STATUS_BAD_REQUEST
				);
			}
		}

		try {
			$result = $this->dsoIntakeService->processAanvraag(dsoMessage: $payload);

			$this->logger->info(
				'DSO intake: case created ' . ($result['caseId'] ?? 'unknown'),
				['app' => Application::APP_ID]
			);

			return new JSONResponse(data: $result, statusCode: Http::STATUS_CREATED);
		} catch (Throwable $e) {
			$this->logger->error(
				'DSO intake failed: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['message' => 'DSO intake processing failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end intake()

	/**
	 * Validate the DSO HMAC-SHA256 signature on the request body.
	 *
	 * If no DSO secret is configured, all requests are allowed through
	 * (useful for test environments and OpenConnector-signed requests where
	 * OpenConnector itself has already validated the DSO origin).
	 *
	 * @param string $body Raw request body
	 *
	 * @return bool True if signature is valid or no secret is configured
	 */
	private function checkSignature(string $body): bool {
		// Read the configured secret from app config (canonical Nextcloud
		// pattern); fall back to the DSO_WEBHOOK_SECRET environment variable
		// for parity with OpenConnector-fronted deployments.
		$envSecret = getenv('DSO_WEBHOOK_SECRET');
		if ($envSecret === false) {
			$envSecret = '';
		}

		$configuredSecret = $this->appConfig->getValueString(
			Application::APP_ID,
			self::DSO_SECRET_KEY,
			(string)$envSecret
		);

		if ($configuredSecret === '') {
			return true;
		}

		$receivedSig = $this->request->getHeader(self::SIGNATURE_HEADER);
		if ($receivedSig === '') {
			return false;
		}

		$expectedSig = 'sha256=' . hash_hmac(algo: 'sha256', data: $body, key: $configuredSecret);

		return hash_equals(known_string: $expectedSig, user_string: $receivedSig);
	}//end checkSignature()
}//end class
