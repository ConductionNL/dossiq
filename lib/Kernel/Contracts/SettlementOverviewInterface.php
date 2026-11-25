<?php

/**
 * Contract of SettlementOverview
 *
 * The public surface of SettlementOverview that other components call, so they depend
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
 * Contract of SettlementOverview.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SettlementOverviewInterface {

	/**
	 * The settlements of the case, the most recent settlement date first, one
	 * without a date (a draft) before them all.
	 *
	 * `empty` is true while the case has no settlement, so a card can say so.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{empty: bool, settlements: list<array<string, mixed>>}
	 *
	 * @spec openspec/specs/case-page/spec.md
	 */
	public function forCase(string $caseId): array;

	/**
	 * The executions of the case's grants, each with the granted amount, the
	 * advances paid, its interim reports and its settlements (in the shape
	 * forCase gives a settlement), so the case page's grant actions can pick
	 * the row they act on.
	 *
	 * `empty` is true while the case has no execution, so a dialog can say so.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{empty: bool, executions: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
	 */
	public function chainForCase(string $caseId): array;
}//end interface
