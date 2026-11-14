<?php

/**
 * Contract of CaseTypeReader
 *
 * The public surface of CaseTypeReader that other components call, so they depend
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
 * Contract of CaseTypeReader.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypeReaderInterface {

	/**
	 * Whether a statusType closes the case it is on.
	 *
	 * @param string $statusTypeId StatusType UUID.
	 *
	 * @return bool True when the statusType carries isFinal.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function isFinalStatus(string $statusTypeId): bool;

	/**
	 * The payment rule one case type declares, or null when it declares none.
	 *
	 * Kept apart from {@see read()} on purpose. That method answers a fixed
	 * shape the transition engine reads on every move, and widening it would
	 * make every caller carry a field almost none of them asks about. This one
	 * is asked only where money is the question.
	 *
	 * A case type nobody can read answers null, which {@see UnpaidCaseGate}
	 * reads as "no payment rule". That is the additive reading and it is
	 * deliberate: this rule only ever ADDS a refusal, so an unreadable case
	 * type must leave the app behaving as it did before the rule existed,
	 * rather than stopping every melding on the instance.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array{paymentRequiredBeforeHandling: bool}|null The rule, or null.
	 *
	 * @spec openspec/specs/financial-integration/spec.md#requirement-a-case-type-decides-whether-an-unpaid-case-proceeds-req-fee-04
	 */
	public function paymentRule(string $caseTypeId): ?array;

	/**
	 * The lifecycle rules of one case type.
	 *
	 * A case type that cannot be read allows nothing, which is the safe
	 * reading: an unreadable rule is not permission.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array{suspensionAllowed: bool, extensionAllowed: bool, extensionPeriod: string,
	 *               maxExtensions: int|null, initialStatus: string, title: string,
	 *               entryRequirements: array<string, mixed>}
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function read(string $caseTypeId): array;
}//end interface
