<?php

/**
 * The OpenRegister object service with the register and federated-share schema a share snapshot lives in.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Sharing
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

namespace OCA\Dossiq\Service\Sharing;

/**
 * The OpenRegister object service with the register and federated-share schema a share snapshot lives in.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class FederatedShareStore {
	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $register The configured register id
	 * @param string $shareSchema The configured federated-share schema id
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $shareSchema,
	) {
	}//end __construct()
}//end class
