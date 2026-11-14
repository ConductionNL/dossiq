<?php

/**
 * One target of the Woo summary rewrite: OpenRegister's object service with the register, schema and fields to walk.
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
 * One target of the Woo summary rewrite: OpenRegister's object service with the register, schema and fields to walk.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class SummaryTarget {
	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service
	 * @param array{register $target  string, schema: string, fields: array<int, string>}:The register, schema and fields
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly array $target,
	) {
	}//end __construct()
}//end class
