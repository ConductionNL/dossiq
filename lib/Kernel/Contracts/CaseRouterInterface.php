<?php

/**
 * Contract of CaseRouter
 *
 * The public surface of CaseRouter that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use OCA\Dossiq\Exception\RefusedException;
use RuntimeException;

/**
 * Contract of CaseRouter.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseRouterInterface {

	/**
	 * Route a case by a rule.
	 *
	 * @param string               $caseId The case id.
	 * @param array<string, mixed> $rule   The routing rule.
	 *
	 * @return array{caseId: string, assignee: string, team: string, areaFallbackUsed: bool, reason: string, takeBack: string}
	 *
	 * @throws RuntimeException When OpenRegister is absent or the case does not exist.
	 * @throws RefusedException When the rule names a strategy nobody registered (422).
	 *
	 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
	 */
	public function route(string $caseId, array $rule): array;

	/**
	 * Hand a case back to its pool when its take-back window breached.
	 *
	 * @param string $caseId   The case id.
	 * @param string $routedTo The person the window was armed for.
	 * @param string $routedAt The routing moment the window was armed for (ATOM).
	 *
	 * @return string TAKEN_BACK, KEPT or NOT_DUE.
	 *
	 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
	 */
	public function takeBack(string $caseId, string $routedTo, string $routedAt): string;
}//end interface
