<?php

/**
 * The object service with the register and the share and activity schemas a federated activity stream is kept in.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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

namespace OCA\Dossiq\Service;

/**
 * The object service with the register and the share and activity schemas a federated activity stream is kept in.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class FederatedActivityStore {
	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $register The register id
	 * @param string $shareSchema The caseFederatedShare schema id
	 * @param string $activitySchema The caseFederatedActivity schema id
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $shareSchema,
		public readonly string $activitySchema,
	) {
	}//end __construct()
}//end class
