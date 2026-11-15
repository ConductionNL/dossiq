<?php

/**
 * Dossiq CaseLeafRegister.
 *
 * Where the case leaf reads: OpenRegister's object service and the
 * configured register, known together once the surface is configured.
 *
 * @category Integration
 * @package  OCA\Dossiq\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Integration;

/**
 * The object service and register the case leaf reads from.
 *
 * @spec openspec/specs/case-leaf/spec.md
 */
final class CaseLeafRegister {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
	) {
	}//end __construct()
}//end class
