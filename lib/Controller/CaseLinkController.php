<?php

/**
 * Dossiq case link controller.
 *
 * The HTTP surface of the linked-case-with-a-role capability: which roles a
 * case can be linked with, linking it, and running an outcome cascade. Every
 * act is checked against the case it touches with `CaseAccessGuard`, which
 * fails closed; the services behind it never decide access.
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
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Relation\OutcomeCascade;
use OCA\Dossiq\Service\Relation\RoleLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use RuntimeException;

/**
 * Lists, makes and cascades role links on a case.
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */
class CaseLinkController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string          $appName     The app name.
	 * @param IRequest        $request     The request.
	 * @param RoleLinkService $links       Links a case with a role.
	 * @param OutcomeCascade  $cascades    Runs a configured cascade.
	 * @param CaseAccessGuard $accessGuard Per-case authorization, failing closed.
	 * @param IUserSession    $userSession The session.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RoleLinkService $links,
		private readonly OutcomeCascade $cascades,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The roles a case can be linked with, and which required ones are missing.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The roles, or the refusal.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-the-case-says-which-required-roles-are-missing-req-lcr-3
	 */
	#[NoAdminRequired]
	public function roles(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->accessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		return $this->answer(act: fn (): array => $this->links->rolesFor(caseId: $caseId));
	}//end roles()

	/**
	 * Link a case with a role to the target the request names.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $role   The role.
	 *
	 * @return JSONResponse What was written, or the refusal.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-linking-a-case-with-a-role-writes-the-relation-and-the-record-req-lcr-2
	 */
	#[NoAdminRequired]
	public function link(string $caseId, string $role): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->accessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		$targetId = trim((string)$this->request->getParam('target', ''));
		if ($targetId === '') {
			return new JSONResponse(['error' => 'Name what this case is linked to.'], Http::STATUS_BAD_REQUEST);
		}

		$payload = $this->request->getParam('record');
		if (is_array($payload) === false) {
			$payload = [];
		}

		return $this->answer(
			act: fn (): array => $this->links->link(caseId: $caseId, role: $role, targetId: $targetId, payload: $payload)
		);
	}//end link()

	/**
	 * Run a configured cascade on a target the caller may change the case of.
	 *
	 * @param string $name     The cascade.
	 * @param string $targetId The target uuid.
	 *
	 * @return JSONResponse The target after the cascade, or the refusal.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-an-outcome-cascade-runs-its-configured-steps-req-lcr-5
	 */
	#[NoAdminRequired]
	public function cascade(string $name, string $targetId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$inputs = $this->request->getParam('inputs');
		if (is_array($inputs) === false) {
			$inputs = [];
		}

		return $this->answer(
			act: function () use ($name, $targetId, $inputs, $user): array {
				$this->guardCascadeAccess(name: $name, targetId: $targetId, user: $user);
				return $this->cascades->run(name: $name, targetId: $targetId, inputs: $inputs);
			}
		);
	}//end cascade()

	/**
	 * Refuse a cascade on a target whose case the caller may not change.
	 *
	 * A target that names no case is refused too: with no case there is no
	 * one to ask, and an unchecked write is the thing this guard exists to stop.
	 *
	 * @param string $name     The cascade.
	 * @param string $targetId The target uuid.
	 * @param IUser  $user     The caller.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the caller may not.
	 */
	private function guardCascadeAccess(string $name, string $targetId, IUser $user): void {
		$caseId = $this->cascades->caseOf(name: $name, targetId: $targetId);
		if ($caseId === '' || $this->accessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			throw new RefusedException(
				rule: 'cascade-target-not-yours',
				sentence: 'Not authorized',
				status: RefusedException::STATUS_FORBIDDEN
			);
		}
	}//end guardCascadeAccess()

	/**
	 * Run an act and turn its refusals into HTTP answers.
	 *
	 * @param callable(): array<string, mixed> $act The act.
	 *
	 * @return JSONResponse The answer.
	 */
	private function answer(callable $act): JSONResponse {
		try {
			return new JSONResponse($act());
		} catch (RefusedException $e) {
			return new JSONResponse(['error' => $e->getSentence()], $e->getStatus());
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}//end answer()
}//end class
