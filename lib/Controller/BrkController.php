<?php

/**
 * Procest BRK Controller
 *
 * HTTP surface for the BRK (Basisregistratie Kadaster) authoritative
 * parcel lookup seam (brk-woz-register-adapters):
 *   - GET /api/external/brk/parcel (kadastrale-aanduiding search)
 *   - GET /api/external/brk/parcel/{id}
 *
 * Auth posture and error-shape mirror `BagController` (the closest
 * existing base-registration HTTP sibling): `@NoAdminRequired` + an
 * explicit session guard returning 401, 400 for malformed input, and a
 * graceful 200 passthrough of the adapter's own `lookupStatus` for "not
 * configured" / "not found" rather than surfacing those as HTTP errors.
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

use OCA\Procest\Service\External\Brk\BrkAdapterInterface;
use OCA\Procest\Service\External\Brk\BrkLookupResult;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for BRK parcel lookups.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 *
 * @psalm-suppress UnusedClass
 */
class BrkController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App name
	 * @param IRequest $request Request
	 * @param BrkAdapterInterface $brkAdapter BRK lookup port
	 * @param IUserSession $userSession User session
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly BrkAdapterInterface $brkAdapter,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Look up a parcel by kadastrale aanduiding.
	 *
	 * Query parameters:
	 *   - kadastraleGemeenteCode (string, required)
	 *   - sectie (string, required)
	 *   - perceelnummer (string, required)
	 *   - appartementsrechtVolgnummer (string, optional)
	 *
	 * @return JSONResponse {lookupStatus, parcel, dormant, extras}
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function parcel(): JSONResponse {
		$unauthorized = $this->requireUser();
		if ($unauthorized !== null) {
			return $unauthorized;
		}

		$municipalityCode = (string)$this->request->getParam('kadastraleGemeenteCode', '');
		$section = (string)$this->request->getParam('sectie', '');
		$perceelnummer = (string)$this->request->getParam('perceelnummer', '');
		if ($municipalityCode === '' || $section === '' || $perceelnummer === '') {
			return new JSONResponse(
				['error' => 'kadastraleGemeenteCode, sectie and perceelnummer are required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$sequenceNumberParam = $this->request->getParam('appartementsrechtVolgnummer');
		$sequenceNumber = null;
		if (is_string($sequenceNumberParam) === true && $sequenceNumberParam !== '') {
			$sequenceNumber = $sequenceNumberParam;
		}

		try {
			$result = $this->brkAdapter->lookupByKadastraleAanduiding(
				kadastraleMunicipalityCode: $municipalityCode,
				section: $section,
				perceelnummer: $perceelnummer,
				appartementsrechtSequenceNumber: $sequenceNumber,
			);
		} catch (Throwable $e) {
			$this->logger->error('Procest BRK parcel lookup failed: ' . $e->getMessage());
			return new JSONResponse(
				['error' => 'BRK parcel lookup failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		return $this->toResponse(result: $result);
	}//end parcel()

	/**
	 * Look up a parcel by its Kadaster identificatie.
	 *
	 * @param string $id BRK kadastraalOnroerendeZaak identificatie.
	 *
	 * @return JSONResponse {lookupStatus, parcel, dormant, extras}
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function object(string $id): JSONResponse {
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
			$result = $this->brkAdapter->lookupObject(id: $id);
		} catch (Throwable $e) {
			$this->logger->error('Procest BRK object lookup failed: ' . $e->getMessage());
			return new JSONResponse(
				['error' => 'BRK object lookup failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
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
	 * Wrap a BrkLookupResult as a 200 JSON response — the adapter's own
	 * `lookupStatus` (including LOOKUP_DEFERRED / NOT_FOUND / INVALID_INPUT
	 * / LOOKUP_ERROR) carries the outcome; the controller never turns
	 * "not configured" or "not found" into an HTTP error.
	 *
	 * @param BrkLookupResult $result Adapter result.
	 *
	 * @return JSONResponse
	 */
	private function toResponse(BrkLookupResult $result): JSONResponse {
		return new JSONResponse(
			[
				'lookupStatus' => $result->lookupStatus,
				'parcel' => $result->parcel,
				'dormant' => $result->dormant,
				'extras' => $result->extras,
			]
		);
	}//end toResponse()
}//end class
