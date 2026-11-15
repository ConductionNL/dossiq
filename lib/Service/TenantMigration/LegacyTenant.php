<?php

/**
 * One legacy procest tenant row on its way to an OR Organisation
 *
 * The row as read, the uuid and slug read from it, and the Organisation
 * the migration found or made for it.
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
 * One legacy tenant row; it never leaves the service that built it.
 *
 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
 */
final class LegacyTenant {

	/**
	 * The legacy tenant object as read.
	 *
	 * @var array<string, mixed>
	 */
	public array $row = [];

	/**
	 * The tenant uuid, the migration's idempotency key.
	 *
	 * @var string
	 */
	public string $uuid = '';

	/**
	 * The tenant slug.
	 *
	 * @var string
	 */
	public string $slug = '';

	/**
	 * The Organisation found by uuid, built, or saved for this tenant.
	 *
	 * @var object
	 */
	public object $organisation;
}//end class
