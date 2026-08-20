<?php

/**
 * Procest Beschikking Template-Engine Adapter Interface.
 *
 * Contract for the Docudesk template-engine integration. The real adapter
 * (delivered in the docudesk repo, change task T26) renders a versioned
 * template to PDF/A-3 from zaakdata; the MockAdapter returns a deterministic
 * stub so the Procest pipeline is testable without a live Docudesk instance.
 *
 * @category Interface
 * @package  OCA\Procest\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T26
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Beschikking;

/**
 * Renders a beschikking template (Docudesk) to PDF/A-3.
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T26
 */
interface TemplateEngineAdapterInterface {
	/**
	 * Render a template to PDF/A-3 from zaakdata context.
	 *
	 * @param string $templateId The template identifier.
	 * @param array<string, mixed> $context The zaakdata + beschikking context.
	 *
	 * @return array{format: string, fileId: string, checksumSha256: string, paginas: int} Composition metadata.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T26
	 */
	public function render(string $templateId, array $context): array;

	/**
	 * Resolve the template version effective on a given date.
	 *
	 * @param string $templateId The template identifier.
	 * @param string $effectiveDate The ISO date the beschikking is effective.
	 *
	 * @return array{templateId: string, version: string, effectiveDate: string} The resolved version.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T26
	 */
	public function resolveVersion(string $templateId, string $effectiveDate): array;
}//end interface
