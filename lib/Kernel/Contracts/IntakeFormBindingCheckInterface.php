<?php

/**
 * Contract of IntakeFormBindingCheck
 *
 * The public surface of IntakeFormBindingCheck that other components call, so they depend
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
 * Contract of IntakeFormBindingCheck.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntakeFormBindingCheckInterface {

	/**
	 * The rule a refused binding names.
	 *
	 * @var string
	 */
	public const RULE = 'intake-form-binding-invalid';

	/**
	 * Every problem with the case type's intake form binding.
	 *
	 * @param array<string, mixed> $caseType The case type as it is about to be saved.
	 *
	 * @return array<int, array<string, mixed>> The findings: `property`, `code`, `message` and, for an answer, `field`. Empty when valid or unbound.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function findingsFor(array $caseType): array;

	/**
	 * The sentence a refused save answers with.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings.
	 *
	 * @return string The sentence.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function sentenceFor(array $findings): string;
}//end interface
