<?php

/**
 * Contract of DuplicatePolicy
 *
 * The public surface of DuplicatePolicy that other components call, so they depend
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
use OCP\IUser;

/**
 * Contract of DuplicatePolicy.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DuplicatePolicyInterface {

	/**
	 * What the create form is told before it asks anybody anything.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 * @param IUser|null           $user     The account filing the case.
	 *
	 * @return array{policy: string, mayOverride: bool, overrideGroups: array<int, string>}
	 *
	 * @spec openspec/specs/friendly-case-create-form/spec.md
	 */
	public function declarationFor(array $caseType, ?IUser $user): array;

	/**
	 * Refuse a create the case type blocks.
	 *
	 * Runs only when the case type says `block`, which is what bounds the blast
	 * radius: nothing happens at all to a case type that never asked for it.
	 *
	 * @param array<string, mixed> $case     The case as it would be written.
	 * @param array<string, mixed> $caseType The effective case type row.
	 * @param IUser|null           $user     The account filing the case.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the case type blocks and the account may not override.
	 *
	 * @spec openspec/specs/friendly-case-create-form/spec.md
	 */
	public function assertCreatable(array $case, array $caseType, ?IUser $user): void;
}//end interface
