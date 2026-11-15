<?php

/**
 * Dossiq VerifiesPortalAssertions support trait.
 *
 * The signed portal assertion every portal intake endpoint reads before it
 * acts. The using class provides `$verifier`
 * ({@see \OCA\Dossiq\Kernel\Contracts\PortalAssertionVerifierInterface}),
 * the controller's `$request`, `$throttler` (IThrottler), `$logger`, and the
 * `AUDIENCES` and `THROTTLE_ACTION` constants.
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
 * @spec exclude shared assertion check of the portal intake controllers, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * The verified claims of the portal assertion, or the 401 or 403.
 *
 * @spec exclude shared assertion check of the portal intake controllers, covered by their own specs
 */
trait VerifiesPortalAssertions {

	/**
	 * The claims of a valid assertion for one of this endpoint's audiences.
	 *
	 * An assertion that does not verify is counted against the caller's IP.
	 *
	 * @return array<string, mixed>|JSONResponse The claims, or the refusal.
	 */
	private function portalClaims(): array|JSONResponse {
		$claims = $this->verifier->verify((string)$this->request->getHeader(PortalAssertionVerifierInterface::HEADER));
		if ($claims === null) {
			$this->registerRejectedAssertion();
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if (in_array((string)($claims['audience'] ?? ''), self::AUDIENCES, true) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return $claims;
	}//end portalClaims()

	/**
	 * Count a rejected assertion against the caller's IP; a throttler failure is logged, not thrown.
	 *
	 * @return void
	 */
	private function registerRejectedAssertion(): void {
		try {
			$this->throttler->registerAttempt(action: self::THROTTLE_ACTION, ip: $this->request->getRemoteAddress());
		} catch (Throwable $e) {
			$parts = explode('\\', static::class);
			$this->logger->warning((string)end($parts) . ': registerAttempt failed', ['error' => $e->getMessage()]);
		}
	}//end registerRejectedAssertion()
}//end trait
