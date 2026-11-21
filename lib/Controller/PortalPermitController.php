<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\VerifiesPortalAssertions;
use OCA\Dossiq\Kernel\Contracts\PermitChangeRefusedInterface;
use OCA\Dossiq\Kernel\Contracts\PermitPlateChangeInterface;
use OCA\Dossiq\Kernel\Contracts\PortalAssertionVerifierInterface;
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

/**
 * The portal's "Kenteken wijzigen": a resident asks for a new plate on a permit they hold.
 *
 * Portaliq forwards the endpoint action with its signed `X-Portal-Subject`
 * assertion. Order, fail-closed: verify (401), audience (403), then the
 * request, which checks the permit is the resident's. The resident comes from
 * the verified `sub` claim only; a subject in the body is never read.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class PortalPermitController extends Controller {
	use VerifiesPortalAssertions;

	/**
	 * The brute-force action a rejected assertion counts against.
	 */
	private const THROTTLE_ACTION = 'dossiq_portal_assertion';

	/**
	 * The portal audiences a permit holder arrives as.
	 */
	private const AUDIENCES = ['citizen', 'client'];

	/**
	 * The status each refusal answers with.
	 */
	private const STATUS = [
		PermitChangeRefusedInterface::INVALID => Http::STATUS_BAD_REQUEST,
		PermitChangeRefusedInterface::NOT_FOUND => Http::STATUS_NOT_FOUND,
		PermitChangeRefusedInterface::NOT_ACTIVE => Http::STATUS_CONFLICT,
		PermitChangeRefusedInterface::UNAVAILABLE => Http::STATUS_SERVICE_UNAVAILABLE,
	];

	/**
	 * @param string                  $appName   The app name.
	 * @param IRequest                $request   The request.
	 * @param PortalAssertionVerifierInterface $verifier  Verifies portaliq's assertion.
	 * @param PermitPlateChangeInterface       $change    Opens the change case.
	 * @param IThrottler              $throttler Counts rejected assertions.
	 * @param LoggerInterface         $logger    Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly PermitPlateChangeInterface $change,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Open a case for a new licence plate on the resident's permit.
	 *
	 * @return JSONResponse 201 with the case, or the refusal's status.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function changePlate(): JSONResponse {
		$claims = $this->portalClaims();
		if ($claims instanceof JSONResponse) {
			return $claims;
		}

		try {
			return new JSONResponse(
				$this->change->request(
					subjectRef: (string)$claims['sub'],
					permitId: (string)$this->request->getParam('permitId', ''),
					plate: (string)$this->request->getParam('nieuwKenteken', '')
				),
				Http::STATUS_CREATED
			);
		} catch (PermitChangeRefusedInterface $refused) {
			return new JSONResponse(
				['error' => $refused->getReason(), 'message' => $refused->getDetail()],
				(self::STATUS[$refused->getReason()] ?? Http::STATUS_BAD_REQUEST)
			);
		}
	}//end changePlate()
}//end class
