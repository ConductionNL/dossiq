<?php

/**
 * Procest BAG Controller
 *
 * HTTP surface for the BAG (Basisregistratie Adressen en Gebouwen)
 * authoritative lookup seam (bag-register-adapter):
 *   - GET /api/external/bag/address (postcode + huisnummer search)
 *   - GET /api/external/bag/pand/{id}
 *   - GET /api/external/bag/verblijfsobject/{id}
 *
 * This is procest's first HTTP controller for a base-registration lookup
 * — BRP/KvK's adapters are consumed internally only and have no route.
 * Auth posture and error-shape mirror `LhsController` (the closest
 * existing authenticated-lookup sibling in this app): `@NoAdminRequired`
 * + an explicit session guard returning 401, 400 for malformed input, and
 * a graceful 200 passthrough of the adapter's own `lookupStatus` for
 * "not configured" / "not found" rather than surfacing those as HTTP
 * errors — see openspec/changes/bag-register-adapter/design.md Decision 2.
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\External\Bag\BagAdapterInterface;
use OCA\Procest\Service\External\Bag\BagLookupResult;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for BAG address / pand / verblijfsobject lookups.
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 *
 * @psalm-suppress UnusedClass
 */
class BagController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App name
	 * @param IRequest $request Request
	 * @param BagAdapterInterface $bagAdapter BAG lookup port
	 * @param IUserSession $userSession User session
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly BagAdapterInterface $bagAdapter,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Look up address record(s) by postcode + huisnummer.
	 *
	 * Query parameters:
	 *   - postcode (string, required): Dutch postcode, e.g. `1234AB`
	 *   - huisnummer (string, required): house number
	 *   - huisletter (string, optional)
	 *   - huisnummertoevoeging (string, optional)
	 *
	 * @return JSONResponse {lookupStatus, address, dormant, extras}
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/bag-register-adapter/proposal.md
	 */
	public function address(): JSONResponse {
		$unauthorized = $this->requireUser();
		if ($unauthorized !== null) {
			return $unauthorized;
		}

		$postcode = (string)$this->request->getParam('postcode', '');
		$houseNumber = (string)$this->request->getParam('huisnummer', '');
		if ($postcode === '' || $houseNumber === '') {
			return new JSONResponse(
				['error' => 'postcode and huisnummer are required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$huisletterParam = $this->request->getParam('huisletter');
		$huisletter = null;
		if (is_string($huisletterParam) === true && $huisletterParam !== '') {
			$huisletter = $huisletterParam;
		}

		$toevoegingParam = $this->request->getParam('huisnummertoevoeging');
		$toevoeging = null;
		if (is_string($toevoegingParam) === true && $toevoegingParam !== '') {
			$toevoeging = $toevoegingParam;
		}

		try {
			$result = $this->bagAdapter->lookupAddress(
				postcode: $postcode,
				houseNumber: $houseNumber,
				huisletter: $huisletter,
				toevoeging: $toevoeging,
			);
		} catch (Throwable $e) {
			$this->logger->error('Procest BAG address lookup failed: ' . $e->getMessage());
			return new JSONResponse(
				['error' => 'BAG address lookup failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		return $this->toResponse(result: $result);
	}//end address()

	/**
	 * Look up a pand (building) by its BAG identificatie.
	 *
	 * @param string $id BAG pand identificatie.
	 *
	 * @return JSONResponse {lookupStatus, address, dormant, extras}
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/bag-register-adapter/proposal.md
	 */
	public function pand(string $id): JSONResponse {
		return $this->objectLookup(objectType: 'pand', id: $id);
	}//end pand()

	/**
	 * Look up a verblijfsobject by its BAG identificatie.
	 *
	 * @param string $id BAG verblijfsobject identificatie.
	 *
	 * @return JSONResponse {lookupStatus, address, dormant, extras}
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/bag-register-adapter/proposal.md
	 */
	public function verblijfsobject(string $id): JSONResponse {
		return $this->objectLookup(objectType: 'verblijfsobject', id: $id);
	}//end verblijfsobject()

	/**
	 * Shared pand/verblijfsobject lookup implementation.
	 *
	 * @param string $objectType `pand` or `verblijfsobject`.
	 * @param string $id BAG identificatie.
	 *
	 * @return JSONResponse
	 */
	private function objectLookup(string $objectType, string $id): JSONResponse {
		$unauthorized = $this->requireUser();
		if ($unauthorized !== null) {
			return $unauthorized;
		}

		if ($id === '') {
			return new JSONResponse(
				['error' => 'id is required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			$result = $this->bagAdapter->lookupObject(objectType: $objectType, id: $id);
		} catch (Throwable $e) {
			$this->logger->error('Procest BAG object lookup failed: ' . $e->getMessage());
			return new JSONResponse(
				['error' => 'BAG object lookup failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		return $this->toResponse(result: $result);
	}//end objectLookup()

	/**
	 * Require an active user session.
	 *
	 * @return JSONResponse|null A 401 response when unauthenticated, else
	 *                           null.
	 */
	private function requireUser(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				['error' => 'Authentication required'],
				Http::STATUS_UNAUTHORIZED,
			);
		}

		return null;
	}//end requireUser()

	/**
	 * Wrap a BagLookupResult as a 200 JSON response — the adapter's own
	 * `lookupStatus` (including LOOKUP_DEFERRED / NOT_FOUND / INVALID_INPUT
	 * / LOOKUP_ERROR) carries the outcome; the controller never turns
	 * "not configured" or "not found" into an HTTP error.
	 *
	 * @param BagLookupResult $result Adapter result.
	 *
	 * @return JSONResponse
	 */
	private function toResponse(BagLookupResult $result): JSONResponse {
		return new JSONResponse(
			[
				'lookupStatus' => $result->lookupStatus,
				'address' => $result->address,
				'dormant' => $result->dormant,
				'extras' => $result->extras,
			]
		);
	}//end toResponse()
}//end class
