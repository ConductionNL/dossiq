<?php

/**
 * Contract of FirstResponseOutcome
 *
 * The public surface of FirstResponseOutcome that other components call, so they depend
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
 * Contract of FirstResponseOutcome.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FirstResponseOutcomeInterface {

	/**
	 * How many first responses were missed, and by how much on average.
	 *
	 * The average is computed over the STORED overruns rather than recomputed
	 * from dates, because the dates behind a year-old case may have moved and
	 * the number recorded at the time is the one that was true.
	 *
	 * @param array<string, mixed> $filters Case filters, such as a case type or an organisation.
	 *
	 * @return array{missed: int, met: int, averageOverrunDays: float} The report.
	 *
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md#requirement-a-case-type-declares-a-first-response-term-and-the-overrun-is-stored-req-tcf-01
	 */
	public function report(array $filters = []): array;
}//end interface
