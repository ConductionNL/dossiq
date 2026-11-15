<?php

/**
 * Contract of TenantMigrationService
 *
 * The public surface of TenantMigrationService that other components call, so they depend
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
 * Contract of TenantMigrationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TenantMigrationServiceInterface {

	/**
	 * The migration writes.
	 */
	public const MODE_APPLY = 'apply';

	/**
	 * The migration decides and reports, and writes nothing.
	 */
	public const MODE_PREVIEW = 'preview';

	/**
	 * Run the migration.
	 *
	 * Reads all legacy `tenant` objects and inserts one OR Organisation per
	 * tenant whose slug is not already present.
	 *
	 * A dry run reads and decides exactly as a real run does, and writes
	 * nothing: no Organisation is inserted or updated. Its summary is the one
	 * a real run would print, so a person can read the collisions and orphans
	 * before anything moves (REQ-TOO-001).
	 *
	 * `unmigrated` counts the stored tenants that have no Organisation of the
	 * same uuid once the run is over: refused collisions and failed rows
	 * included, and in a dry run every tenant that would have been created.
	 * The repair step reports the same number, so the release that deletes
	 * this class can wait for it to read zero (decision Q1).
	 *
	 * @param string $mode MODE_APPLY writes, MODE_PREVIEW writes nothing. A mode, not a
	 *                     flag, so the walk stays one method and the two cannot drift apart.
	 *
	 * @return array{migrated:int, repaired:int, skipped:int, refused:int, failed:int, total:int,
	 *               unmigrated:int, dryRun:bool,
	 *               mappings:array<int,array{tenant:string, organisation:string}>,
	 *               collisions:array<int,array{tenant:string, slug:string, heldBy:string}>}
	 *
	 * @spec openspec/changes/archive/2026-06-14-migrate-tenant-to-or-tenant/tasks.md
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function migrate(string $mode = self::MODE_APPLY): array;
}//end interface
