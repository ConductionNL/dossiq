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
	 * @spec openspec/changes/overview-cards-per-case-type/specs/case-page/spec.md
	 */
	public function forCase(string $caseId): array;
}//end interface
