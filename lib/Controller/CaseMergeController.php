<?php

/**
 * Dossiq Case Merge Controller.
 *
 *  - POST /api/case/{caseId}/merge
 *
 * One endpoint, because a merge is one decision made in two places. Whether
 * this case may be merged away is case management's rule, so dossiq decides
 * it; the merge itself is OpenRegister's (ADR-045), so dossiq asks. The
 * refusal lives here rather than only in the browser: an action the browser
 * hides is still an endpoint anyone signed in may call, and the case it would
 * collapse may be one with a signed beschikking on it.
 *
 * Each refusal carries a short static `code` beside the sentence, so the
 * dialog can act on the rule without reading the prose.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseMergeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Merge one case into another.
 *
 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
 */
class CaseMergeController extends Controller {
	/**
	 * The status each refusal answers with.
	 *
	 * @var array<string, int>
	 */
	private const REFUSAL_STATUS = [
		'same-case' => Http::STATUS_BAD_REQUEST,
		'unknown-case' => Http::STATUS_NOT_FOUND,
		'final-status' => Http::STATUS_CONFLICT,
		'signed-beschikking' => Http::STATUS_CONFLICT,
		'already-merged' => Http::STATUS_CONFLICT,
		'survivor-already-merged' => Http::STATUS_CONFLICT,
		'platform-unavailable' => Http::STATUS_SERVICE_UNAVAILABLE,
		'platform-refused' => Http::STATUS_CONFLICT,
	];

	/**
	 * Constructor.
	 *
	 * @param string           $appName         The app name.
	 * @param IRequest         $request         The HTTP request.
	 * @param CaseMergeService $mergeService    The rules, and the ask.
	 * @param CaseAccessGuard  $caseAccessGuard Per-case authorization (fails closed).
	 * @param IUserSession     $userSession     The current session.
	 * @param IL10N            $l10n            The translations.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseMergeService $mergeService,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Merge this case into another one.
	 *
	 * @param string $caseId The case to merge away.
	 * @param string $into   The case it becomes part of.
	 * @param string $reason What the caseworker typed.
	 *
	 * @return JSONResponse The merge operation, or the rule that refused it.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	#[NoAdminRequired]
	public function merge(string $caseId, string $into = '', string $reason = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('You are not signed in.')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		// Both halves, because a merge changes both. Mutation access to the
		// case being merged away is not access to the one it lands in.
		foreach ([$caseId, $into] as $subject) {
			if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $subject, user: $user) === false) {
				return new JSONResponse(
					['error' => $this->l10n->t('You cannot merge these cases.')],
					Http::STATUS_FORBIDDEN
				);
			}
		}

		$outcome = $this->mergeService->requestMerge(
			mergedId: $caseId,
			survivorId: $into,
			reason: $reason,
			actor: $user->getUID()
		);

		$refusal = (string)($outcome['refused'] ?? '');
		if ($refusal !== '') {
			return new JSONResponse(
				[
					'code' => $refusal,
					'error' => $this->sentence(rule: $refusal),
				],
				(int)(self::REFUSAL_STATUS[$refusal] ?? Http::STATUS_CONFLICT)
			);
		}

		return new JSONResponse(['operation' => ($outcome['operation'] ?? [])]);
	}//end merge()

	/**
	 * The sentence a refusal reads as.
	 *
	 * @param string $rule The refusing rule.
	 *
	 * @return string The sentence.
	 */
	private function sentence(string $rule): string {
		return match ($rule) {
			'same-case' => $this->l10n->t('Pick a different case to merge this one into.'),
			'unknown-case' => $this->l10n->t('One of these cases no longer exists.'),
			'final-status' => $this->l10n->t('This case is closed, so it cannot be merged away.'),
			'signed-beschikking' => $this->l10n->t('This case carries a signed decision, so it cannot be merged away.'),
			'already-merged' => $this->l10n->t('This case was already merged into another one.'),
			'survivor-already-merged' => $this->l10n->t('The case you picked was itself merged into another one.'),
			'platform-unavailable' => $this->l10n->t('Merging is unavailable right now.'),
			default => $this->l10n->t('These cases could not be merged.'),
		};
	}//end sentence()
}//end class
