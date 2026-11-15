<?php

/**
 * The object service with the register and case schema a bezwaar case is read from and saved in.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
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

namespace OCA\Dossiq\Service\Bezwaar;

/**
 * The object service with the register and case schema a bezwaar case is read from and saved in.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class BezwaarCaseStore {
	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $register Register id
	 * @param string $caseSchema Case schema id
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $caseSchema,
	) {
	}//end __construct()
}//end class
