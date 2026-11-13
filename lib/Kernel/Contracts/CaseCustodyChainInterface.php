<?php

/**
 * Contract of CaseCustodyChain
 *
 * The public surface of CaseCustodyChain that other components call, so they depend
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
 * Contract of CaseCustodyChain.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseCustodyChainInterface {

	/**
	 * Open the first holding of a case, where the chain has none yet.
	 *
	 * Used by the backfill, which dates the first holding from the case start
	 * rather than from the moment the backfill ran: a chain that begins when
	 * somebody remembered to write it is a chain with a hole at the front.
	 *
	 * @param string $caseId           The case.
	 * @param string $organisationUnit The unit that has held it since the start.
	 * @param string $handler          The person on the seat, or an empty string.
	 * @param string $from             The moment the holding began, in ISO 8601.
	 * @param string $reason           Why this holding exists.
	 * @param string $movedBy          Who is credited with it.
	 *
	 * @return array<string, mixed>|null The holding, or null when one was already open.
	 *
	 * @throws RefusedException When the chain cannot be written.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function begin(
		string $caseId,
		string $organisationUnit,
		string $handler,
		string $from,
		string $reason,
		string $movedBy,
	): ?array;

	/**
	 * Every holding of a case, oldest first.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> The chain.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function holdings(string $caseId): array;

	/**
	 * The holding that is running now, or null when the chain is empty.
	 *
	 * Resolves the transient two-open window documented on {@see move()} by
	 * taking the highest sequence, so a reader never has to guess.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<string, mixed>|null The open holding.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function openHoldingFor(string $caseId): ?array;
}//end interface
