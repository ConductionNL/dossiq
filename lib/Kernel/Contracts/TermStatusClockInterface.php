<?php

/**
 * Contract of TermStatusClock
 *
 * The public surface of TermStatusClock that other components call, so they depend
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

/**
 * Contract of TermStatusClock.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TermStatusClockInterface {

	/**
	 * Make the timer match the status the case is now in.
	 *
	 * @param string $caseId   The case.
	 * @param string $statusId The status it is now in.
	 * @param string $caseType The case's type, which is what carries the term.
	 *
	 * @return string One of UNCHANGED, SUSPENDED or RESUMED.
	 *
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md#requirement-a-term-declares-the-statuses-its-clock-runs-in-with-thresholds-as-days-or-shares-req-tcf-03
	 */
	public function reconcile(string $caseId, string $statusId, string $caseType): string;
}//end interface
