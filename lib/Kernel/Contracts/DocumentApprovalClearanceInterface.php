<?php

/**
 * Contract of DocumentApprovalClearance
 *
 * The public surface of DocumentApprovalClearance that other components call, so they depend
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
 * Contract of DocumentApprovalClearance.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocumentApprovalClearanceInterface {

	/**
	 * The sentence a refusal carries: the route and the step it waits on.
	 *
	 * "Not cleared" on its own makes somebody open decidiq and read three
	 * screens to learn one name, which is the reason decidiq's own clearance
	 * answer names them and this one passes them through rather than
	 * summarising them away.
	 *
	 * @param array{routed: bool, cleared: bool, waitingOn: array<int, array<string, mixed>>} $clearance The answer.
	 *
	 * @return string The reason, or the empty string when nothing is waiting.
	 *
	 * @spec openspec/specs/besluitvorming-leaf/spec.md
	 */
	public function describe(array $clearance): string;

	/**
	 * What decidiq says about one document's approval routes.
	 *
	 * @param string $documentId The informatieobject UUID.
	 * @param string $schema The subject schema slug decidiq knows it under.
	 *
	 * @return array{routed: bool, cleared: bool, waitingOn: array<int, array<string, mixed>>}
	 *         The answer, or NOT_ROUTED when decidiq cannot answer.
	 *
	 * @spec openspec/specs/besluitvorming-leaf/spec.md
	 */
	public function forDocument(string $documentId, string $schema = 'informatieobject'): array;
}//end interface
