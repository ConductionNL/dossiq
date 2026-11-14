<?php

/**
 * Contract of MajorCaseDeclaration
 *
 * The public surface of MajorCaseDeclaration that other components call, so they depend
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
 * Contract of MajorCaseDeclaration.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MajorCaseDeclarationInterface {

	/**
	 * Close a major case's channel and keep what was said with the case.
	 *
	 * @param string $caseId Case UUID.
	 *
	 * @return array{ok: bool, reason?: string, closed?: bool}
	 *         Whether a channel was closed, or a refusal naming its reason.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function closeMajorChannel(string $caseId): array;

	/**
	 * Declare a case major: one channel, the responders the case type names.
	 *
	 * @param string      $caseId     Case UUID.
	 * @param string|null $declaredBy User id that declared it.
	 *
	 * @return array{ok: bool, reason?: string, unresolved?: array<string>, channel?: array<string, mixed>, alreadyMajor?: bool}
	 *         The channel, or a refusal naming its reason and the group that failed.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function declareMajor(string $caseId, ?string $declaredBy = null): array;
}//end interface
