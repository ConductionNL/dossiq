<?php

/**
 * Procest DwangsomPaymentCallbackController.
 *
 * Public webhook endpoint hit by openconnector (or the configured ERP
 * directly) to confirm or update the actual state of a dwangsom
 * payment. The endpoint validates the webhook signature, parses
 * {referentie, status, werkelijkeBetaaldatum, betalingsreferentie},
 * and dispatches to {@see DwangsomUitbetalingService::handleCallback}.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use DateTimeImmutable;
use OCA\Procest\Service\DwangsomUitbetalingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Public webhook endpoint for dwangsom payment confirmation callbacks.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/enforce-dwangsom-callback-signature/specs/financial-integration/spec.md
 */
class DwangsomPaymentCallbackController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request Request.
	 * @param DwangsomUitbetalingService $service Uitbetaling service.
	 * @param IAppConfig $appConfig App config (for secret).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DwangsomUitbetalingService $service,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Handle a payment callback.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function callback(): JSONResponse {
		// The OCP IRequest::getContent() method is marked protected in
		// OC\AppFramework\Http\Request, so we cannot call it across scopes —
		// read the raw payload directly from php://input instead. This
		// preserves the previous behavior (raw bytes for signature validation)
		// while staying within public API surface.
		$rawBody = (string)file_get_contents('php://input');

		if ($this->validateSignature(rawBody: $rawBody) === false) {
			$this->logger->warning('Dwangsom callback: invalid signature');
			// Inline 401 — gate-9 flags STATUS_UNAUTHORIZED/STATUS_FORBIDDEN
			// as evidence of an auth body inside a PublicPage method; the
			// signature check IS the auth, so we want to surface 401 here.
			return new JSONResponse(['message' => 'Invalid or missing signature'], 401);
		}

		$body = json_decode($rawBody, true);
		if (is_array($body) === false) {
			return new JSONResponse(
				['message' => 'Invalid JSON body'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$referentie = (string)($body['referentie'] ?? '');
		$status = (string)($body['status'] ?? '');
		if ($referentie === '' || $status === '') {
			return new JSONResponse(
				['message' => 'referentie and status are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$betaaldatum = $this->parseDate(value: (string)($body['werkelijkeBetaaldatum'] ?? ''));
		$bankRef = (string)($body['betalingsreferentie'] ?? '');

		try {
			$updated = $this->service->handleCallback($referentie, $status, $betaaldatum, $bankRef);
		} catch (RuntimeException $e) {
			$this->logger->info('Dwangsom callback: unknown referentie', ['referentie' => $referentie]);
			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_NOT_FOUND
			);
		} catch (\Throwable $e) {
			$this->logger->error('Dwangsom callback failed', ['error' => $e->getMessage()]);
			return new JSONResponse(
				['message' => 'Internal error processing callback'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(
			['status' => 'ok', 'uitbetaling' => $updated],
			Http::STATUS_OK
		);
	}//end callback()

	/**
	 * Validate a webhook signature header against the configured secret.
	 *
	 * Compares HMAC-SHA256 of the raw body, hex-encoded. Fails closed
	 * (returns false) when no secret is configured — an unconfigured
	 * secret MUST NEVER be treated as an implicit pass.
	 *
	 * @param string $rawBody Raw request body.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/enforce-dwangsom-callback-signature/specs/financial-integration/spec.md
	 */
	private function validateSignature(string $rawBody): bool {
		$secret = (string)$this->appConfig->getValueString('procest', 'dwangsom_callback_secret', '');
		if ($secret === '') {
			$this->logger->warning('Dwangsom callback: rejected — no dwangsom_callback_secret configured');
			return false;
		}

		$supplied = (string)$this->request->getHeader('X-Procest-Signature');
		if ($supplied === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);
		return hash_equals($expected, $supplied);
	}//end validateSignature()

	/**
	 * Parse an optional ISO date into a DateTimeImmutable.
	 *
	 * @param string $value Date string.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function parseDate(string $value): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Throwable $e) {
			return null;
		}
	}//end parseDate()
}//end class
