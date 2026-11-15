<?php

/**
 * OpenRegister's object service and the dossiq register a Woo request's case is written in.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
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

namespace OCA\Dossiq\Woo;

/**
 * OpenRegister's object service and the dossiq register a Woo request's case is written in.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class IntakeRegister {
	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $register The dossiq register
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
	) {
	}//end __construct()
}//end class
