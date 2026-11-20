<?php

/**
 * Contract of CaseTemplateService
 *
 * The public surface of CaseTemplateService that other components call, so they depend
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
 * Contract of CaseTemplateService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTemplateServiceInterface {

	/**
	 * Start a case from a template.
	 *
	 * @param string               $templateId The template's id.
	 * @param array<string, mixed> $overrides  Fields the handler set themselves.
	 *
	 * @return array{ok: bool, reason: string, case: array<string, mixed>} What happened.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
	 */
	public function startFrom(string $templateId, array $overrides = []): array;

	/**
	 * The templates a handler may start from.
	 *
	 * @param string $caseTypeId Only templates of this case type, or '' for all.
	 *
	 * @return array<int, array<string, mixed>>|null The templates, or null when
	 *                                               the store is unreachable.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
	 */
	public function templates(string $caseTypeId = ''): ?array;
}//end interface
