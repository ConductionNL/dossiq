<?php

/**
 * Dossiq CaseDefinitionExport.
 *
 * One case definition package while it is exported: the case type it is of,
 * the components it carries and its manifest.
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The state of one export, built and read by CaseDefinitionExportService only.
 *
 * @spec openspec/specs/case-types/spec.md
 */
final class CaseDefinitionExport {
	/**
	 * The OpenRegister id of the case type.
	 *
	 * @var string
	 */
	public readonly string $caseTypeId;

	/**
	 * The case type object itself.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $caseType;

	/**
	 * The components the package carries.
	 *
	 * @var string[]
	 */
	public array $components = [];

	/**
	 * The package's manifest; set once it is built.
	 *
	 * @var array<string, mixed>
	 */
	public array $manifest = [];

	/**
	 * Start the export of a case type.
	 *
	 * @param string               $caseTypeId The OpenRegister id of the case type.
	 * @param array<string, mixed> $caseType   The case type object.
	 */
	public function __construct(string $caseTypeId, array $caseType) {
		$this->caseTypeId = $caseTypeId;
		$this->caseType = $caseType;
	}//end __construct()
}//end class
