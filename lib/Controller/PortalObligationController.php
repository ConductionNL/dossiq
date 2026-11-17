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

use OCA\Dossiq\Controller\Support\PortalAssertionGate;
use OCA\Dossiq\Controller\Support\ReadsStringParams;
use OCA\Dossiq\Service\Obligations\IntakeObligations;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;

/**
 * Records a sign or refuse that portaliq vouches for.
 *
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
 */
class PortalObligationController extends Controller {
	use ReadsStringParams;

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
	 * @param string              $appName     The app name.
	 * @param IRequest            $request     The request.
	 * @param PortalAssertionGate $gate        Verifies portaliq's assertion and counts rejected ones.
	 * @param IntakeObligations   $obligations Settles the obligation.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalAssertionGate $gate,
		private readonly IntakeObligations $obligations,
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
		$claims = $this->gate->claimsFor(request: $this->request, audiences: self::AUDIENCES, throttleAction: self::THROTTLE_ACTION);
		if ($claims instanceof JSONResponse) {
			return $claims;
		}

		$result = $this->obligations->settle(
			caseId: ($this->stringParam(name: 'case') ?? ''),
			kind: ($this->stringParam(name: 'kind') ?? ''),
			answer: ($this->stringParam(name: 'answer') ?? ''),
			holder: ['subject' => (string)($claims['sub'] ?? ''), 'trust' => (string)($claims['trust'] ?? '')],
		);

		return new JSONResponse($result['body'], $result['status']);
	}//end settle()
}//end class
