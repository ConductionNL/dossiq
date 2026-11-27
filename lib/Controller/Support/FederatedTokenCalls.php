<?php

/**
 * Dossiq federated token calls.
 *
 * What the token-authenticated federation endpoints share: resolving a
 * federated transfer token to its live share, accepting or rejecting the
 * transfer as the remote party the token belongs to, and counting a rejected
 * token with the brute-force throttler. Split out of
 * {@see \OCA\Dossiq\Controller\CaseFederationController}, which keeps the
 * `#[BruteForceProtection]` attributes that enforce the count, the session
 * endpoints and the answers.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\CaseTransferServiceInterface;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

/**
 * The transfer token, the transfer answer, and the count of rejected tokens.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class FederatedTokenCalls {

	/**
	 * Constructor.
	 *
	 * @param CaseTransferServiceInterface $caseTransferService The transfer service.
	 * @param IThrottler                   $throttler           The brute-force throttler counting rejected share tokens.
	 * @param LoggerInterface              $logger              The logger.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly CaseTransferServiceInterface $caseTransferService,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The live share a transfer token resolves to, or null.
	 *
	 * @param string $shareToken The token from the URL.
	 * @param string $transferId The transfer.
	 *
	 * @return array<string, mixed>|null The verified share, with `sharedWith`.
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function verifiedTransfer(string $shareToken, string $transferId): ?array {
		return $this->caseTransferService->resolveFederatedTransferShare($shareToken, $transferId);
	}//end verifiedTransfer()

	/**
	 * Accept or reject the transfer as the request's `action` asks, as the remote party.
	 *
	 * @param IRequest    $request    The request: `action`, and `reason` for a rejection.
	 * @param string      $transferId The transfer.
	 * @param string|null $sharedWith The remote party the verified token belongs to.
	 *
	 * @return array<string, mixed>|null The transfer, or null when the action is neither.
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function answerTransfer(IRequest $request, string $transferId, ?string $sharedWith): ?array {
		$action = $request->getParam('action');

		return match ($action) {
			'accept' => $this->caseTransferService->acceptTransfer($transferId, $sharedWith),
			'reject' => $this->caseTransferService->rejectTransfer(
				$transferId,
				$request->getParam('reason', ''),
				$sharedWith
			),
			default => null,
		};
	}//end answerTransfer()

	/**
	 * Record a rejected share token with the brute-force throttler.
	 *
	 * The token in the URL is the only credential of these endpoints; a
	 * rejection means it did not resolve to a live share. This is the half that
	 * COUNTS; `#[BruteForceProtection]` on the endpoints is the half that
	 * ENFORCES (ADR-082). A throttler that fails is logged, never thrown.
	 *
	 * @param IRequest $request The request, for the caller's address.
	 * @param string   $action  The throttle action the endpoints declare.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function registerFailedToken(IRequest $request, string $action): void {
		try {
			$this->throttler->registerAttempt(
				action: $action,
				ip: $request->getRemoteAddress()
			);
		} catch (\Throwable $throttlerFailure) {
			$this->logger->warning(
				'CaseFederationController: registerAttempt failed: ' . $throttlerFailure->getMessage()
			);
		}
	}//end registerFailedToken()
}//end class
