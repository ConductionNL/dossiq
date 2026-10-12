<?php

/**
 * Dossiq recall controller: the stopping rule, recall samples and their judgements.
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
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Review\RecallSamples;
use OCA\Dossiq\Review\StoppingRules;
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
 * Declares the stopping rule of a document review, draws recall samples from the
 * null set and records their judgements. Every route checks the case through
 * CaseAccessGuard in its body: reading needs read access, the rest mutation access.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
 */
class RecallController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param StoppingRules $rules The stopping rule.
	 * @param RecallSamples $samples The recall samples.
	 * @param CaseAccessGuard $caseAccessGuard The per-case access check.
	 * @param IUserSession $userSession The session.
	 * @param IL10N $l10n The translations.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly StoppingRules $rules,
		private readonly RecallSamples $samples,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The stopping rule (or null) and every sample of the case, with progress and estimates.
	 *
	 * @param string $id The case UUID.
	 *
	 * @return JSONResponse `{rule, samples}`, or the refusal.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 */
	#[NoAdminRequired]
	public function recall(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthenticated();
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return $this->noAccess();
		}

		return new JSONResponse(
			[
				'rule' => $this->rules->forCase(caseId: $id),
				'reviewStarted' => $this->rules->reviewStarted(caseId: $id),
				'samples' => $this->samples->forCase(caseId: $id),
			]
		);
	}//end recall()

	/**
	 * Declare the stopping rule, while no document of the case is marked yet.
	 *
	 * @param string $id The case UUID.
	 *
	 * @return JSONResponse The rule (201), or the refusal; once review started, 409.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
	 */
	#[NoAdminRequired]
	public function declareStoppingRule(string $id): JSONResponse {
		$user = $this->mutatingUser(caseId: $id);
		if ($user instanceof JSONResponse === true) {
			return $user;
		}

		try {
			$rule = $this->rules->declare(
				caseId: $id,
				targetRecall: (float)$this->request->getParam('targetRecall', 0),
				confidence: (float)$this->request->getParam('confidence', 0),
				userId: $user->getUID(),
			);
		} catch (RefusedException $e) {
			return $this->refused(refusal: $e);
		}

		return new JSONResponse($rule, Http::STATUS_CREATED);
	}//end declareStoppingRule()

	/**
	 * Draw a recall sample of `size` from the null set and hand it to a reviewer (the caller by default).
	 *
	 * @param string $id The case UUID.
	 *
	 * @return JSONResponse The sample (201), or the refusal.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 */
	#[NoAdminRequired]
	public function drawSample(string $id): JSONResponse {
		$user = $this->mutatingUser(caseId: $id);
		if ($user instanceof JSONResponse === true) {
			return $user;
		}

		$assignee = trim((string)$this->request->getParam('assignee', ''));
		if ($assignee === '') {
			$assignee = $user->getUID();
		}

		try {
			$sample = $this->samples->draw(
				caseId: $id,
				size: (int)$this->request->getParam('size', 0),
				assignee: $assignee,
				userId: $user->getUID(),
			);
		} catch (RefusedException $e) {
			return $this->refused(refusal: $e);
		}

		return new JSONResponse($this->samples->withProgress(sample: $sample), Http::STATUS_CREATED);
	}//end drawSample()

	/**
	 * Judge one sampled document in or out of scope, in the sample only.
	 *
	 * @param string $id The case UUID.
	 * @param string $sampleId The recall sample.
	 *
	 * @return JSONResponse The sample with its progress and, once complete, its estimate; or the refusal.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 */
	#[NoAdminRequired]
	public function judge(string $id, string $sampleId): JSONResponse {
		$user = $this->mutatingUser(caseId: $id);
		if ($user instanceof JSONResponse === true) {
			return $user;
		}

		$inScope = filter_var($this->request->getParam('inScope', null), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		if ($inScope === null) {
			return new JSONResponse(
				['error' => 'review-recall-judgement-required', 'message' => $this->sentence(rule: 'review-recall-judgement-required')],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		try {
			$sample = $this->samples->judge(
				caseId: $id,
				sampleId: $sampleId,
				documentRef: (string)$this->request->getParam('documentRef', ''),
				inScope: $inScope,
				userId: $user->getUID(),
			);
		} catch (RefusedException $e) {
			return $this->refused(refusal: $e);
		}

		return new JSONResponse($sample);
	}//end judge()

	/**
	 * The signed-in user with mutation access to the case, or the refusal.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return IUser|JSONResponse The user, or the refusal.
	 */
	private function mutatingUser(string $caseId): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthenticated();
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return $this->noAccess();
		}

		return $user;
	}//end mutatingUser()

	/**
	 * The answer to a refusal.
	 *
	 * @param RefusedException $refusal The refusal.
	 *
	 * @return JSONResponse The answer.
	 */
	private function refused(RefusedException $refusal): JSONResponse {
		return new JSONResponse(
			['error' => $refusal->getRule(), 'message' => $this->sentence(rule: $refusal->getRule())],
			$refusal->getStatus()
		);
	}//end refused()

	/**
	 * The answer without a session.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function unauthenticated(): JSONResponse {
		return new JSONResponse(['error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
	}//end unauthenticated()

	/**
	 * The refusal of a user without access to the case.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function noAccess(): JSONResponse {
		return new JSONResponse(
			['error' => 'no-case-access', 'message' => $this->l10n->t('You do not have access to this case')],
			Http::STATUS_FORBIDDEN
		);
	}//end noAccess()

	/**
	 * The translated sentence of a refusal rule.
	 *
	 * @param string $rule The rule.
	 *
	 * @return string The sentence.
	 */
	private function sentence(string $rule): string {
		return match ($rule) {
			'review-stopping-rule-out-of-range' => $this->l10n->t('Choose a target between 0.5 and 0.99 and a confidence of 0.90, 0.95 or 0.99.'),
			'review-stopping-rule-locked' => $this->l10n->t('A stopping rule is declared before review. Once a document is marked, it cannot change.'),
			'review-stopping-rule-unavailable' => $this->l10n->t('The stopping rule cannot be stored, so nothing was declared.'),
			'review-recall-assignee-unknown' => $this->l10n->t('Choose the reviewer of the sample.'),
			'review-recall-size' => $this->l10n->t('Choose a sample size between 1 and the number of documents set aside or not yet marked.'),
			'review-recall-document-unknown' => $this->l10n->t('Only a sampled document can be judged in the sample.'),
			'review-recall-sample-closed' => $this->l10n->t('Every document of this sample is judged and its estimate is recorded.'),
			'review-recall-sample-unknown' => $this->l10n->t('This sample is not one of this case.'),
			'review-recall-judgement-required' => $this->l10n->t('Judge the document in scope or out of scope.'),
			'review-stopping-rule-met-not-recorded' => $this->l10n->t(
				'That the stopping rule was met cannot be recorded, so the last judgement was not stored.'
			),
			default => $this->l10n->t('The Recall sample cannot be stored, so nothing was drawn or judged.'),
		};
	}//end sentence()
}//end class
