<?php

/**
 * Where the WOO document assessments are stored: OpenRegister's object
 * service, the register and the assessment schema.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The object service, register and schema a WOO assessment is read from.
 *
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-1
 */
final class WooAssessmentLocation {

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The dossiq register.
	 * @param string $schema        The WOO assessment schema.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
