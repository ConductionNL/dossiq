<?php

/**
 * Dossiq row match run.
 *
 * The package row an existing row is looked for, and where it is looked for:
 * the object service, register and schema, and the settings key naming that
 * schema.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

/**
 * One look for the row an instance already holds for a package row.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
final class RowMatchRun {

	/**
	 * Constructor.
	 *
	 * @param object               $objectService The OpenRegister object service.
	 * @param string               $register      The register.
	 * @param string               $schema        The schema the row goes to.
	 * @param string               $schemaKey     The settings key naming that schema.
	 * @param array<string, mixed> $row           The package row.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
		public readonly string $schemaKey,
		public readonly array $row,
	) {
	}//end __construct()
}//end class
