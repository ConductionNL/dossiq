<?php

/**
 * Contract of RefusalOutcome
 *
 * The public surface of RefusalOutcome that other components call, so they depend
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
 * Contract of RefusalOutcome.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RefusalOutcomeInterface {

	/**
	 * Whether a case of this type can be refused at all.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return boolean True when a department and a role are declared.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	public function canRefuse(array $caseType): bool;

	/**
	 * Where a case of this type goes when it is refused.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array{department: string, role: string} The destination, blank when undeclared.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	public function destinationFor(array $caseType): array;

	/**
	 * Refuse one case at intake and hand it to its declared destination.
	 *
	 * @param string $caseId    The case UUID.
	 * @param string $reason    Why it is refused, in the refuser's own words.
	 * @param string $refusedBy The uid of the person refusing.
	 *
	 * @return array<string, mixed> `{refused: true, department, role, reason, refusedBy, refusedAt}`.
	 *
	 * @throws RefusedException When there is no reason, no destination, or no readable case.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	public function refuse(string $caseId, string $reason, string $refusedBy): array;
}//end interface
