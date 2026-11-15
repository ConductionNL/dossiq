<?php

/**
 * The OpenRegister object service with the ZGW mapping whose stored objects a lookup searches.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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

namespace OCA\Dossiq\Service\Zgw;

/**
 * The OpenRegister object service with the ZGW mapping whose stored objects a lookup searches.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class MappedObjects {
	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array<string, mixed> $mapping The ZGW mapping (sourceRegister, sourceSchema)
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly array $mapping,
	) {
	}//end __construct()
}//end class
