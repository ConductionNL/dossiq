<?php

/**
 * Dossiq Woo collection controller
 *
 * The corpus of a Woo request (woo-request-corpus-collection): the search plan,
 * the collection report, setting a document aside before review, and the
 * stored queries a colleague can re-run. Reading needs read access to the
 * case; changing needs change access. Re-running is reading: the query runs
 * with the caller's own access.
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
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-collection-is-reported-per-custodian-and-system-req-wrc-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Woo\WooCollection;
use OCA\Dossiq\Woo\WooCollectionQueries;
use OCA\Dossiq\Woo\WooCorpusRefused;
use OCA\Dossiq\Woo\WooSearchPlans;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use RuntimeException;

/**
 * The corpus endpoints of a Woo case, each behind a per-case guard.
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-collection-is-reported-per-custodian-and-system-req-wrc-002
 */
class WooCollectionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string               $appName     The app name.
	 * @param IRequest             $request     The request.
	 * @param WooSearchPlans       $plans       The search plan.
	 * @param WooCollection        $collection  The report and the exclusions.
	 * @param WooCollectionQueries $queries     The stored queries.
	 * @param CaseAccessGuard      $accessGuard Per-case read and change access, failing closed.
	 * @param IUserSession         $userSession The caller.
	 * @param IL10N                $l10n        Refusal sentences.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WooSearchPlans $plans,
		private readonly WooCollection $collection,
		private readonly WooCollectionQueries $queries,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The case's search plan, draft or recorded.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse `{plan, recorded}`.
	 *
	 * @throws \RuntimeException Never past run(): it answers 503 when OpenRegister is not configured.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
	 */
	#[NoAdminRequired]
	public function plan(string $id): JSONResponse {
		return $this->run(caseId: $id, change: false, step: function () use ($id): JSONResponse {
			$plan = $this->plans->find(caseId: $id);
			return new JSONResponse(['plan' => $plan, 'recorded' => ($this->plans->recorded(caseId: $id) !== null)]);
		});
	}//end plan()

	/**
	 * Record the case's search plan.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse The plan as stored, or 400 naming what is missing.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
	 */
	#[NoAdminRequired]
	public function recordPlan(string $id): JSONResponse {
		return $this->run(caseId: $id, change: true, step: function (IUser $user) use ($id): JSONResponse {
			$input = [];
			foreach (['custodians', 'systems', 'periodFrom', 'periodTo', 'terms'] as $key) {
				$input[$key] = $this->request->getParam($key);
			}

			return new JSONResponse(['plan' => $this->plans->record(caseId: $id, input: $input, userId: $user->getUID())]);
		});
	}//end recordPlan()

	/**
	 * The collection report.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse `{custodians, systems, exclusions, arrived, assessed, excluded, outstanding}`.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-collection-is-reported-per-custodian-and-system-req-wrc-002
	 */
	#[NoAdminRequired]
	public function report(string $id): JSONResponse {
		return $this->run(caseId: $id, change: false, step: fn (): JSONResponse => new JSONResponse($this->collection->report(caseId: $id)));
	}//end report()

	/**
	 * Set a document on the case aside before review.
	 *
	 * @param string $id          The case uuid.
	 * @param string $documentRef The document uuid.
	 *
	 * @return JSONResponse The exclusion, or 400/404/409.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-every-exclusion-before-review-is-kept-with-its-reason-req-wrc-003
	 */
	#[NoAdminRequired]
	public function exclude(string $id, string $documentRef): JSONResponse {
		return $this->run(caseId: $id, change: true, step: function (IUser $user) use ($id, $documentRef): JSONResponse {
			$exclusion = $this->collection->exclude(
				caseId: $id,
				documentRef: $documentRef,
				reason: (string)$this->request->getParam('reason', ''),
				note: trim((string)$this->request->getParam('note', '')),
				userId: $user->getUID(),
			);

			return new JSONResponse(['exclusion' => $exclusion], Http::STATUS_CREATED);
		});
	}//end exclude()

	/**
	 * The case's stored queries.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse `{queries}`.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-selecting-query-is-saved-and-can-be-re-run-req-wrc-004
	 */
	#[NoAdminRequired]
	public function queries(string $id): JSONResponse {
		return $this->run(caseId: $id, change: false, step: fn (): JSONResponse => new JSONResponse(['queries' => $this->queries->forCase(caseId: $id)]));
	}//end queries()

	/**
	 * Store a platform search the dialog ran through Nextcloud's unified search.
	 *
	 * @param string $id The case uuid.
	 *
	 * @return JSONResponse The query as stored.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-selecting-query-is-saved-and-can-be-re-run-req-wrc-004
	 */
	#[NoAdminRequired]
	public function storeQuery(string $id): JSONResponse {
		return $this->run(caseId: $id, change: true, step: function (IUser $user) use ($id): JSONResponse {
			$search = [];
			foreach (['source', 'terms', 'periodFrom', 'periodTo', 'filters', 'resultKeys'] as $key) {
				$search[$key] = $this->request->getParam($key);
			}

			return new JSONResponse(['query' => $this->queries->store(caseId: $id, search: $search, userId: $user->getUID())], Http::STATUS_CREATED);
		});
	}//end storeQuery()

	/**
	 * Run a stored query again with the caller's own access.
	 *
	 * @param string $id      The case uuid.
	 * @param string $queryId The query uuid.
	 *
	 * @return JSONResponse `{rows}` each with `new`, or 503 when the source cannot answer.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-selecting-query-is-saved-and-can-be-re-run-req-wrc-004
	 */
	#[NoAdminRequired]
	public function rerun(string $id, string $queryId): JSONResponse {
		return $this->run(caseId: $id, change: false, step: function (IUser $user) use ($id, $queryId): JSONResponse {
			$answer = $this->queries->rerun(caseId: $id, queryId: $queryId, userId: $user->getUID());
			if ($answer['refusal'] !== '') {
				$message = $this->l10n->t('This source cannot be searched now.');
				return new JSONResponse(['error' => $answer['refusal'], 'message' => $message], Http::STATUS_SERVICE_UNAVAILABLE);
			}

			return new JSONResponse(['rows' => $answer['rows']]);
		});
	}//end rerun()

	/**
	 * Check the caller, run the step, and translate a refusal.
	 *
	 * @param string   $caseId The case uuid.
	 * @param bool     $change Whether the step changes the case.
	 * @param callable $step   The step, given the caller.
	 *
	 * @return JSONResponse The step's answer, or 401/403/4xx/503.
	 */
	private function run(string $caseId, bool $change, callable $step): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$allowed = $this->accessGuard->hasCaseReadAccess(caseId: $caseId, user: $user);
		if ($change === true) {
			$allowed = $this->accessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user);
		}

		if ($allowed === false) {
			return new JSONResponse(['error' => 'forbidden', 'message' => $this->l10n->t('You do not have access to this case.')], Http::STATUS_FORBIDDEN);
		}

		try {
			return $step($user);
		} catch (WooCorpusRefused $refused) {
			$reason = $refused->getMessage();
			return new JSONResponse(['error' => $reason, 'message' => $this->sentence(reason: $reason)], $refused->getStatus());
		} catch (RuntimeException $e) {
			$message = $this->l10n->t('OpenRegister is not available.');
			return new JSONResponse(['error' => 'unavailable', 'message' => $message], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end run()

	/**
	 * The sentence for a refusal.
	 *
	 * @param string $reason The reason code.
	 *
	 * @return string The sentence.
	 */
	private function sentence(string $reason): string {
		return match ($reason) {
			'plan_incomplete_custodians' => $this->l10n->t('Name at least one person or function whose files are searched.'),
			'plan_incomplete_systems' => $this->l10n->t('Pick at least one system to search.'),
			'plan_incomplete_period' => $this->l10n->t('Give the period to search, with the start before the end.'),
			'plan_incomplete_terms' => $this->l10n->t('Enter what to search for.'),
			'document_assessed' => $this->l10n->t('This document is already assessed, so it cannot be set aside.'),
			'document_excluded' => $this->l10n->t('This document is already set aside.'),
			'document_not_on_case' => $this->l10n->t('This document is not on the case.'),
			'unknown_reason' => $this->l10n->t('Pick why the document is set aside.'),
			'query_not_found' => $this->l10n->t('This search is not stored on this case.'),
			'unknown_source' => $this->l10n->t('This source is not known.'),
			default => $this->l10n->t('This could not be done.'),
		};
	}//end sentence()
}//end class
