<?php

/**
 * Contract of UnpaidCaseGate
 *
 * The public surface of UnpaidCaseGate that other components call, so they depend
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
 * Contract of UnpaidCaseGate.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface UnpaidCaseGateInterface {

	/**
	 * Whether this case type makes payment a precondition for handling.
	 *
	 * ABSENT MEANS NO, deliberately. Most case types cost nothing, and a rule
	 * that defaulted to "wait for money" would stop every melding on every
	 * install the moment this shipped.
	 *
	 * @param array<string, mixed>|null $caseType The case type, as stored.
	 *
	 * @return bool True when an unpaid case waits.
	 *
	 * @spec openspec/specs/financial-integration/spec.md#requirement-a-case-type-decides-whether-an-unpaid-case-proceeds-req-fee-04
	 */
	public function requiresPayment(?array $caseType): bool;

	/**
	 * The sentence a refused act carries, or an empty string when the act may
	 * proceed.
	 *
	 * @param array<string, mixed>      $case     The case, carrying its projection.
	 * @param array<string, mixed>|null $caseType The case type, carrying the rule.
	 *
	 * @return string The refusal, '' when nothing stands in the way.
	 *
	 * @spec openspec/specs/financial-integration/spec.md#requirement-a-case-type-decides-whether-an-unpaid-case-proceeds-req-fee-04
	 */
	public function whyItWaits(array $case, ?array $caseType): string;
}//end interface
