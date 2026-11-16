<?php

/**
 * The 401 or 403 a case endpoint answers before it acts.
 *
 * A caller without a session is not authenticated; a caller without read
 * (or change) access to the case is not authorized. Several case endpoints
 * answered both the same way, in the same words.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Refuses a caller who is not signed in or may not read or change the case.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class CaseAccessRefusal {

	/**
	 * Constructor.
	 *
	 * @param IUserSession             $userSession The session.
	 * @param CaseAccessGuardInterface $guard       Decides case access.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuardInterface $guard,
	) {
	}//end __construct()

	/**
	 * The refusal for a caller who may not read the case, else null.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function requireReadAccess(string $caseId): ?JSONResponse {
		return $this->refusalOf(caller: $this->reader(caseId: $caseId));
	}//end requireReadAccess()

	/**
	 * The refusal for a caller who may not change the case, else null.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function requireChangeAccess(string $caseId): ?JSONResponse {
		return $this->refusalOf(caller: $this->writer(caseId: $caseId));
	}//end requireChangeAccess()

	/**
	 * The signed-in caller when they may read the case, else the 401 or 403.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return IUser|JSONResponse
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function reader(string $caseId): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notAuthenticated();
		}

		if ($this->guard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return $this->notAuthorized();
		}

		return $user;
	}//end reader()

	/**
	 * The signed-in caller when they may change the case, else the 401 or 403.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return IUser|JSONResponse
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function writer(string $caseId): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notAuthenticated();
		}

		if ($this->guard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return $this->notAuthorized();
		}

		return $user;
	}//end writer()

	/**
	 * The refusal a caller lookup ended in, or null for a caller.
	 *
	 * @param IUser|JSONResponse $caller The caller or the refusal.
	 *
	 * @return JSONResponse|null
	 */
	private function refusalOf(IUser|JSONResponse $caller): ?JSONResponse {
		if ($caller instanceof JSONResponse) {
			return $caller;
		}

		return null;
	}//end refusalOf()

	/**
	 * The 401.
	 *
	 * @return JSONResponse
	 */
	private function notAuthenticated(): JSONResponse {
		return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
	}//end notAuthenticated()

	/**
	 * The 403.
	 *
	 * @return JSONResponse
	 */
	private function notAuthorized(): JSONResponse {
		return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
	}//end notAuthorized()
}//end class
