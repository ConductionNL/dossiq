<?php

/**
 * Procest WOZ Controller
 *
 * HTTP surface for the WOZ (Waardering Onroerende Zaken) authoritative
 * property-valuation lookup seam (brk-woz-register-adapters):
 *   - GET /api/external/woz/value (postcode + huisnummer, or
 *     nummeraanduidingId, search)
 *   - GET /api/external/woz/value/{wozobjectnummer}
 *
 * Auth posture and error-shape mirror `BagController` / `BrkController`:
 * `@NoAdminRequired` + an explicit session guard returning 401, 400 for
 * malformed input, and a graceful 200 passthrough of the adapter's own
 * `lookupStatus` for "not configured" / "not found" rather than surfacing
 * those as HTTP errors.
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
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\External\Woz\WozAdapterInterface;
use OCA\Procest\Service\External\Woz\WozLookupResult;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for WOZ value lookups.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 *
 * @psalm-suppress UnusedClass
 */
class WozController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App name
	 * @param IRequest $request Request
	 * @param WozAdapterInterface $wozAdapter WOZ lookup port
	 * @param IUserSession $userSession User session
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WozAdapterInterface $wozAdapter,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Look up WOZ object(s) by postcode + huisnummer, or by
	 * nummeraanduidingId.
	 *
	 * Query parameters (exactly one of the two shapes is required):
	 *   - nummeraanduidingId (string): BAG nummeraanduiding identificatie
	 *   - postcode + huisnummer (string, string): Dutch address,
	 *     optionally with huisletter / huisnummertoevoeging
	 *
	 * @return JSONResponse {lookupStatus, wozObject, dormant, extras}
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function value(): JSONResponse {
		$unauthorized = $this->requireUser();
		if ($unauthorized !== null) {
			return $unauthorized;
		}

		// A nummeraanduidingId takes precedence over an address search (the
		// preferred composition path — see design.md Decision 3).
		$addressDesignationId = (string)$this->request->getParam('addressDesignationId', '');
		if ($addressDesignationId !== '') {
			return $this->addressDesignationLookup(addressDesignationId: $addressDesignationId);
		}

		$postcode = (string)$this->request->getParam('postcode', '');
		$houseNumber = (string)$this->request->getParam('houseNumber', '');
		if ($postcode === '' || $houseNumber === '') {
			return new JSONResponse(
				['error' => 'nummeraanduidingId, or postcode and huisnummer, are required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		return $this->addressLookup(postcode: $postcode, houseNumber: $houseNumber);
	}//end value()

	/**
	 * Look up a WOZ value by BAG nummeraanduiding identificatie.
	 *
	 * @param string $addressDesignationId BAG nummeraanduiding identificatie.
	 *
	 * @return JSONResponse
	 */
	private function addressDesignationLookup(string $addressDesignationId): JSONResponse {
		try {
			$result = $this->wozAdapter->lookupByNummeraanduiding(addressDesignationId: $addressDesignationId);
		} catch (Throwable $e) {
			$this->logger->error('Procest WOZ nummeraanduiding lookup failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'WOZ lookup failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->toResponse(result: $result);
	}//end nummeraanduidingLookup()

	/**
	 * Look up a WOZ value by postcode + huisnummer, reading the optional
	 * huisletter / huisnummertoevoeging from the request.
	 *
	 * @param string $postcode Dutch postcode.
	 * @param string $houseNumber House number.
	 *
	 * @return JSONResponse
	 */
	private function addressLookup(string $postcode, string $houseNumber): JSONResponse {
		$huisletter = $this->optionalParam(key: 'huisletter');
		$toevoeging = $this->optionalParam(key: 'huisnummertoevoeging');

		try {
			$result = $this->wozAdapter->lookupAddress(
				postcode: $postcode,
				houseNumber: $houseNumber,
				huisletter: $huisletter,
				toevoeging: $toevoeging,
			);
		} catch (Throwable $e) {
			$this->logger->error('Procest WOZ address lookup failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'WOZ lookup failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->toResponse(result: $result);
	}//end addressLookup()

	/**
	 * Read an optional non-empty string request parameter, or null.
	 *
	 * @param string $key Query parameter name.
	 *
	 * @return string|null
	 */
	private function optionalParam(string $key): ?string {
		$param = $this->request->getParam($key);
		if (is_string($param) === true && $param !== '') {
			return $param;
		}

		return null;
	}//end optionalParam()

	/**
	 * Look up a single WOZ object by its wozobjectnummer.
	 *
	 * @param string $wozobjectnummer WOZ object number.
	 *
	 * @return JSONResponse {lookupStatus, wozObject, dormant, extras}
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function object(string $wozobjectnummer): JSONResponse {
		$unauthorized = $this->requireUser();
		if ($unauthorized !== null) {
			return $unauthorized;
		}

		if ($wozobjectnummer === '') {
			return new JSONResponse(['error' => 'wozobjectnummer is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->wozAdapter->lookupByWozObjectNummer(wozobjectnummer: $wozobjectnummer);
		} catch (Throwable $e) {
			$this->logger->error('Procest WOZ object lookup failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'WOZ object lookup failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->toResponse(result: $result);
	}//end object()

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
	 * Wrap a WozLookupResult as a 200 JSON response — the adapter's own
	 * `lookupStatus` (including LOOKUP_DEFERRED / NOT_FOUND / INVALID_INPUT
	 * / LOOKUP_ERROR) carries the outcome; the controller never turns
	 * "not configured" or "not found" into an HTTP error.
	 *
	 * @param WozLookupResult $result Adapter result.
	 *
	 * @return JSONResponse
	 */
	private function toResponse(WozLookupResult $result): JSONResponse {
		return new JSONResponse(
			[
				'lookupStatus' => $result->lookupStatus,
				'wozObject' => $result->wozObject,
				'dormant' => $result->dormant,
				'extras' => $result->extras,
			]
		);
	}//end toResponse()
}//end class
