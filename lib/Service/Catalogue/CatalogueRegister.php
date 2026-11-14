<?php

/**
 * OpenRegister's object service and the register the object type catalogue reads.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Catalogue
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

namespace OCA\Dossiq\Service\Catalogue;

/**
 * OpenRegister's object service and the register the object type catalogue reads.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class CatalogueRegister {
	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's ObjectService
	 * @param string $register The register id
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
	) {
	}//end __construct()
}//end class
