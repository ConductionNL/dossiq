<?php

/**
 * Contract of BoundFormsCheck
 *
 * The public surface of BoundFormsCheck that other components call, so they depend
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
 * Contract of BoundFormsCheck.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BoundFormsCheckInterface {

	/**
	 * The form an owning app announces.
	 *
	 * @var string
	 */
	public const FORM_DEPENDENT = 'OCA\\OpenRegister\\Service\\Form\\FormDependent';

	/**
	 * The rule a refused case type save names.
	 *
	 * @var string
	 */
	public const RULE = 'bound-form-invalid';

	/**
	 * After the case type is stored: unpublish each broken form and tell its author.
	 *
	 * OpenRegister does both, through the closure the owning app gave. An
	 * OpenRegister without that service leaves the forms as they are, and
	 * that is logged as an error: the forms then open no case.
	 *
	 * @param array<string, mixed> $assessment What `assess()` answered.
	 *
	 * @return array<int, array<string, mixed>> Each affected form with its outcome (id, app, title, outcome, findings).
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function apply(array $assessment): array;

	/**
	 * Judge every published form of another app that opens a case of this type.
	 *
	 * A draft case type is still being put together and is not judged. The
	 * case type's own Forms binding is part of the case type and is refused by
	 * the binding check itself, so it is left out here.
	 *
	 * @param array<string, mixed> $caseType The case type as it is being saved.
	 *
	 * @return array<string, mixed> OpenRegister's assessment shape: `refused`, `schema` and `affected` (id, app, title, break, findings, form).
	 *
	 * @psalm-return array{refused: bool, schema: string, affected: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function assess(array $caseType): array;

	/**
	 * Whether a stored object is a case type.
	 *
	 * @param array<string, mixed> $object The object payload, `@self` included.
	 *
	 * @return bool True when it belongs to the case type schema.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function isCaseType(array $object): bool;

	/**
	 * The affected forms without the form objects, for a response or a log line.
	 *
	 * @param array<string, mixed> $assessment What `assess()` answered.
	 *
	 * @return array<int, array<string, mixed>> id, app, title, break and findings per form.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function listed(array $assessment): array;

	/**
	 * The names a form may use for this case type: its id, uuid and slug.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return array<int, string> The names, without empty ones.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function namesOf(array $caseType): array;

	/**
	 * The sentence a refused case type save answers with.
	 *
	 * @param array<string, mixed> $assessment What `assess()` answered.
	 *
	 * @return string The sentence, naming each form and what it lacks.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function sentenceFor(array $assessment): string;
}//end interface
