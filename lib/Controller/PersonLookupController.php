<?php

/**
 * "Zoek op BSN": the party picker's exact, guarded and recorded lookup.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bsn-exact-lookup/specs/initiator-selection/spec.md#requirement-a-person-is-found-by-bsn-through-a-guarded-recorded-lookup
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\People\PersonNumberLookup;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * POST `{ term }`: the people with that BSN, for a caller allowed to look citizens up.
 *
 * @spec openspec/changes/bsn-exact-lookup/specs/initiator-selection/spec.md#requirement-a-person-is-found-by-bsn-through-a-guarded-recorded-lookup
 */
class PersonLookupController extends Controller {

	/**
	 * How many BSN lookups one user may make per hour, as the KCC lookup.
	 */
	public const LOOKUP_LIMIT_PER_HOUR = 60;

	/**
	 * Constructor.
	 *
	 * @param string             $appName     The app name.
	 * @param IRequest           $request     The request.
	 * @param PersonNumberLookup $lookup      The guarded lookup.
	 * @param IUserSession       $userSession The caller.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PersonNumberLookup $lookup,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The people with the posted BSN; 403 when the caller may not look citizens up.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bsn-exact-lookup/specs/initiator-selection/spec.md#requirement-a-person-is-found-by-bsn-through-a-guarded-recorded-lookup
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::LOOKUP_LIMIT_PER_HOUR, period: 3600)]
	public function lookup(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$people = $this->lookup->find(user: $user, bsn: (string)$this->request->getParam('term', ''));
		} catch (Throwable) {
			return new JSONResponse(['message' => 'The lookup could not be completed. Try again later.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($people === null) {
			return new JSONResponse(['message' => 'You may not look a citizen up by BSN.'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['results' => $people, 'total' => count($people)]);
	}//end lookup()
}//end class
