<?php

/**
 * The one method dossiq calls on OpenRegister's OrganisationService.
 *
 * Read on ConductionNL/openregister development, lib/Service/OrganisationService.php:
 * `getActiveOrganisation(?array $preloadedOrgs = null): ?Organisation`. It
 * answers null for a user with no active organisation. Declared here because
 * the real service takes a database, a session and a cache.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\OpenRegister\Db\Organisation;

interface ActiveOrganisationServiceStub {
	/**
	 * The current user's active organisation.
	 *
	 * @param array<int, Organisation>|null $preloadedOrgs Organisations already loaded.
	 *
	 * @return Organisation|null The organisation, or null.
	 */
	public function getActiveOrganisation(?array $preloadedOrgs = null): ?Organisation;
}
