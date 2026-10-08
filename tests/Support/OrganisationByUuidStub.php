<?php

/**
 * The one method dossiq calls on OpenRegister's OrganisationMapper.
 *
 * `findByUuid()` throws when there is no such row; it never returns null.
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

interface OrganisationByUuidStub {
	/**
	 * Find one Organisation by uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return Organisation The organisation.
	 */
	public function findByUuid(string $uuid): Organisation;
}
