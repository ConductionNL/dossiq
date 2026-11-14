<?php

/**
 * Contract of DemoCaseloadReport
 *
 * The public surface of DemoCaseloadReport that other components call, so they depend
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

use DateTimeImmutable;
use RuntimeException;

/**
 * Contract of DemoCaseloadReport.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DemoCaseloadReportInterface {

	/**
	 * Count the buckets the dashboard widgets read.
	 *
	 * @param DateTimeImmutable|null $now The clock, injectable for tests.
	 *
	 * @return array{open: integer, overdue: integer, dueSoon: integer, closed: integer, tasksOpen: integer, tasksDue: integer}
	 *         The bucket counts.
	 *
	 * @throws RuntimeException When OpenRegister or the configuration is missing.
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	public function buckets(?DateTimeImmutable $now = null): array;
}//end interface
