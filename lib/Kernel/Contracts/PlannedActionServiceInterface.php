<?php

/**
 * Contract of PlannedActionService
 *
 * The public surface of PlannedActionService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of PlannedActionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PlannedActionServiceInterface {

	/**
	 * Complete an action, and plan the one its type declares as the successor.
	 *
	 * Returns the action that was planned, or null when the chain ends here.
	 * A caller cannot tell "the chain ended" from "nothing happened" by looking
	 * at the case, so the two are distinguished in the return rather than left
	 * to be inferred.
	 *
	 * @param array<string, mixed> $action The action being completed.
	 * @param string $completedBy Who completed it.
	 * @param array<string, string> $roleHolders Who holds each role on this case.
	 * @param DateTimeImmutable|null $completedOn The completion date; today when null.
	 *
	 * @return array<string, mixed>|null The action planned next, or null.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function complete(
		array $action,
		string $completedBy = '',
		array $roleHolders = [],
		?DateTimeImmutable $completedOn = null,
	): ?array;

	/**
	 * The action planned on this case, or null when none is.
	 *
	 * The EARLIEST planned one, because "what happens next on this case" has
	 * one answer and a list of three is not it. Actions are scheduled one at a
	 * time, so more than one open action means a chain was branched by hand,
	 * and the nearest is still the next thing to do.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed>|null The next planned action, or null.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function nextFor(string $caseId): ?array;

	/**
	 * Every action still planned on this case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> The planned actions.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function plannedOn(string $caseId): array;
}//end interface
