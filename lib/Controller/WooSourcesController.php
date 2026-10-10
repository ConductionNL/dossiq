<?php

/**
 * Dossiq Woo sources controller
 *
 * Gather documents on a Woo case (woo-requests-gather-documents-from-sources,
 * design D-2 and D-3): which sources there are, a search of integriq's
 * Microsoft 365 connection, and adding picked results to the case. The two
 * platform sources (files, cases) are searched by the dialog itself through
 * Nextcloud's unified search, per ADR-022, so they have no search endpoint
 * here.
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
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Woo\WooGatherAdd;
use OCA\Dossiq\Woo\WooSources;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The three Gather documents endpoints, each behind a per-case guard.
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
 */
class WooSourcesController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string          $appName     The app name.
	 * @param IRequest        $request     The request.
	 * @param WooSources      $sources     The sources, and integriq's search.
	 * @param WooGatherAdd    $gatherAdd   Adds picks to the case.
	 * @param CaseAccessGuard $accessGuard Per-case read and change access, failing closed.
	 * @param IUserSession    $userSession The caller.
	 * @param IL10N           $l10n        Refusal sentences.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WooSources $sources,
		private readonly WooGatherAdd $gatherAdd,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The sources a handler can search from this case, each with whether it can answer now.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse `{sources}`, or 401/403.
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
	 */
	#[NoAdminRequired]
	public function index(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->accessGuard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return $this->forbidden();
		}

		return new JSONResponse(['sources' => $this->sources->list()]);
	}//end index()

	/**
	 * Search integriq's connection; the platform sources are searched by the dialog directly.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse `{source, rows, remaining, notices}`, or a refusal.
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
	 */
	#[NoAdminRequired]
	public function search(string $id): JSONResponse {
		$user = $this->changer(caseId: $id);
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$source = (string)$this->request->getParam('source', '');
		$terms = trim((string)$this->request->getParam('terms', ''));
		if ($source !== WooSources::SOURCE_MICROSOFT365) {
			return new JSONResponse(
				['error' => 'unsupported_source', 'message' => $this->l10n->t('This source is searched through the Nextcloud search, not here.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($terms === '') {
			return new JSONResponse(['error' => 'terms_required', 'message' => $this->l10n->t('Enter what to search for.')], Http::STATUS_BAD_REQUEST);
		}

		$answer = $this->sources->searchMicrosoft365(
			terms: $terms,
			from: $this->dateParam(name: 'from'),
			to: $this->dateParam(name: 'to'),
			userId: $user->getUID(),
		);
		if ($answer['refusal'] !== '') {
			return new JSONResponse(
				['error' => $answer['refusal'], 'message' => $this->l10n->t('SharePoint, Teams and mail cannot be searched now.')],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse(
			[
				'source' => $source,
				'rows' => $answer['rows'],
				'remaining' => $answer['remaining'],
				'notices' => $answer['notices'],
			]
		);
	}//end search()

	/**
	 * Add picked results to the case, each pick answered on its own.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse `{results, added, refused}`: 200 when at least one was added, 422 when none was.
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
	 */
	#[NoAdminRequired]
	public function add(string $id): JSONResponse {
		$user = $this->changer(caseId: $id);
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$picks = $this->request->getParam('picks', []);
		if (is_string($picks) === true) {
			$picks = (json_decode($picks, true) ?? []);
		}

		if (is_array($picks) === false || $picks === []) {
			return new JSONResponse(['error' => 'picks_required', 'message' => $this->l10n->t('Pick at least one result to add.')], Http::STATUS_BAD_REQUEST);
		}

		$results = $this->gatherAdd->addPicks(
			caseId: $id,
			picks: array_values($picks),
			terms: trim((string)$this->request->getParam('terms', '')),
			user: $user,
		);
		$added = count(array_filter($results, static fn (array $result): bool => $result['status'] === 'added'));

		$status = Http::STATUS_OK;
		if ($added === 0) {
			$status = Http::STATUS_UNPROCESSABLE_ENTITY;
		}

		return new JSONResponse(['results' => $results, 'added' => $added, 'refused' => (count($results) - $added)], $status);
	}//end add()

	/**
	 * The caller when they may change the case, otherwise the refusal to answer.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return IUser|JSONResponse The caller, or 401/403.
	 */
	private function changer(string $caseId): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->accessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return $this->forbidden();
		}

		return $user;
	}//end changer()

	/**
	 * A date parameter as Y-m-d, or null when absent or not a date.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string|null The date.
	 */
	private function dateParam(string $name): ?string {
		$value = trim((string)$this->request->getParam($name, ''));
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return null;
		}

		return $value;
	}//end dateParam()

	/**
	 * The answer for a caller without access to the case.
	 *
	 * @return JSONResponse 403.
	 */
	private function forbidden(): JSONResponse {
		return new JSONResponse(['error' => 'forbidden', 'message' => $this->l10n->t('You do not have access to this case.')], Http::STATUS_FORBIDDEN);
	}//end forbidden()
}//end class
