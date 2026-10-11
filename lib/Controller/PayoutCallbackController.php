<?php

/**
 * Dossiq PayoutCallbackController.
 *
 * Public webhook endpoint that reports the progress of a payout: posted by
 * the finance app (shillinq, signed with X-Shillinq-Signature) or by an
 * external payment provider (signed with the frozen X-Procest-Signature), on
 * the same URL. The signature is checked and the body read by
 * {@see SignedPayoutCallback}; the payout row is updated by
 * {@see PayoutService::handleCallback}.
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
 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Money\PayoutService;
use OCA\Dossiq\Service\Money\SignedPayoutCallback;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Public webhook endpoint for payout callbacks.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
 */
class PayoutCallbackController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request Request.
	 * @param PayoutService $service Updates the payout row.
	 * @param SignedPayoutCallback $callback Checks the signature and reads the body.
	 * @param CaseDateNormaliser $dates The one date write path.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PayoutService $service,
		private readonly SignedPayoutCallback $callback,
		private readonly CaseDateNormaliser $dates,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Handle a payout callback.
	 *
	 * Rate-limit rationale: the caller is a finance app or a provider retrying
	 * on its own schedule, and dropping a payment notification is worse than
	 * absorbing a burst.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function callback(): JSONResponse {
		return $this->handle(rawBody: $this->rawBody());
	}//end callback()

	/**
	 * Verify, read and apply one callback body.
	 *
	 * @param string $rawBody The raw request body.
	 *
	 * @return JSONResponse
	 */
	private function handle(string $rawBody): JSONResponse {
		if ($this->callback->isSigned(rawBody: $rawBody, request: $this->request) === false) {
			if ($this->callback->hasSecret() === false) {
				$this->logger->warning('Payout callback: rejected, no ' . SignedPayoutCallback::SECRET_KEY . ' configured');
			}

			$this->logger->warning('Payout callback: invalid signature');
			// Inline 401: the signature check IS the auth of this public page.
			return new JSONResponse(['message' => 'Invalid or missing signature'], 401);
		}

		try {
			$fields = $this->callback->read(rawBody: $rawBody);
			$paymentDate = null;
			if ($fields['actualPaymentDate'] !== '') {
				$paymentDate = $this->dates->parse($fields['actualPaymentDate'], 'actualPaymentDate');
			}
		} catch (InvalidArgumentException $e) {
			// A malformed date used to be replaced by today, booking the payment on the wrong day.
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		try {
			$updated = $this->service->handleCallback($fields['reference'], $fields['status'], $paymentDate, $fields['bankReference']);
		} catch (RuntimeException $e) {
			$this->logger->info('Payout callback: unknown reference', ['reference' => $fields['reference']]);
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			$this->logger->error('Payout callback failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['message' => 'Internal error processing callback'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['status' => 'ok', 'uitbetaling' => $updated], Http::STATUS_OK);
	}//end handle()

	/**
	 * The raw request body, for the signature.
	 *
	 * IRequest::getContent() is protected on the concrete OC request, so the
	 * payload is read from php://input. Protected so a test can supply it.
	 *
	 * @return string The raw body.
	 */
	protected function rawBody(): string {
		return (string)file_get_contents('php://input');
	}//end rawBody()
}//end class
