<?php

/**
 * Contract of AssigneeNarrowing
 *
 * The public surface of AssigneeNarrowing that other components call, so they depend
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
 * Contract of AssigneeNarrowing.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AssigneeNarrowingInterface {

	/**
	 * The declaration this case type carries.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array{allowedGroups: array<int, string>, allowedUsers: array<int, string>}
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	public function declarationFor(array $caseType): array;

	/**
	 * Whether this case type narrows anything at all.
	 *
	 * A case type declaring neither list keeps every choice. That is the
	 * behaviour every existing case type has today, and it stays the default
	 * so this change narrows nothing nobody asked to narrow.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return boolean True when every team and every person stays choosable.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	public function narrowsNothing(array $caseType): bool;

	/**
	 * Refuse a write naming a team or a person outside the narrowing.
	 *
	 * @param array<string, mixed> $case     The case as it would be written.
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the team or the person is outside the narrowing.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	public function assertWritable(array $case, array $caseType): void;
}//end interface
