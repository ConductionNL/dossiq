<?php

/**
 * OpenRegister's object service with the register and case schema the running cases are read from and patched in.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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

namespace OCA\Dossiq\Listener;

/**
 * OpenRegister's object service with the register and case schema the running cases are read from and patched in.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class RunningCaseStore {
	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service
	 * @param string $register The register
	 * @param string $schema The case schema
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
