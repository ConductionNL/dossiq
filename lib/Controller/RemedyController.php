<?php

/**
 * Dossiq remedy controller
 *
 * POST /api/cases/{id}/remedy: lodge a remedy (a bezwaar, or whatever the case
 * type declares) against the decision of a decided case. Generic (decision
 * 182): the case type's `remedy` declaration says what it opens.
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
 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Remedy\RemedyLodging;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Lodges a remedy against a decided case.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
 */
class RemedyController extends Controller {

	/**
	 * The objection fields the dialog may send; everything else is dropped.
	 */
	private const FIELDS = ['receivedDate', 'receivedChannel', 'grounds', 'requestedRelief'];

	/**
	 * Constructor.
	 *
	 * @param string          $appName     The app name.
	 * @param IRequest        $request     The request.
	 * @param RemedyLodging   $lodging     Opens the remedy case and records its objection.
	 * @param CaseAccessGuard $accessGuard Per-case authorization, failing closed.
	 * @param IUserSession    $userSession The session.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RemedyLodging $lodging,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Lodge a remedy against the decision of case `$id`.
	 *
	 * @param string $id The decided case.
	 *
	 * @return JSONResponse `{caseId, objectionId, deadline, isTimely}`, or the refusal.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
	 */
	#[NoAdminRequired]
	public function lodge(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->accessGuard->hasCaseMutationAccess(caseId: $id, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		$objection = [];
		foreach (self::FIELDS as $field) {
			$value = $this->request->getParam(key: $field);
			if (is_string($value) === true && trim($value) !== '') {
				$objection[$field] = trim($value);
			}
		}

		try {
			return new JSONResponse($this->lodging->lodge(caseId: $id, objection: $objection), Http::STATUS_CREATED);
		} catch (RefusedException $e) {
			return new JSONResponse(['error' => $e->getRule(), 'message' => $e->getSentence()], $e->getStatus());
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end lodge()
}//end class
