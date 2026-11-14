<?php

/**
 * Contract of CaseStageDatesService
 *
 * The public surface of CaseStageDatesService that other components call, so they depend
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
 * Contract of CaseStageDatesService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseStageDatesServiceInterface {

	/**
	 * The first day each status was reached on one case.
	 *
	 * Empty when the case has no moves yet or no store is configured. A read
	 * that FAILS throws, and the caller decides what a stage bar does without
	 * dates: this service does not turn a failure into an empty answer.
	 *
	 * @param string                           $caseId      The case id.
	 * @param array<int, array<string, mixed>> $statusTypes The case type's steps, to name the first one.
	 *
	 * @return array<string, string> Y-m-d, keyed by statusType id.
	 *
	 * @throws \Throwable When the status moves cannot be read.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function reachedDatesFor(string $caseId, array $statusTypes = []): array;
}//end interface
