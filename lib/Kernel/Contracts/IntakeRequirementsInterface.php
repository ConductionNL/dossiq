<?php

/**
 * Contract of IntakeRequirements
 *
 * The public surface of IntakeRequirements that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of IntakeRequirements.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntakeRequirementsInterface {

	/**
	 * The declaration this case type carries, with the default filled in.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array{requiredBeforeCreation: array<int, string>, requiredBeforeComplete: array<int, string>}
	 *
	 * @spec openspec/specs/semantic-case-intake/spec.md
	 */
	public function declarationFor(array $caseType): array;

	/**
	 * Refuse this creation when a before-creation field is unanswered.
	 *
	 * The refusal names the FIELD, not the count. "Two fields are missing" is
	 * a sentence nobody can act on; "the communication channel is missing" is
	 * one a handler fixes in the form in front of them.
	 *
	 * @param array<string, mixed> $case     The case as it would be written.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return void
	 *
	 * @throws RefusedException When a before-creation field is unanswered.
	 *
	 * @spec openspec/specs/semantic-case-intake/spec.md
	 */
	public function assertCreatable(array $case, array $caseType): void;
}//end interface
