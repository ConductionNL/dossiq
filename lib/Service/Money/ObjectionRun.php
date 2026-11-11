<?php

/**
 * One objection step against an accruing amount
 *
 * Where the accruing amount and its payouts are stored, which amount the
 * objection is about and the amount as it now stands: the state
 * AmountObjectionService builds once per register or resolve, so its steps
 * take one argument.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Money
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

/**
 * The state of one objection step; it never leaves the service that built it.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */
final class ObjectionRun {

	/**
	 * The OpenRegister object service.
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * The register.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The accruing amount schema.
	 *
	 * @var string
	 */
	public string $amountSchema = '';

	/**
	 * The payout schema.
	 *
	 * @var string
	 */
	public string $payoutSchema = '';

	/**
	 * The accruing amount id.
	 *
	 * @var string
	 */
	public string $calculationId = '';

	/**
	 * The accruing amount as it now stands.
	 *
	 * @var array<string, mixed>
	 */
	public array $calculation = [];

	/**
	 * The legal basis of the objection step.
	 *
	 * @var string
	 */
	public string $basis = '';

	/**
	 * The reasoning recorded with the step.
	 *
	 * @var string
	 */
	public string $rationale = '';

	/**
	 * Why a payout is withdrawn: the basis and the reasoning together.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function reason(): string {
		return trim($this->basis . ' ' . $this->rationale);
	}//end reason()
}//end class
