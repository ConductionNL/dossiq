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
use OCA\Dossiq\Service\Lifecycle\ArchiveRequesterNotice;
use OCA\Dossiq\Service\Lifecycle\CaseActs;
use OCA\Dossiq\Service\Lifecycle\CaseActsOverview;
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
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Ten routed endpoints over one
 *  resource, each a three-line call into {@see CaseActs} or
 *  {@see CaseActsOverview} and back through TranslatesRefusals. The eleventh
 *  public method phpmd counts is the constructor, which a Nextcloud controller
 *  cannot do without. The rest is the number of acts a case has, not a
 *  responsibility this class took on: there is no behaviour here to move. A
 *  second controller would split one resource's route table in two and give a
 *  reader of appinfo/routes.php two places to look for "what can happen to a
 *  case".
 *
 *  Checked again on 2026-09-20 rather than carried over: every one of the ten
 *  is registered in appinfo/routes.php, so dropping one would drop an endpoint
 *  rather than move code.
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
	 * @param CaseActs $acts Every act on a case, behind one collaborator.
	 * @param CaseActsOverview $overview The read the menu is drawn from.
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization, failing closed.
	 * @param IUserSession $userSession The current session.
	 * @param LoggerInterface $logger The logger.
	 * @param ArchiveRequesterNotice|null $archiveNotice Tells the requester an archived case is completed.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseActs $acts,
		private readonly CaseActsOverview $overview,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ?ArchiveRequesterNotice $archiveNotice = null,
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
	 * Anyone who may read the case reads it; a reader who is not its handler
	 * gets every act refused with the sentence that says to claim it first.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 * @spec openspec/changes/a-handling-team-opens-its-unclaimed-cases/specs/case-access-control/spec.md#requirement-a-handling-team-member-opens-a-case-of-its-case-type-before-it-is-claimed-req-acc-11
	 */
	#[NoAdminRequired]
	public function acts(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Sign in first.', 'error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['message' => 'You do not work on this case.', 'error' => 'not-authorized'],
				Http::STATUS_FORBIDDEN,
			);
		}

		$mayAct = $this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user);

		return $this->answered(
			caseId: $caseId,
			run: fn (): array => $this->refusedUnlessHandler(overview: $this->overview->overview(caseId: $caseId), mayAct: $mayAct),
		);
	}//end acts()

	/**
	 * Whether this case is ready to be archived, item by item, for the archive dialog.
	 *
	 * Read access is enough, as for the act menu: the checklist tells, the
	 * archive act itself refuses whoever may not archive.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function archiveReadiness(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Sign in first.', 'error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['message' => 'You do not work on this case.', 'error' => 'not-authorized'],
				Http::STATUS_FORBIDDEN,
			);
		}

		return $this->answered(
			caseId: $caseId,
			run: fn (): array => $this->overview->archiveReadiness(caseId: $caseId),
		);
	}//end archiveReadiness()

	/**
	 * The overview as a reader who may not act sees it: every act listed, none allowed.
	 *
	 * A handling team member reads an unclaimed case of their team, but only
	 * its handler acts on it, so each act carries the sentence that says so.
	 *
	 * @param array<string, mixed> $overview The overview for the handler.
	 * @param bool $mayAct Whether the caller may act on the case.
	 *
	 * @return array<string, mixed> The overview the caller is answered.
	 *
	 * @spec openspec/changes/a-handling-team-opens-its-unclaimed-cases/specs/case-access-control/spec.md#requirement-a-handling-team-member-opens-a-case-of-its-case-type-before-it-is-claimed-req-acc-11
	 */
	private function refusedUnlessHandler(array $overview, bool $mayAct): array {
		if ($mayAct === true) {
			return $overview;
		}

		$sentence = 'Claim this case first: only its handler acts on it.';
		$sentence = ($this->refusalTranslator()?->t($sentence) ?? $sentence);
		foreach (['acts', 'alwaysAvailable'] as $list) {
			if (is_array($overview[$list] ?? null) === false) {
				continue;
			}

			foreach ($overview[$list] as $index => $act) {
				if (is_array($act) === true && array_key_exists('allowed', $act) === true) {
					$overview[$list][$index]['allowed'] = false;
					$overview[$list][$index]['reason'] = $sentence;
				}
			}
		}

		return $overview;
	}//end refusedUnlessHandler()

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
			run: fn (): array => $this->acts->finish(
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
			run: fn (): array => $this->acts->abort(
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
	 * With `notifyRequester` the requester is told afterwards that the case is
	 * completed, and the answer carries whether they were, under `notice`.
	 * A refused archive tells nobody.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
	 */
	#[NoAdminRequired]
	public function archive(string $caseId): JSONResponse {
		$notify = in_array($this->request->getParam('notifyRequester', false), [true, 'true', '1', 1], true);

		return $this->guarded(
			caseId: $caseId,
			run: function () use ($caseId, $notify): array {
				$archived = $this->acts->archive(
					caseId: $caseId,
					reason: (string)$this->request->getParam('reason', ''),
				);
				if ($notify === true) {
					$archived['notice'] = $this->tellRequester(caseId: $caseId);
				}

				return $archived;
			},
		);
	}//end archive()

	/**
	 * Tell the requester that their archived case is completed.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, string> Whether the requester was told, and why not.
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
	 */
	private function tellRequester(string $caseId): array {
		if ($this->archiveNotice === null) {
			return [
				'noticeStatus' => 'not-sent',
				'noticeChannel' => '',
				'noticeReasonCode' => 'notice-not-wired',
				'noticeReason' => 'No sender is wired, so the requester was not told.',
			];
		}

		return $this->archiveNotice->tell(caseId: $caseId);
	}//end tellRequester()

	/**
	 * Take a case back out of the archive: one act, no wizard.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	#[NoAdminRequired]
	public function unarchive(string $caseId): JSONResponse {
		return $this->guarded(
			caseId: $caseId,
			run: fn (): array => $this->acts->unarchive(
				caseId: $caseId,
				reason: (string)$this->request->getParam('reason', ''),
			),
		);
	}//end unarchive()

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
			run: fn (): array => $this->acts->hold(
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
			run: fn (): array => $this->acts->releaseHold(
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
			run: fn (): array => $this->acts->draft(caseId: $caseId),
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
			run: fn (): array => $this->acts->promote(caseId: $caseId),
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
			run: fn (): array => $this->acts->recordIncompleteness(caseId: $caseId, fields: $fields),
		);
	}//end incompleteness()

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

		return $this->answered(caseId: $caseId, run: $run);
	}//end guarded()

	/**
	 * Answer what a call computes, turning a refusal or a failure into its response.
	 *
	 * Callers run their own guard first.
	 *
	 * @param string $caseId The case UUID.
	 * @param callable(): array<string, mixed> $run The call.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	private function answered(string $caseId, callable $run): JSONResponse {
		try {
			return new JSONResponse($run());
		} catch (RefusedException $e) {
			return $this->refused(op: 'case act', e: $e);
		} catch (Throwable $e) {
			$this->logger->error(
				'CaseActsController: the act failed',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);

			$sentence = 'The case could not be changed.';

			return new JSONResponse(
				['message' => ($this->refusalTranslator()?->t($sentence) ?? $sentence), 'error' => 'act-failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}//end try
	}//end answered()
}//end class
