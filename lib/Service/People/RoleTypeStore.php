<?php

/**
 * OpenRegister's object service with the dossiq register and the role type schema the generic role type is seeded in.
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
 * OpenRegister's object service with the dossiq register and the role type schema the generic role type is seeded in.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class RoleTypeStore {
	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service
	 * @param string $register The dossiq register
	 * @param string $schema The role type schema
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
