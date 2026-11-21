<?php

/**
 * Contract of CaseCustodyQuery
 *
 * The public surface of CaseCustodyQuery that other components call, so they depend
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
 * Contract of CaseCustodyQuery.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseCustodyQueryInterface {

	/**
	 * The holdings a unit had that overlap a period.
	 *
	 * A holding counts when it started before the window ended and had not
	 * ended before the window began. An open holding has no end, so it counts
	 * whenever it started in time.
	 *
	 * @param string $organisationUnit The unit.
	 * @param string $from             The start of the window.
	 * @param string $to               The end of the window.
	 *
	 * @return array<int, array<string, mixed>> The holdings, oldest first.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function heldBy(string $organisationUnit, string $from, string $to): array;

	/**
	 * The holding that covered a case on a moment, or null.
	 *
	 * Exactly one holding covers any moment the case existed, because a move
	 * closes one holding and opens the next at the SAME moment. The boundary is
	 * therefore half-open: the holding that began at that instant owns it, and
	 * the one that ended does not, so a transfer date never returns two units.
	 *
	 * @param string $caseId The case.
	 * @param string $asOf   The moment, in anything DateTimeImmutable reads.
	 *
	 * @return array<string, mixed>|null The holding.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function holderOn(string $caseId, string $asOf): ?array;
}//end interface
