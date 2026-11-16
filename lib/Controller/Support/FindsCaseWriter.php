<?php

/**
 * Dossiq FindsCaseWriter support trait.
 *
 * The caller lookup of the controllers that move a case (handover, version
 * move, rebind). The using class provides `$userSession` and `$accessGuard`
 * ({@see \OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface}).
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
 * @spec exclude shared caller lookup of the case-moving controllers, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\IUser;

/**
 * The caller when they may write the case.
 *
 * @spec exclude shared caller lookup of the case-moving controllers, covered by their own specs
 */
trait FindsCaseWriter {

	/**
	 * The caller, when they may write this case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return IUser|null The caller, or null when they may not.
	 */
	private function writerOf(string $caseId): ?IUser {
		return (new CaseCaller(userSession: $this->userSession, accessGuard: $this->accessGuard))->writer(caseId: $caseId);
	}//end writerOf()
}//end trait
