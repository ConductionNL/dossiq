<?php

/**
 * Dossiq ReadsQueueCaseSchema trait
 *
 * The configured case schema a queue source reads, case when none is set.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared private step; each using class carries its own spec
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * The configured case schema a queue source reads, case when none is set.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait ReadsQueueCaseSchema {
	/**
	 * The configured case schema, `case` when none is set.
	 *
	 * @return string The schema.
	 */
	private function caseSchema(): string {
		$schema = trim((string)$this->settings->getConfigValue('case_schema', 'case'));
		if ($schema === '') {
			$schema = 'case';
		}

		return $schema;
	}//end caseSchema()
}//end trait
