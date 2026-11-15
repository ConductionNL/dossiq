<?php

/**
 * Contract of EmailTemplateService
 *
 * The public surface of EmailTemplateService that other components call, so they depend
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
 * Contract of EmailTemplateService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmailTemplateServiceInterface {

	/**
	 * Persist a new template (version 1).
	 *
	 * @param string $caseTypeId Owning caseType id/slug.
	 * @param array<string, mixed> $data Template payload (name/subject/body).
	 *
	 * @return array<string, mixed> The saved object.
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T04
	 */
	public function createTemplate(string $caseTypeId, array $data): array;

	/**
	 * Variable catalog grouped by source.
	 *
	 * The keys returned here are the supported `{{placeholder}}` names; the
	 * frontend renders them in the editor sidebar.
	 *
	 * @param string $caseTypeId CaseType id/slug (reserved for future per-type
	 *                           extension of the catalog).
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T04
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function getAvailableVariables(string $caseTypeId): array;

	/**
	 * List active templates for a caseType.
	 *
	 * @param string $caseTypeId CaseType id/slug.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T04
	 */
	public function listTemplates(string $caseTypeId): array;

	/**
	 * Prefill a draft from a template against a case.
	 *
	 * Returns the rendered subject/body plus any unresolved variable names.
	 * Does NOT send mail — handing the draft off to NC Mail is the leaf's
	 * responsibility.
	 *
	 * @param string $caseId Case UUID.
	 * @param string $templateId Template id/slug.
	 *
	 * @return array{subject: string, body: string, unresolved: array<int, string>, caseId: string, templateId: string}
	 *
	 * @throws \RuntimeException When the case is final or inputs are missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T04
	 */
	public function prefillDraft(string $caseId, string $templateId): array;

	/**
	 * Record what a shared-mailbox connection test found.
	 *
	 * Lives here rather than in the controller that runs the probe: that
	 * controller sits at PHPMD's coupling ceiling, and this service is already
	 * the one collaborator it has for everything mailbox-shaped.
	 *
	 * @param string $status One of the four integration states.
	 * @param string $message What the test reported.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function recordMailboxStatus(string $status, string $message): void;

	/**
	 * Bump-version update.
	 *
	 * Creates a NEW object with `version + 1` rather than overwriting the
	 * existing one — old versions remain queryable for audit.
	 *
	 * @param string $templateId Existing template id/slug.
	 * @param array<string, mixed> $data New payload.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T04
	 */
	public function updateTemplate(string $templateId, array $data): array;
}//end interface
