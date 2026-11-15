<?php

/**
 * One run of the legacy tenant migration
 *
 * The OrganisationMapper, whether the run writes, and the summary it
 * builds up: the state TenantMigrationService carries through one run, so
 * its steps take one argument for it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\TenantMigration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\TenantMigration;

/**
 * The state of one migration run; it never leaves the service that built it.
 *
 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
 */
final class TenantMigrationRun {

	/**
	 * OR OrganisationMapper, set once the run has found one.
	 *
	 * @var object
	 */
	public object $mapper;

	/**
	 * When true, the run decides and writes nothing.
	 *
	 * @var boolean
	 */
	public bool $dryRun = false;

	/**
	 * The summary the run returns.
	 *
	 * @var array<string, mixed>
	 */
	public array $summary = [];
}//end class
