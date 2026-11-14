<?php

/**
 * Contract of BeschikkingGenerationService
 *
 * The public surface of BeschikkingGenerationService that other components call, so they depend
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

/**
 * Contract of BeschikkingGenerationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BeschikkingGenerationServiceInterface {

	/**
	 * Generate a beschikking document for the given zaak.
	 *
	 * Selects the appropriate template (verleend/geweigerd) from config,
	 * attempts filinq PDF generation, and attaches the result as a
	 * bijlage on the vergunningaanvraag. Returns a result array with
	 * success status, bijlage ID, and a human-readable message.
	 *
	 * @param string $caseId The UUID of the zaak
	 * @param string $outcome Either 'granted' or 'refused'
	 * @param string $motivation The motivation text for the beslissing
	 *
	 * @return array<string,mixed> Result with keys: success, bijlageId, message. `success` is false
	 *                             when no bijlage could be saved, and `bijlageId` is then ''.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T04
	 */
	public function generateBeschikking(string $caseId, string $outcome, string $motivation): array;
}//end interface
