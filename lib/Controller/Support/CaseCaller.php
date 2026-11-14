<?php

/**
 * Dossiq case caller.
 *
 * The signed-in caller, when the case access guard lets them read or write
 * on a case, and the one refusal a case endpoint answers when it does not.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * Who may read or write on a case, asked of the case access guard.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class CaseCaller {

	/**
	 * Constructor.
	 *
	 * @param IUserSession    $userSession The session.
	 * @param CaseAccessGuardInterface $accessGuard The per-case guard.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuardInterface $accessGuard,
	) {
	}//end __construct()

	/**
	 * The caller, when they may read this case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return IUser|null The caller, or null without a session or read access.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function reader(string $caseId): ?IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		if ($this->accessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return null;
		}

		return $user;
	}//end reader()

	/**
	 * The caller, when they may write on this case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return IUser|null The caller, or null without a session or write access.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function writer(string $caseId): ?IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		if ($this->accessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return null;
		}

		return $user;
	}//end writer()

	/**
	 * One answer for "this case is not yours", so no endpoint differs.
	 *
	 * @return JSONResponse The refusal.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function notYours(): JSONResponse {
		return new JSONResponse(
			['message' => 'You cannot do that on this case.', 'error' => 'case-access-denied'],
			Http::STATUS_FORBIDDEN,
		);
	}//end notYours()
}//end class
