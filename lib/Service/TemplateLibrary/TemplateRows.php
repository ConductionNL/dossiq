<?php

/**
 * The rows of one collection a template declares, and the schema setting
 * they are saved under.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\TemplateLibrary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\TemplateLibrary;

/**
 * A template collection (status types, document types, ...) to save.
 *
 * @spec openspec/specs/template-library/spec.md
 */
final class TemplateRows {

	/**
	 * Constructor.
	 *
	 * @param string $schemaKey The app setting naming the schema the rows are saved in.
	 * @param mixed  $rows      The rows as the template declares them.
	 */
	public function __construct(
		public readonly string $schemaKey,
		public readonly mixed $rows,
	) {
	}//end __construct()
}//end class
