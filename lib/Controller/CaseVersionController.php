<?php

/**
 * Dossiq Case Version Controller.
 *
 * The version chain of a case type, and moving one running case along it.
 *
 * Separate from `CaseTypeController`, which owns the effective blueprint and
 * the validate-then-publish gesture, and from `CaseDefinitionController`, which
 * owns the portable package. These endpoints are keyed on the CHAIN rather than
 * on a single case type, and the two that matter are keyed on a CASE, which is
 * a different guard: publishing is an administrator's act on a catalogue, and
 * moving a case is a handler's act on work they are answerable for.
 *
 * 🔴 THE MOVE CHECKS THE CASE, NOT A ROLE. `#[NoAdminRequired]` with no
 * per-object guard is an IDOR, and moving somebody else's case onto another
 * version of its type rewrites the statuses and the fields it is governed by.
 * {@see CaseAccessGuard} answers per case and fails closed (ADR-005 Rule 3).
 * The chain read is open to any authenticated user for the reason the blueprint
 * read is: it is the catalogue a new-case form already shows.
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
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseType\CaseTypeVersionChain;
use OCA\Dossiq\Service\CaseType\CaseTypeVersionWindow;
use OCA\Dossiq\Service\CaseType\CaseVersionMove;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a case type's version chain, and moves a case along it.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */
class CaseVersionController extends Controller {

	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param string                 $appName        The app name.
	 * @param IRequest               $request        The request.
	 * @param CaseTypeVersionChain   $chain          The versions of one case type.
	 * @param CaseVersionMove        $move           What a move would change, and the move.
	 * @param CaseTypeVersionWindow  $window         When a version starts and stops being offered.
	 * @param CaseAccessGuard        $accessGuard    Per-case authorization, failing closed.
	 * @param IUserSession           $userSession    The session.
	 * @param LoggerInterface        $logger         The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseTypeVersionChain $chain,
		private readonly CaseVersionMove $move,
		private readonly CaseTypeVersionWindow $window,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Every version of this case type, newest first.
	 *
	 * Answers the chain as a list rather than a walk, so a version with a hole
	 * in its `previousVersion` links still appears: a version missing from the
	 * answer reads as a version that does not exist.
	 *
	 * `#[NoAdminRequired]` and no body guard, deliberately, and for the same
	 * reason `CaseTypeController::blueprint()` carries neither: this READS the
	 * case type catalogue, which the case page and the new-case form already
	 * show to ordinary users, and OpenRegister applies its own read authority
	 * underneath. Nothing here writes.
	 *
	 * @param string $id The case type id, any version of the chain.
	 *
	 * @return JSONResponse The versions, or 404 when the type is unreadable.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md#requirement-the-case-type-page-shows-its-version-chain-req-zv-04
	 */
	#[NoAdminRequired]
	public function chain(string $id): JSONResponse {
		$versions = $this->chain->versionsOf(caseTypeId: $id);
		if ($versions === []) {
			return new JSONResponse(['error' => 'Case type not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['versions' => $versions]);
	}//end chain()

	/**
	 * Close a superseded version for new cases.
	 *
	 * Admin-only, like publishing, and for the same reason: this writes the
	 * catalogue that governs every case of the type, and the authority is the
	 * ATTRIBUTE rather than a guard in the body, so Nextcloud's middleware
	 * enforces it before the method runs.
	 *
	 * @param string $id The case type version to close.
	 *
	 * @return JSONResponse The outcome, or 422 with what stood in the way.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md#requirement-new-version-and-deprecate-are-actions-on-the-page-req-zv-05
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function deprecate(string $id): JSONResponse {
		try {
			$result = $this->window->deprecate(caseTypeId: $id);
		} catch (Throwable $e) {
			return $this->broke(op: 'deprecate', e: $e);
		}

		if ($result['deprecated'] === false) {
			return new JSONResponse(
				['message' => ($result['findings'][0] ?? 'This version was not closed.')] + $result,
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		}

		return new JSONResponse($result);
	}//end deprecate()

	/**
	 * The versions this case could move to, and what one of them would change.
	 *
	 * One endpoint for the picker and the preview, because the dialog asks both
	 * questions about the same case and a second round trip for the second half
	 * would let the two answers come from different moments. `target` is
	 * optional: without it the answer is the list, with it the preview rides
	 * alongside.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The options, with the preview when a target is named.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md#requirement-a-running-case-moves-to-another-version-only-as-a-named-act-req-zv-09
	 */
	#[NoAdminRequired]
	public function options(string $caseId): JSONResponse {
		if ($this->writerOf(caseId: $caseId) === null) {
			return $this->notYours();
		}

		try {
			$answer = $this->move->options(caseId: $caseId);

			$target = trim((string)$this->request->getParam('target', ''));
			if ($target !== '') {
				$answer['preview'] = $this->move->preview(caseId: $caseId, targetCaseTypeId: $target);
			}

			return new JSONResponse($answer);
		} catch (RefusedException $e) {
			return $this->refused(op: 'version-options', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'version-options', e: $e);
		}
	}//end options()

	/**
	 * Move this case onto another version of its own case type.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse What was applied, or the refusal naming the status.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md#requirement-a-running-case-moves-to-another-version-only-as-a-named-act-req-zv-09
	 */
	#[NoAdminRequired]
	public function moveToVersion(string $caseId): JSONResponse {
		$user = $this->writerOf(caseId: $caseId);
		if ($user === null) {
			return $this->notYours();
		}

		try {
			return new JSONResponse(
				$this->move->move(
					caseId: $caseId,
					targetCaseTypeId: trim((string)$this->request->getParam('target', '')),
					reason: (string)$this->request->getParam('reason', ''),
					actorUid: $user->getUID(),
				)
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'version-move', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'version-move', e: $e);
		}
	}//end moveToVersion()

	/**
	 * The caller, when they may write this case, and null otherwise.
	 *
	 * Answering the USER rather than a boolean, because every caller that needs
	 * the check also needs the uid, and a guard that hands back a boolean
	 * invites a second `getUser()` beside it that nothing re-checks.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return IUser|null The caller, or null when they may not.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
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
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	private function notYours(): JSONResponse {
		return new JSONResponse(
			['message' => 'You cannot move this case.', 'error' => 'case-access-denied'],
			Http::STATUS_FORBIDDEN,
		);
	}//end notYours()

	/**
	 * A failure that is not a refusal, logged and answered as one.
	 *
	 * @param string    $op The endpoint, for the log line.
	 * @param Throwable $e  The failure.
	 *
	 * @return JSONResponse The answer.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	private function broke(string $op, Throwable $e): JSONResponse {
		$this->logger->error('CaseVersionController: ' . $op . ' failed', ['exception' => $e->getMessage()]);

		return new JSONResponse(
			['message' => 'The case was not moved.', 'error' => 'version-move-failed'],
			Http::STATUS_INTERNAL_SERVER_ERROR,
		);
	}//end broke()
}//end class
