<?php

/**
 * The OpenRegister object service with the register and informatieobject schema documents are saved in.
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
 * The OpenRegister object service with the register and informatieobject schema documents are saved in.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class InformatieobjectStore {
	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister object service
	 * @param string $register The register id
	 * @param string $infoSchema The informatieobject schema id
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $infoSchema,
	) {
	}//end __construct()
}//end class
