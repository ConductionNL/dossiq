<?php

/**
 * Contract of PackageAdminTabs
 *
 * The public surface of PackageAdminTabs that other components call, so they depend
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
 * Contract of PackageAdminTabs.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PackageAdminTabsInterface {

	/**
	 * Every installed package with an admin tab, in package id order.
	 *
	 * Each entry carries the package id, its installed version, the tab's
	 * title and, per declared list, the items the package installed: their
	 * id, and the title and status the row holds here when this app stores it.
	 *
	 * @return array<int, array{id: string, version: string, title: string,
	 *         lists: array<string, array<int, array{id: string, title: string, status: string}>>}>
	 *         The tabs; none when the ledger cannot be read.
	 *
	 * @spec openspec/changes/vth-cases-run-end-to-end
	 */
	public function installed(): array;
}//end interface
