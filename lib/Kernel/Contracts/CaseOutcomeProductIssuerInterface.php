<?php

/**
 * Contract of CaseOutcomeProductIssuer
 *
 * The public surface of CaseOutcomeProductIssuer that other components call, so they depend
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
 * Contract of CaseOutcomeProductIssuer.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseOutcomeProductIssuerInterface {

	/**
	 * Act on a concluded decision about a case.
	 *
	 * Never throws: the decision stands whether or not a product could be
	 * written, and a failure is logged with the case and the decision.
	 *
	 * @param string      $caseId     The case the decision is about.
	 * @param string      $decisionId The concluded decision.
	 * @param string      $status     The outcome status the decision app reported.
	 * @param string|null $decidedAt  When it concluded (ISO 8601), or null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-30-portal-permits-as-held-products/tasks.md#2.1
	 */
	public function onConcludedDecision(string $caseId, string $decisionId, string $status, ?string $decidedAt): void;
}//end interface
