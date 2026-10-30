<?php

/**
 * Dossiq Portal Obligation Controller
 *
 * The receiving end of the portal's settlement of an obligation placed at
 * intake: the person a form named, such as a second signer, signs or refuses
 * on the portal, and portaliq forwards that here with its signed
 * `X-Portal-Subject` assertion. Public and CSRF-free because the caller is
 * portaliq's backend: the assertion is the only identity this route trusts.
 *
 * Order, fail-closed: verify (401), audience (403), then IntakeObligations.
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
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Portal\PortalAssertionVerifier;
use OCA\Dossiq\Service\Obligations\IntakeObligations;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records a sign or refuse that portaliq vouches for.
 *
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
 */
class PortalObligationController extends Controller {

	/**
	 * The brute-force bucket a rejected assertion counts against.
	 */
	private const THROTTLE_ACTION = 'dossiq_portal_assertion';

	/**
	 * The portal audiences that may settle (DigiD arrives as client).
	 */
	private const AUDIENCES = ['citizen', 'client'];

	/**
	 * Constructor.
	 *
	 * @param string                  $appName     The app name.
	 * @param IRequest                $request     The request.
	 * @param PortalAssertionVerifier $verifier    Verifies portaliq's assertion.
	 * @param IntakeObligations       $obligations Settles the obligation.
	 * @param IThrottler              $throttler   Counts rejected assertions.
	 * @param LoggerInterface         $logger      Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly IntakeObligations $obligations,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Sign or refuse an obligation placed at intake.
	 *
	 * Body: `case` (the case id), `kind` (the obligation kind) and `answer`
	 * (`sign` or `refuse`). The holder is the assertion's subject.
	 *
	 * @return JSONResponse 200 `{case, kind, state}`; 400, 401, 403, 404, 409 or 503 `{error}`.
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function settle(): JSONResponse {
		$claims = $this->verifier->verify((string)$this->request->getHeader(PortalAssertionVerifier::HEADER));
		if ($claims === null) {
			$this->registerRejectedAssertion();
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if (in_array((string)($claims['audience'] ?? ''), self::AUDIENCES, true) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$result = $this->obligations->settle(
			caseId: $this->stringParam(key: 'case'),
			kind: $this->stringParam(key: 'kind'),
			answer: $this->stringParam(key: 'answer'),
			subjectRef: (string)($claims['sub'] ?? ''),
			trust: (string)($claims['trust'] ?? ''),
		);

		return new JSONResponse($result['body'], $result['status']);
	}//end settle()

	/**
	 * A string request parameter, or ''.
	 *
	 * @param string $key The parameter.
	 *
	 * @return string
	 */
	private function stringParam(string $key): string {
		$value = $this->request->getParam($key, '');
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end stringParam()

	/**
	 * Count a rejected assertion; bookkeeping never turns a 401 into a 500.
	 *
	 * @return void
	 */
	private function registerRejectedAssertion(): void {
		try {
			$this->throttler->registerAttempt(action: self::THROTTLE_ACTION, ip: $this->request->getRemoteAddress());
		} catch (Throwable $e) {
			$this->logger->warning('PortalObligationController: registerAttempt failed', ['error' => $e->getMessage()]);
		}
	}//end registerRejectedAssertion()
}//end class
