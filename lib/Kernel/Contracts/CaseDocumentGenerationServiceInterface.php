<?php

/**
 * Contract of CaseDocumentGenerationService
 *
 * The public surface of CaseDocumentGenerationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use OCA\Dossiq\Service\Actions\ActionResult;

/**
 * Contract of CaseDocumentGenerationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseDocumentGenerationServiceInterface {

	/**
	 * Render a library template over a case and file the result in its dossier.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $templateId The library template id.
	 *
	 * @return ActionResult Succeeded with the new informatieobject id, or a
	 *                      named failure having created nothing.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function generate(string $caseId, string $templateId): ActionResult;
}//end interface
