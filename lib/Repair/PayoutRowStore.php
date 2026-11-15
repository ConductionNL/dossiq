<?php

/**
 * The OpenRegister object service with the register and payout schema the payout rows are read from and patched in.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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

namespace OCA\Dossiq\Repair;

/**
 * The OpenRegister object service with the register and payout schema the payout rows are read from and patched in.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class PayoutRowStore {
	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister object service
	 * @param string $register The register
	 * @param string $schema The payout schema
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
