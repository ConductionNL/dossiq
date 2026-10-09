<?php

/**
 * Dossiq Tenant Service
 *
 * What is left of dossiq's tenant service once its tenant API moved to
 * OpenRegister (Q5, Ruben 2026-10-08): the platform admin check that
 * `MandateValidationMiddleware` makes before it refuses an organisation that
 * is not active.
 *
 * The rest went with the tenant controller. Reading the active organisation is
 * `GET /api/organisations/active`, listing a user's organisations is
 * `GET /api/organisations`, provisioning is
 * `PUT /api/organisations/{uuid}/activate`, and usage is
 * `GET /api/organisations/{uuid}/usage` with `GET /api/organisations/{uuid}`,
 * all on OpenRegister.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\IGroupManager;

/**
 * The platform admin check for the tenant boundary.
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md
 */
class TenantService {
	/**
	 * Constructor for the TenantService.
	 *
	 * @param IGroupManager $groupManager The Nextcloud group manager.
	 */
	public function __construct(
		private IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Check whether a user is a platform administrator.
	 *
	 * @param string $userId The Nextcloud user ID.
	 *
	 * @return bool True when the user is in the NC admin group.
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function isPlatformAdmin(string $userId): bool {
		return $this->groupManager->isAdmin($userId);
	}//end isPlatformAdmin()
}//end class
