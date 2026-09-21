<?php

/**
 * Dossiq Case Term Follow Controller.
 *
 *  - POST /api/case/{caseId}/term-follow/{taskId}/accept
 *  - POST /api/case/{caseId}/term-follow/{taskId}/decline
 *
 * The two answers a handler can give to "the case you wait on moved by N days,
 * shall this one move too". Accepting goes through the ordinary extension and
 * meets the ordinary refusals; declining changes nothing and closes the task.
 *
 * 🔴 THE NUMBER OF DAYS IS NOT AN INPUT. It was written onto the task by the
 * listener that made the offer, and is read back off the task here. An
 * endpoint that took it from the request would let any signed-in handler move
 * a statutory term by any number they cared to type.
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
 * @spec openspec/changes/dependent-term-follows-predecessor/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Term\DependentTermOffer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Accept or decline a followed term move.
 *
 * @spec openspec/changes/dependent-term-follows-predecessor/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
 */
class CaseTermFollowController extends Controller {
	/**
	 * The status each refusal answers with.
	 *
	 * @var array<string, int>
	 */
	private const REFUSAL_STATUS = [
		'not-a-term-follow-task' => Http::STATUS_NOT_FOUND,
		'term-gone' => Http::STATUS_NOT_FOUND,
		'term-has-no-end-date' => Http::STATUS_CONFLICT,
		'extension-refused' => Http::STATUS_CONFLICT,
	];

	/**
	 * Constructor.
	 *
	 * @param string             $appName         The app name.
	 * @param IRequest           $request         The HTTP request.
	 * @param DependentTermOffer $offers          Makes and settles the offer.
	 * @param CaseAccessGuard    $caseAccessGuard Per-case authorization (fails closed).
	 * @param IUserSession       $userSession     The current session.
	 * @param IL10N              $l10n            The translations.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DependentTermOffer $offers,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Move this case's term by the days the offer carries.
	 *
	 * @param string $caseId The waiting case.
	 * @param string $taskId The offer.
	 *
	 * @return JSONResponse The recorded term event, or the rule that refused it.
	 *
	 * @spec openspec/changes/dependent-term-follows-predecessor/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
	 */
	#[NoAdminRequired]
	public function accept(string $caseId, string $taskId): JSONResponse {
		$refusal = $this->requireHandler(caseId: $caseId);
		if ($refusal !== null) {
			return $refusal;
		}

		$outcome = $this->offers->accept(
			taskId: $taskId,
			actor: $this->userSession->getUser()?->getUID()
		);

		$rule = (string)($outcome['refused'] ?? '');
		if ($rule !== '') {
			return new JSONResponse(
				['code' => $rule, 'error' => $this->sentence(rule: $rule)],
				(int)(self::REFUSAL_STATUS[$rule] ?? Http::STATUS_CONFLICT)
			);
		}

		return new JSONResponse(['event' => ($outcome['event'] ?? [])]);
	}//end accept()

	/**
	 * Leave this case's term where it is, and close the offer.
	 *
	 * @param string $caseId The waiting case.
	 * @param string $taskId The offer.
	 *
	 * @return JSONResponse Whether the offer was closed.
	 *
	 * @spec openspec/changes/dependent-term-follows-predecessor/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
	 */
	#[NoAdminRequired]
	public function decline(string $caseId, string $taskId): JSONResponse {
		$refusal = $this->requireHandler(caseId: $caseId);
		if ($refusal !== null) {
			return $refusal;
		}

		$closed = $this->offers->decline(
			taskId: $taskId,
			actor: $this->userSession->getUser()?->getUID()
		);

		if ($closed === false) {
			return new JSONResponse(
				[
					'code' => 'not-a-term-follow-task',
					'error' => $this->sentence(rule: 'not-a-term-follow-task'),
				],
				Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(['declined' => true]);
	}//end decline()

	/**
	 * Refuse anyone who may not act on this case.
	 *
	 * @param string $caseId The case.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may act.
	 */
	private function requireHandler(string $caseId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('You are not signed in.')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('You do not handle this case.')],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end requireHandler()

	/**
	 * The sentence a refusal reads as.
	 *
	 * @param string $rule The refusing rule.
	 *
	 * @return string The sentence.
	 */
	private function sentence(string $rule): string {
		return match ($rule) {
			'not-a-term-follow-task' => $this->l10n->t('That offer no longer exists.'),
			'term-gone' => $this->l10n->t('This case no longer has the term the offer was about.'),
			'term-has-no-end-date' => $this->l10n->t('This term has no end date to move.'),
			'extension-refused' => $this->l10n->t('This term cannot be extended any further. Ask a supervisor.'),
			default => $this->l10n->t('The term was not moved.'),
		};
	}//end sentence()
}//end class
