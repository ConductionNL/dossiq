<?php

/**
 * Where the dossier compiler reads case documents from.
 *
 * Built by `DossierCompiler` once the dossier schemas are resolved, and handed through the steps that
 * collect each case's documents: OpenRegister's object service, the register and the caseDocument
 * schema.
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
 * Where the dossier compiler reads case documents from.
 *
 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
 */
final class CaseDocumentSource {

	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The register id.
	 * @param string $docSchema     The caseDocument schema id.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $docSchema,
	) {
	}//end __construct()
}//end class
