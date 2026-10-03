<?php

/**
 * Dossiq Case Rebind Controller.
 *
 * Changing the case TYPE of a running case, which is a different act from
 * moving it along its own version chain: {@see CaseVersionController} owns
 * that, and the difference is that a version move can derive its landing
 * status by name while a rebind cannot derive anything at all.
 *
 * 🔴 `#[NoAdminRequired]` WITH THE GUARD IN THE SERVICE, NOT IN THE UI. The
 * manifest hides the action from anybody outside `dossiq-coordinators`, and a
 * hidden button is not an authorization: this endpoint answers curl. So
 * {@see \OCA\Dossiq\Service\CaseRebindService::rebind()} checks the same group
 * itself and refuses 403 naming the rule, and this controller adds the
 * per-case check on top, because being a coordinator is not the same as being
 * allowed near this particular case (ADR-005 Rule 3, gate 12).
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
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseRebindService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads what a rebind would cost, and performs it.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */
class CaseRebindController extends Controller {

	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param string            $appName     The app name.
	 * @param IRequest          $request     The request.
	 * @param CaseRebindService $rebind      The act itself.
	 * @param CaseAccessGuard   $accessGuard Per-case authorization, failing closed.
	 * @param IUserSession      $userSession The session.
	 * @param LoggerInterface   $logger      The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseRebindService $rebind,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Whether the caller may rebind a case at all.
	 *
	 * Its own endpoint because a manifest `visibleWhen` predicate fetches a URL
	 * and compares one field, and it carries no case id: the question it asks is
	 * about the CALLER, which is exactly the group check D-3 describes. The
	 * per-case half is asked by the endpoints that write.
	 *
	 * @return JSONResponse Whether they may.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md#requirement-a-coordinator-may-rebind-a-running-case-with-a-mapping-and-a-reason-req-zv-07
	 */
	#[NoAdminRequired]
	public function permission(): JSONResponse {
		$user = $this->userSession->getUser();

		$uid = '';
		if ($user !== null) {
			$uid = $user->getUID();
		}

		return new JSONResponse(
			['mayRebind' => $this->rebind->mayRebind(uid: $uid)]
		);
	}//end permission()

	/**
	 * The case types this case could be rebound to, and what one would ask for.
	 *
	 * One endpoint for the picker and the preview, for the reason
	 * {@see CaseVersionController::options()} keeps one: the dialog asks both
	 * questions about the same case, and a second round trip would let the two
	 * answers come from different moments.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The options, with the preview when a target is named.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md#requirement-a-coordinator-may-rebind-a-running-case-with-a-mapping-and-a-reason-req-zv-07
	 */
	#[NoAdminRequired]
	public function options(string $caseId): JSONResponse {
		$user = $this->writerOf(caseId: $caseId);
		if ($user === null) {
			return $this->notYours();
		}

		if ($this->rebind->mayRebind(uid: $user->getUID()) === false) {
			return $this->notACoordinator();
		}

		try {
			$answer = $this->rebind->options(caseId: $caseId);

			$target = trim((string)$this->request->getParam('target', ''));
			if ($target !== '') {
				$answer['preview'] = $this->rebind->preview(
					caseId: $caseId,
					targetCaseTypeId: $target,
					targetStatusId: trim((string)$this->request->getParam('status', '')),
				);
			}

			return new JSONResponse($answer);
		} catch (RefusedException $e) {
			return $this->refused(op: 'rebind-options', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'rebind-options', e: $e);
		}
	}//end options()

	/**
	 * Rebind this case onto another case type.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse What was applied, or the refusal naming the rule.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md#requirement-a-coordinator-may-rebind-a-running-case-with-a-mapping-and-a-reason-req-zv-07
	 */
	#[NoAdminRequired]
	public function rebind(string $caseId): JSONResponse {
		$user = $this->writerOf(caseId: $caseId);
		if ($user === null) {
			return $this->notYours();
		}

		$properties = $this->request->getParam('properties', []);
		if (is_array($properties) === false) {
			$properties = [];
		}

		try {
			return new JSONResponse(
				$this->rebind->rebind(
					caseId: $caseId,
					targetCaseTypeId: trim((string)$this->request->getParam('target', '')),
					targetStatusId: trim((string)$this->request->getParam('status', '')),
					reason: (string)$this->request->getParam('reason', ''),
					properties: $properties,
					actorUid: $user->getUID(),
				)
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'rebind', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'rebind', e: $e);
		}
	}//end rebind()

	/**
	 * The caller, when they may write this case, and null otherwise.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return IUser|null The caller, or null when they may not.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	private function writerOf(string $caseId): ?IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		if ($this->accessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return null;
		}

		return $user;
	}//end writerOf()

	/**
	 * The answer a caller who may not touch this case gets.
	 *
	 * @return JSONResponse The 403.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	private function notYours(): JSONResponse {
		return new JSONResponse(
			['message' => 'You cannot change the type of this case.', 'error' => 'case-access-denied'],
			Http::STATUS_FORBIDDEN,
		);
	}//end notYours()

	/**
	 * The answer a handler outside the coordinators gets.
	 *
	 * Named separately from {@see notYours()} because they are different facts:
	 * one says this case is not yours, the other says this ACT is not yours on
	 * any case, and a handler told the first would go looking for a case they
	 * do own and be refused again.
	 *
	 * @return JSONResponse The 403.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md#requirement-a-coordinator-may-rebind-a-running-case-with-a-mapping-and-a-reason-req-zv-07
	 */
	private function notACoordinator(): JSONResponse {
		return new JSONResponse(
			[
				'message' => 'Only a case coordinator may change the type of a running case.',
				'error' => 'rebind-is-for-coordinators',
			],
			Http::STATUS_FORBIDDEN,
		);
	}//end notACoordinator()

	/**
	 * A failure that is not a refusal, logged and answered as one.
	 *
	 * @param string    $op The endpoint, for the log line.
	 * @param Throwable $e  The failure.
	 *
	 * @return JSONResponse The answer.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	private function broke(string $op, Throwable $e): JSONResponse {
		$this->logger->error('CaseRebindController: ' . $op . ' failed', ['exception' => $e->getMessage()]);

		return new JSONResponse(
			['message' => 'The case was not rebound.', 'error' => 'rebind-failed'],
			Http::STATUS_INTERNAL_SERVER_ERROR,
		);
	}//end broke()
}//end class
