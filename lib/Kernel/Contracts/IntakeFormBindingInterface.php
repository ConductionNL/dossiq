<?php

/**
 * Contract of IntakeFormBinding
 *
 * The public surface of IntakeFormBinding that other components call, so they depend
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
 * Contract of IntakeFormBinding.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntakeFormBindingInterface {

	/**
	 * The mapping a submission of the bound form is written with.
	 *
	 * Without answers it is the mapping as it is judged when the case type is
	 * saved. With answers, a binding that declares no fields gets its title
	 * and description from them.
	 *
	 * @param array<string, mixed>      $caseType The case type.
	 * @param array<string, mixed>|null $answers  The submitted answers, question to value; null for the check.
	 *
	 * @return array{fields: array<int, array<string, mixed>>, fixed: array<string, mixed>} The mapping.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function mappingFor(array $caseType, ?array $answers = null): array;
}//end interface
