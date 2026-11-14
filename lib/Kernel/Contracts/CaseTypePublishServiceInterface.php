<?php

/**
 * Contract of CaseTypePublishService
 *
 * The public surface of CaseTypePublishService that other components call, so they depend
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
 * Contract of CaseTypePublishService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypePublishServiceInterface {

	/**
	 * Publish a draft case type.
	 *
	 * Validation runs first and a non-empty finding list stops everything: a
	 * partial publish — draft flag cleared, workflow template still a draft —
	 * is worse than no publish, because nothing afterwards says which half ran.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 * @param string $changeNote  What changed in this version.
	 *
	 * @return array<string, mixed> `{published: bool, findings: string[], warnings: string[], version: ?int}`.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 */
	public function publish(string $caseTypeId, string $changeNote): array;

	/**
	 * What stands between this draft and being published.
	 *
	 * Returns the findings as sentences a person can act on, never as codes:
	 * the list is rendered straight into the Publish dialog, and "no findings"
	 * is the empty array.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, string> The findings, empty when the draft is publishable.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function validate(string $caseTypeId): array;

	/**
	 * What publishing this case type should say out loud without refusing.
	 *
	 * 🔴 A WARNING AND NOT A FINDING, ON PURPOSE. A finding blocks publication,
	 * and a case type may genuinely owe no acknowledgement of receipt, so
	 * refusing would make a lawful configuration unpublishable. What must not
	 * happen is the duty coming OFF quietly: Awb 4:3a owes a confirmation to
	 * every electronic submission, and an app that stops sending one still
	 * reads green. So publication says so and names the article, and the person
	 * publishing decides.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, string> The warnings, empty when nothing is off.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 */
	public function warnings(string $caseTypeId): array;
}//end interface
