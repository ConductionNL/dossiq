<?php

/**
 * Contract of CaseTypeResolver
 *
 * The public surface of CaseTypeResolver that other components call, so they depend
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
 * Contract of CaseTypeResolver.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypeResolverInterface {

	/**
	 * The whole blueprint, for the one page that shows all of it.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<string, mixed> The blueprint: `caseType` (the effective
	 *                              row), `parents` (the ancestors it merged),
	 *                              `statusTypes`, `resultTypes`,
	 *                              `propertyDefinitions` and `counts`.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function blueprintFor(string $caseTypeId): array;

	/**
	 * The chain of case types from one type up to its root ancestor.
	 *
	 * Nearest first: index 0 is the type itself. A chain that returns to a type
	 * already in it stops there rather than looping, so a cycle already stored
	 * by an older version of the app degrades to a finite answer instead of
	 * hanging the request that reads it.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, array<string, mixed>> The rows, the type itself first.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function chainFor(string $caseTypeId): array;

	/**
	 * The loop naming a parent would close, as the titles a person reads.
	 *
	 * Shared by the publish path ({@see self::assertNoCycle()}) and the save
	 * path (`CaseTypeParentCycleListener`), so the two refuse the same chains
	 * and name them the same way.
	 *
	 * @param string $caseTypeId       The type being saved.
	 * @param string $parentCaseTypeId The parent it wants.
	 * @param string $selfTitle        The title the type is being saved with,
	 *                                 when the caller has it. A new type has no
	 *                                 stored row to read its title from.
	 *
	 * @return array<int, string> The titles from the type round to itself, or
	 *                            an empty array when there is no loop.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function cycleFor(string $caseTypeId, string $parentCaseTypeId, string $selfTitle = ''): array;

	/**
	 * The effective case type: its own fields, with the parent's where it is silent.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<string, mixed> The merged row, or an empty array when unreadable.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function effectiveCaseType(string $caseTypeId): array;

	/**
	 * The statuses a case of this type can be in.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, array<string, mixed>> The rows, each carrying `origin`.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function statusTypesFor(string $caseTypeId): array;
}//end interface
