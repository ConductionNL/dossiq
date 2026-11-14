<?php

/**
 * Contract of SatelliteOrphanScanner
 *
 * The public surface of SatelliteOrphanScanner that other components call, so they depend
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
 * Contract of SatelliteOrphanScanner.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SatelliteOrphanScannerInterface {

	/**
	 * Report every satellite row whose `tenantRef` resolves to nothing.
	 *
	 * 🔴 REPORTED, NEVER MAPPED. An orphan is a row pointing at a tenant that
	 * does not exist, and there is no safe guess about which organisation it
	 * meant. Attaching it to the nearest candidate would give one tenant
	 * another tenant's mandates or quotas, and every scoping filter downstream
	 * would then agree, because the row really would say so. So this method
	 * reads and counts. It writes nothing, and it has no repair mode.
	 *
	 * Safe to run before the migration, after it, or instead of it.
	 *
	 * @return array{scanned:int, orphans:int, templates:int,
	 *               bySchema:array<string, array{scanned:int, orphans:int, templates:int}>,
	 *               rows:array<int, array{schema:string, row:string, tenantRef:string}>}
	 *               What was scanned and what does not resolve.
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/tasks.md
	 */
	public function reportOrphans(): array;
}//end interface
