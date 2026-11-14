<?php

/**
 * Contract of HandlerActsAsSystem
 *
 * The public surface of HandlerActsAsSystem that other components call, so they depend
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
use OCP\IUser;

/**
 * Contract of HandlerActsAsSystem.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface HandlerActsAsSystemInterface {

	/**
	 * Check the caller and the step, then run the act as the system.
	 *
	 * @param string             $caseId    The case UUID.
	 * @param IUser              $user      The caller.
	 * @param string             $stepField The case field that says the act's step is reached.
	 * @param array<int, string> $stepIn    The values of that field the act is allowed at.
	 * @param callable           $operation The act.
	 *
	 * @return mixed What the act returns.
	 *
	 * @throws RefusedException When the caller is not the handler (403) or the case
	 *                          is not at the act's step (422).
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function run(string $caseId, IUser $user, string $stepField, array $stepIn, callable $operation): mixed;
}//end interface
