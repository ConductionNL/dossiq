<?php

/**
 * Dossiq PortalAssertionGate: the portal assertion check as a collaborator.
 *
 * The same check {@see VerifiesPortalAssertions} gives a controller as a
 * trait, for a controller that takes it as one injected dependency instead of
 * the verifier, the throttler and the logger separately.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Portal\PortalAssertionVerifier;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Verifies portaliq's signed assertion on a request and counts rejected ones.
 *
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
 */
class PortalAssertionGate {

	/**
	 * Constructor.
	 *
	 * @param PortalAssertionVerifier $verifier  Verifies portaliq's assertion.
	 * @param IThrottler              $throttler Counts rejected assertions.
	 * @param LoggerInterface         $logger    Records a throttler failure.
	 */
	public function __construct(
		private readonly PortalAssertionVerifier $verifier,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The claims of the request's assertion when it verifies and names one of the audiences.
	 *
	 * An assertion that does not verify answers 401 and is counted against the
	 * caller's IP under the throttle action; a verified one for another audience answers 403.
	 *
	 * @param IRequest      $request        The request carrying the assertion header.
	 * @param array<string> $audiences      The audiences that may call.
	 * @param string        $throttleAction The brute-force bucket.
	 *
	 * @return array<string, mixed>|JSONResponse The claims, or the refusal.
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
	 */
	public function claimsFor(IRequest $request, array $audiences, string $throttleAction): array|JSONResponse {
		$assertion = (string)$request->getHeader(PortalAssertionVerifier::HEADER);
		$claims    = $this->verifier->verify($assertion);
		if ($claims === null) {
			$this->countRejected(ip: $request->getRemoteAddress(), throttleAction: $throttleAction);
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$audience = (string)($claims['audience'] ?? '');
		if (in_array($audience, $audiences, true) === true) {
			return $claims;
		}

		return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
	}//end claimsFor()

	/**
	 * Count a rejected assertion; bookkeeping never turns a 401 into a 500.
	 *
	 * @param string $ip             The caller's address.
	 * @param string $throttleAction The brute-force bucket.
	 *
	 * @return void
	 */
	private function countRejected(string $ip, string $throttleAction): void {
		try {
			$this->throttler->registerAttempt(action: $throttleAction, ip: $ip);
		} catch (Throwable $e) {
			$this->logger->warning('PortalAssertionGate: registerAttempt failed', ['action' => $throttleAction, 'error' => $e->getMessage()]);
		}
	}//end countRejected()
}//end class
