<?php

/**
 * Which person a link puts on which case, in which role type.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\People
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

/**
 * Which person a link puts on which case, in which role type.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class PersonRoleOnCase {
	/**
	 * Constructor.
	 *
	 * @param string $caseId The case uuid
	 * @param string $personUid The person's uid
	 * @param string $roleTypeId The role type uuid, or ''
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $personUid,
		public readonly string $roleTypeId,
	) {
	}//end __construct()
}//end class
