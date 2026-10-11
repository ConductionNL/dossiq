<?php

/**
 * Dossiq triage rule controller: a case's triage rules, kept and applied.
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
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Review\TriageRules;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Lists, creates, changes, deletes and applies the triage rules of a case.
 *
 * Every route checks the case through CaseAccessGuard in its body: listing
 * the rules needs read access to the case, everything else mutation access.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 */
class TriageRuleController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request         The request.
	 * @param TriageRules     $rules           The triage rules.
	 * @param CaseAccessGuard $caseAccessGuard The per-case access check.
	 * @param IUserSession    $userSession     The session.
	 * @param IL10N           $l10n            The translations.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly TriageRules $rules,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The case's rules in order, each with the documents it decided and the overturns.
	 *
	 * @param string $id The case UUID.
	 *
	 * @return JSONResponse `{results: [...]}`, or the refusal.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	#[NoAdminRequired]
	public function index(string $id): JSONResponse {
		$refused = $this->refusedFor(caseId: $id, mutate: false);
		if ($refused !== null) {
			return $refused;
		}

		return new JSONResponse(['results' => $this->rules->forCase(caseId: $id)]);
	}//end index()

	/**
	 * Create a rule.
	 *
	 * @param string $id The case UUID.
	 *
	 * @return JSONResponse The rule (201), or the refusal.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	#[NoAdminRequired]
	public function create(string $id): JSONResponse {
		$refused = $this->refusedFor(caseId: $id, mutate: true);
		if ($refused !== null) {
			return $refused;
		}

		try {
			return new JSONResponse($this->rules->save(caseId: $id, input: $this->input()), Http::STATUS_CREATED);
		} catch (RefusedException $e) {
			return $this->refusal(exception: $e);
		}
	}//end create()

	/**
	 * Change a rule: its name, conditions, effect, order, or whether it is active.
	 *
	 * @param string $id     The case UUID.
	 * @param string $ruleId The rule.
	 *
	 * @return JSONResponse The rule, or the refusal.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	#[NoAdminRequired]
	public function update(string $id, string $ruleId): JSONResponse {
		$refused = $this->refusedFor(caseId: $id, mutate: true);
		if ($refused !== null) {
			return $refused;
		}

		try {
			return new JSONResponse($this->rules->save(caseId: $id, input: $this->input(), ruleId: $ruleId));
		} catch (RefusedException $e) {
			return $this->refusal(exception: $e);
		}
	}//end update()

	/**
	 * Delete a rule; the documents it marked keep their marking.
	 *
	 * @param string $id     The case UUID.
	 * @param string $ruleId The rule.
	 *
	 * @return JSONResponse 204, or the refusal.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	#[NoAdminRequired]
	public function destroy(string $id, string $ruleId): JSONResponse {
		$refused = $this->refusedFor(caseId: $id, mutate: true);
		if ($refused !== null) {
			return $refused;
		}

		try {
			$this->rules->delete(caseId: $id, ruleId: $ruleId);
		} catch (RefusedException $e) {
			return $this->refusal(exception: $e);
		}

		return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
	}//end destroy()

	/**
	 * Apply the active rules to every unmarked document, and answer the count per rule.
	 *
	 * @param string $id The case UUID.
	 *
	 * @return JSONResponse `{counts, marked, considered}`, or the refusal.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	#[NoAdminRequired]
	public function apply(string $id): JSONResponse {
		$refused = $this->refusedFor(caseId: $id, mutate: true);
		if ($refused !== null) {
			return $refused;
		}

		try {
			return new JSONResponse($this->rules->apply(caseId: $id));
		} catch (RefusedException $e) {
			return $this->refusal(exception: $e);
		}
	}//end apply()

	/**
	 * The rule as the request gives it; only the fields it names.
	 *
	 * @return array<string, mixed>
	 */
	private function input(): array {
		$input = [];
		foreach (['name', 'conditions', 'effect', 'order', 'active'] as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$input[$field] = $value;
			}
		}

		if (isset($input['conditions']) === true && is_array($input['conditions']) === false) {
			$input['conditions'] = [];
		}

		if (isset($input['active']) === true) {
			$input['active'] = filter_var($input['active'], FILTER_VALIDATE_BOOLEAN);
		}

		return $input;
	}//end input()

	/**
	 * The refusal when the caller is not signed in or lacks access to the case, else null.
	 *
	 * @param string $caseId The case UUID.
	 * @param bool   $mutate Whether the route changes the case.
	 *
	 * @return JSONResponse|null
	 */
	private function refusedFor(string $caseId, bool $mutate): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->hasAccess(caseId: $caseId, user: $user, mutate: $mutate) === false) {
			return new JSONResponse(
				['error' => 'no-case-access', 'message' => $this->l10n->t('You do not have access to this case')],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end refusedFor()

	/**
	 * Whether the user may read, or change, the case.
	 *
	 * @param string $caseId The case UUID.
	 * @param IUser  $user   The user.
	 * @param bool   $mutate Whether the route changes the case.
	 *
	 * @return bool
	 */
	private function hasAccess(string $caseId, IUser $user, bool $mutate): bool {
		if ($mutate === true) {
			return $this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user);
		}

		return $this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user);
	}//end hasAccess()

	/**
	 * A refusal as JSON with its translated sentence.
	 *
	 * @param RefusedException $exception The refusal.
	 *
	 * @return JSONResponse
	 */
	private function refusal(RefusedException $exception): JSONResponse {
		$sentence = match ($exception->getRule()) {
			'triage-rule-name-required' => $this->l10n->t('Give the rule a name.'),
			'triage-rule-name-taken' => $this->l10n->t('Another rule of this case has that name.'),
			'triage-rule-effect-required' => $this->l10n->t('Choose whether the rule marks documents in scope or out of scope.'),
			'triage-rule-condition-required' => $this->l10n->t('Give the rule at least one condition, or it would mark every document.'),
			'triage-rule-condition-unknown' => $this->l10n->t('A rule can ask for custodian, source system, file type, a date range, sender domain, or text in the document.'),
			'triage-rule-date-invalid' => $this->l10n->t('Give the dates of a rule as a day, month and year.'),
			'triage-rule-unknown' => $this->l10n->t('This case has no such rule.'),
			default => $this->l10n->t('The triage rules cannot be stored or applied, so nothing was marked.'),
		};

		return new JSONResponse(['error' => $exception->getRule(), 'message' => $sentence], $exception->getStatus());
	}//end refusal()
}//end class
