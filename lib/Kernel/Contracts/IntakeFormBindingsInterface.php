<?php

/**
 * Contract of IntakeFormBindings
 *
 * The public surface of IntakeFormBindings that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of IntakeFormBindings.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntakeFormBindingsInterface {

	/**
	 * Every published case type that has a Forms form bound to it.
	 *
	 * @return array<int, array<string, mixed>> The case types.
	 *
	 * @throws RuntimeException When the case types cannot be read: a failed read is not "no forms".
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function boundCaseTypes(): array;

	/**
	 * Take a case type's Forms binding off, so the form opens no more cases.
	 *
	 * The mapping stays on the case type: binding the form again is one field.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the case type cannot be written, so OpenRegister reports the failure.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function unbind(string $caseTypeId): void;
}//end interface
