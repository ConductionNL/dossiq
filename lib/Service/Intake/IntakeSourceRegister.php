<?php

/**
 * OpenRegister's object service and the register the intake sources are seeded in.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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

namespace OCA\Dossiq\Service\Intake;

/**
 * OpenRegister's object service and the register the intake sources are seeded in.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class IntakeSourceRegister {
	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's ObjectService
	 * @param string $register The intake-sources register id
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
	) {
	}//end __construct()
}//end class
