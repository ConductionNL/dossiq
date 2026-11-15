<?php

/**
 * Where a case's startable flows are looked up.
 *
 * Built by `CaseFlowActions::startableFlowIds()` and handed through the steps that read the case and
 * its case type: OpenRegister's ObjectService, the register and the case type schema.
 *
 * @category Flow
 * @package  OCA\Dossiq\Service\Flow
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

namespace OCA\Dossiq\Service\Flow;

/**
 * Where a case's startable flows are looked up.
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */
final class StartableFlowLookup {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register slug.
	 * @param string $typeSchema    The case type schema slug.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $typeSchema,
	) {
	}//end __construct()
}//end class
