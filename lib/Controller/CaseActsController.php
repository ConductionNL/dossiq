<?php

/**
 * The acts on a case that are not status moves and not statutory gestures.
 *
 *  - GET  /api/case/{caseId}/acts             what this handler may do, and why not
 *  - POST /api/case/{caseId}/finish           {reason, resultTypeId?, status?}
 *  - POST /api/case/{caseId}/abort            {reason, resultTypeId?, status?}
 *  - POST /api/case/{caseId}/archive          {reason}
 *  - POST /api/case/{caseId}/hold             {reason, until}
 *  - POST /api/case/{caseId}/release-hold     {reason}
 *  - POST /api/case/{caseId}/draft            (no body)
 *  - POST /api/case/{caseId}/promote          (no body)
 *  - POST /api/case/{caseId}/incompleteness   {fields: [...]}
 *
 * 🔴 WHY A SECOND CONTROLLER RATHER THAN MORE METHODS ON
 * {@see CaseLifecycleController}. That one owns opschorten, hervatten,
 * verlengen and heropenen: four statutory gestures over the TERM, each of
 * which goes through a TermijnInstance. These nine are acts over the case's
 * own state, and three of them are gated by a role the case type declares
 * rather than by the admin group. Two different gates and two different
 * subjects in one class is how the wrong guard ends up on the wrong method.
 *
 * Every method is `#[NoAdminRequired]` and every method runs the per-case
 * guard first: without it these would be nine ways for any signed-in user to
 * act on any case by its uuid. The act's own role gate runs after, inside the
 * service, because it needs the case's type and the guard has already proved
 * the caller may touch the case at all.
 *
 * Refusals are {@see RefusedException}s and are rendered by
 * {@see TranslatesRefusals}: `{message, error}` per ADR-050, with the rule
 * slug in `error` and the sentence the throw site authored in `message`.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\Lifecycle\CaseHoldActs;
use OCA\Dossiq\Service\Lifecycle\CaseIncompleteness;
use OCA\Dossiq\Service\Lifecycle\DraftCaseActs;
use OCA\Dossiq\Service\Lifecycle\LifecycleActorGate;
use OCA\Dossiq\Service\Lifecycle\ProcessOwnedStatusRule;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finish, abort, archive, hold, draft, promote and record incompleteness.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
class CaseActsController extends Controller {

	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The HTTP request.
	 * @param CaseEndingActs $endings Finish, abort and archive.
	 * @param CaseHoldActs $holds Hold and release.
	 * @param DraftCaseActs $drafts Begin and promote.
	 * @param CaseIncompleteness $incompleteness Record what is missing.
	 * @param LifecycleActorGate $gate Whether an act is permitted, and which role is missing.
	 * @param ProcessOwnedStatusRule $processStatus Whether a status may be hand-set.
	 * @param CaseStatusStore $store Reads the case for the menu.
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization, failing closed.
	 * @param IUserSession $userSession The current session.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseEndingActs $endings,
		private readonly CaseHoldActs $holds,
		private readonly DraftCaseActs $drafts,
		private readonly CaseIncompleteness $incompleteness,
		private readonly LifecycleActorGate $gate,
		private readonly ProcessOwnedStatusRule $processStatus,
		private readonly CaseStatusStore $store,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * What this handler may do to this case, with a reason on every refusal.
	 *
	 * 🔑 A REFUSED ACT IS LISTED, NOT OMITTED. The menu shows it disabled with
	 * the reason, because an act that is simply absent teaches nobody why. So
	 * the answer carries every act, each with `allowed` and, when it is not,
	 * the sentence naming the role or the state that refuses it.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function acts(string $caseId): JSONResponse {
		return $this->guarded(
			caseId: $caseId,
			run: function () use ($caseId): array {
				$case = $this->store->loadCase(caseId: $caseId);
				if ($case === null) {
					throw new RefusedException(
						rule: 'case-not-found',
						sentence: 'This case could not be found.',
						status: RefusedException::STATUS_UNPROCESSABLE,
					);
				}

				return [
					'caseId' => $caseId,
					'held' => $this->holds->isHeld(case: $case),
					'heldUntil' => (string)($case[CaseHoldActs::UNTIL_FIELD] ?? ''),
					'draft' => $this->drafts->isDraft(case: $case),
					'incomplete' => ($this->incompleteness->missingOn(case: $case) !== []),
					'missingFields' => $this->incompleteness->missingOn(case: $case),
					'endingAct' => (string)($case[CaseEndingActs::ENDING_FIELD] ?? ''),
					'ending' => $this->endings->endingOf(case: $case),
					'statusIsHandSettable' => $this->processStatus->allowsHandSet(
						caseTypeId: (string)($case['caseType'] ?? '')
					),
					'acts' => $this->actList(case: $case),
				];
			},
		);
	}//end acts()

	/**
	 * Finish the case: it reached its result.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function finish(string $caseId): JSONResponse {
		return $this->guarded(
			caseId: $caseId,
			run: fn (): array => $this->endings->finish(
				caseId: $caseId,
				reason: (string)$this->request->getParam('reason', ''),
				resultTypeId: (string)$this->request->getParam('resultTypeId', ''),
				toStatus: (string)$this->request->getParam('status', ''),
			),
		);
	}//end finish()

	/**
	 * Abort the case: an intrekking, and no besluit.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function abort(string $caseId): JSONResponse {
		return $this->guarded(
			caseId: $caseId,
			run: fn (): array => $this->endings->abort(
				caseId: $caseId,
				reason: (string)$this->request->getParam('reason', ''),
				resultTypeId: (string)$this->request->getParam('resultTypeId', ''),
				toStatus: (string)$this->request->getParam('status', ''),
			),
		);
	}//end abort()

	/**
	 * Archive a finished case: commit the retention its result type carries.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function archive(string $caseId): JSONResponse {
		return $this->guarded(
			caseId: $caseId,
			run: fn (): array => $this->endings->archive(
				caseId: $caseId,
				reason: (string)$this->request->getParam('reason', ''),
			),
		);
	}//end archive()

	/**
	 * Hold the case until a date.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function hold(string $caseId): JSONResponse {
		return $this->guarded(
			caseId: $caseId,
			run: fn (): array => $this->holds->hold(
				caseId: $caseId,
				reason: (string)$this->request->getParam('reason', ''),
				until: (string)$this->request->getParam('until', ''),
			),
		);
	}//end hold()

	/**
	 * Take the case off hold.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function releaseHold(string $caseId): JSONResponse {
		return $this->guarded(
			caseId: $caseId,
			run: fn (): array => $this->holds->release(
				caseId: $caseId,
				reason: (string)$this->request->getParam('reason', ''),
			),
		);
	}//end releaseHold()

	/**
	 * Make this case a draft: no term, no list, its author only.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function draft(string $caseId): JSONResponse {
		return $this->guarded(
			caseId: $caseId,
			run: fn (): array => $this->drafts->begin(caseId: $caseId),
		);
	}//end draft()

	/**
	 * Promote a draft into a case, binding the term.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function promote(string $caseId): JSONResponse {
		return $this->guarded(
			caseId: $caseId,
			run: fn (): array => $this->drafts->promote(caseId: $caseId),
		);
	}//end promote()

	/**
	 * Record which required fields were knowingly left empty.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function incompleteness(string $caseId): JSONResponse {
		$fields = $this->request->getParam('fields', []);
		if (is_array($fields) === false) {
			$fields = [];
		}

		return $this->guarded(
			caseId: $caseId,
			run: fn (): array => $this->incompleteness->record(caseId: $caseId, missing: $fields),
		);
	}//end incompleteness()

	/**
	 * The three role-gated acts, each with its verdict and its reason.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return array<int, array<string, mixed>> One entry per act.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	private function actList(array $case): array {
		$acts = [];
		foreach (['finish', 'abort', 'archive'] as $act) {
			$allowed = $this->gate->may(act: $act, case: $case);
			$acts[] = [
				'act' => $act,
				'allowed' => $allowed,
				'role' => $this->gate->roleFor(act: $act, case: $case),
				'reason' => ($allowed === true ? '' : $this->gate->refusalSentence(act: $act, case: $case)),
			];
		}

		return $acts;
	}//end actList()

	/**
	 * Run one act behind the session and per-case guards.
	 *
	 * The guard runs BEFORE the act, so a refused caller never reaches a
	 * service and never learns whether the case exists.
	 *
	 * @param string $caseId The case UUID.
	 * @param callable(): array<string, mixed> $run The act.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	private function guarded(string $caseId, callable $run): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Sign in first.', 'error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['message' => 'You do not work on this case.', 'error' => 'not-authorized'],
				Http::STATUS_FORBIDDEN,
			);
		}

		try {
			return new JSONResponse($run());
		} catch (RefusedException $e) {
			return $this->refused(op: 'case act', e: $e);
		} catch (Throwable $e) {
			$this->logger->error(
				'CaseActsController: the act failed',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);

			return new JSONResponse(
				['message' => 'The case could not be changed.', 'error' => 'act-failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}//end try
	}//end guarded()
}//end class
