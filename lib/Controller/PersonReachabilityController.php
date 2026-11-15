<?php

/**
 * How a person can be reached (DqContact "Bereikbaar via").
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
 * @spec openspec/changes/contact-reachability/specs/party-pages/spec.md#requirement-a-contact-says-how-the-person-can-be-reached
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\Contracts\PersonReachabilityInterface;
use OCA\Dossiq\Kernel\Contracts\ReadableObjectsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * The person's portal mailbox and newest contact, for a user who may read the person.
 *
 * @spec openspec/changes/contact-reachability/specs/party-pages/spec.md#requirement-a-contact-says-how-the-person-can-be-reached
 */
class PersonReachabilityController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string             $appName      The app name.
	 * @param IRequest           $request      The request.
	 * @param PersonReachabilityInterface $reachability Reads how the person can be reached.
	 * @param ReadableObjectsInterface $readable Whether a caller is signed in and may read the requested object.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PersonReachabilityInterface $reachability,
		private readonly ReadableObjectsInterface $readable,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * How the person can be reached; 404 when the user may not read the person.
	 *
	 * @param string $id The brpPerson uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/contact-reachability/specs/party-pages/spec.md#requirement-a-contact-says-how-the-person-can-be-reached
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		if ($this->readable->hasCaller() === false) {
			return new JSONResponse(['error' => 'Sign in to read this person.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			if ($this->readable->findsAsCaller(schema: 'brpPerson', id: $id) === false) {
				return new JSONResponse(['error' => 'Person not found'], Http::STATUS_NOT_FOUND);
			}

			$answer = $this->reachability->forPerson(personId: trim($id));
		} catch (Throwable) {
			return new JSONResponse(['error' => 'The person could not be read. Try again later.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($answer === null) {
			return new JSONResponse(['error' => 'Person not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($answer);
	}//end show()
}//end class
