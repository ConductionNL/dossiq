<?php

/**
 * Contract of TenantService
 *
 * The public surface of TenantService that other components call, so they depend
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
 * Contract of TenantService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TenantServiceInterface {

	/**
	 * Make sure the Organisation has its tenant audit anchor, creating it once.
	 *
	 * Every tenant audit entry is a row on OpenRegister's audit trail of a
	 * tenant object (decision Q4). A tenant created in OpenRegister after the
	 * migration has no such object, so dossiq creates one when its onboarding
	 * starts (decision Q6): the Organisation's uuid, slug and name, and the
	 * moment the Organisation was created. An anchor that exists is left
	 * exactly as it is. Nothing here updates or deletes one.
	 *
	 * The read and the write skip RBAC and multitenancy on purpose. The anchor
	 * is a system record: it must be found whoever's request writes the audit
	 * entry, and it must not take the organisation of the admin who happened to
	 * start the onboarding. The only caller is the admin-only onboarding route.
	 *
	 * @param string $organisationUuid The Organisation's uuid, which is the tenant uuid.
	 *
	 * @return bool True when the anchor exists afterwards.
	 *
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function ensureAuditAnchor(string $organisationUuid): bool;

	/**
	 * Check whether a user is a platform administrator.
	 *
	 * @param string $userId The Nextcloud user ID.
	 *
	 * @return bool True when the user is in the NC admin group.
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md
	 */
	public function isPlatformAdmin(string $userId): bool;
}//end interface
