<?php

/**
 * OpenRegister DecisionTableLocation
 *
 * Where decision tables live: the register and schema DecisionTableStore reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

/**
 * The register and schema of the stored decision tables.
 */
final class DecisionTableLocation {

	/**
	 * The register slug.
	 *
	 * @var string
	 */
	public const REGISTER = 'decision-tables';

	/**
	 * The schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA = 'decision-table';
}//end class
