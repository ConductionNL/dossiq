<?php

/**
 * Contract of DemoCaseloadSeedDataService
 *
 * The public surface of DemoCaseloadSeedDataService that other components call, so they depend
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
 * Contract of DemoCaseloadSeedDataService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DemoCaseloadSeedDataServiceInterface {

	/**
	 * Seed the demo caseload.
	 *
	 * Idempotent by case title: a case whose title is already present is left
	 * alone, and its tasks are left alone with it.
	 *
	 * 🔴 THROWS RATHER THAN RETURNING A QUIET FAILURE. The only caller is an
	 * operator who just asked for this, so "nothing happened" must never be
	 * presentable as success.
	 *
	 * @param DateTimeImmutable|null $now The clock, injectable for tests.
	 *
	 * @return array{cases: integer, tasks: integer, skipped: integer} What was created.
	 *
	 * @throws RuntimeException When the dataset, OpenRegister or the configuration is missing.
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	public function seed(?DateTimeImmutable $now = null): array;
}//end interface
