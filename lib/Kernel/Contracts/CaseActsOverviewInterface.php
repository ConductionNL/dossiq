<?php

/**
 * Contract of CaseActsOverview
 *
 * The public surface of CaseActsOverview that other components call, so they depend
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
 * Contract of CaseActsOverview.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseActsOverviewInterface {

	/**
	 * Whether the case is ready to be archived, item by item.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{caseId: string, checklist: array<int, array<string, mixed>>} The checklist.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function archiveReadiness(string $caseId): array;

	/**
	 * What this handler may do to this case, with a reason on every refusal.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The state and the verdicts.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 * @spec openspec/specs/case-management/spec.md#requirement-finishing-or-aborting-a-case-picks-its-result-from-the-case-type-req-cm-80
	 */
	public function overview(string $caseId): array;
}//end interface
