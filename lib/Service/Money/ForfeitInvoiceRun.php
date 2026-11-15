<?php

/**
 * One forfeited penalty on its way to an invoice
 *
 * What ForfeitInvoiceRaiser::raise() carries between its reads and the
 * request to the finance app: the act, its case, the case type and the payer.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Money
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

/**
 * The state of one forfeited penalty's invoice.
 *
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
 */
final class ForfeitInvoiceRun {

	/**
	 * The forfeiting act.
	 *
	 * @var array<string, mixed>
	 */
	public array $action = [];

	/**
	 * The case id the act names.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * The case.
	 *
	 * @var array<string, mixed>
	 */
	public array $openCase = [];

	/**
	 * The case's type.
	 *
	 * @var array<string, mixed>
	 */
	public array $caseType = [];

	/**
	 * Who pays: name and email; null when nobody can.
	 *
	 * @var array<string, string>|null
	 */
	public ?array $payer = null;
}//end class
