<?php

/**
 * Contract of CaseTypeStore
 *
 * The public surface of CaseTypeStore that other components call, so they depend
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
 * Contract of CaseTypeStore.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypeStoreInterface {

	/**
	 * Every case type the register holds.
	 *
	 * The catalogue rather than one chain, for the one act that crosses case
	 * types: a rebind picks a target from all of them, and asking per
	 * identifier would need the list of identifiers first.
	 *
	 * The filter is EMPTY on purpose, which is not the same as `IS NULL`: the
	 * bare-key grammar `search()` speaks narrows on what it is given, so giving
	 * it nothing is the whole catalogue. Drafts are answered too and are
	 * dropped by the caller, because "every case type" and "every case type you
	 * may put a running case on" are different questions and this one is the
	 * store's.
	 *
	 * `$scope` is OpenRegister's scope-lifting parameters (`_rbac`, `_multitenancy`),
	 * passed only by the reader that must not depend on the caller and that
	 * then answers every row whatever the caller may read. That reader is
	 * the handling team rules on the case schema ({@see \OCA\Dossiq\Service\Access\CaseTeamAccessProjector}).
	 * Projected from the rows one administrator happens to see, a save by a
	 * narrower account would quietly drop the teams of every case type it could
	 * not read. The limit is wider for the same reason: a case type missing from
	 * the page is a case type whose team loses its cases.
	 *
	 * @param array<string, bool> $scope The scope-lifting parameters, or none.
	 *
	 * @return array<int, array<string, mixed>> The case types, unordered.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function everyCaseType(array $scope=[]): array;

	/**
	 * An object's id, whichever shape the store answered in.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or the empty string.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function rowId(array $row): string;

	/**
	 * The case type's workflow template: the one it names, else its active one.
	 *
	 * Here rather than on a caller because two of them ask: publishing writes
	 * the template out, and the publication checks walk its moves to find what
	 * nothing can reach. Both asked {@see self::rowsOfType()} and picked the
	 * same row the same way, and two readings of "which template is active" is
	 * how a publish and its own validation come to read different workflows.
	 *
	 * A type with templates but none marked active answers the first one, which
	 * is what an unmarked set has always meant here.
	 *
	 * `$named` is the `workflowDefinition` of the case type being judged. A
	 * check made while a case type is SAVED passes the incoming payload's value:
	 * an upgraded instance can hold an older active flow (a basis template
	 * written before the app shipped one), and judging by that refused the
	 * shipped case type on every upgrade (Zuiddrecht, 14 Oct). A name that does
	 * not resolve to a stored template falls back to the active one.
	 *
	 * @param string            $caseTypeId CaseType UUID.
	 * @param string|array|null $named      The flow the case type names: a uuid, an expanded row, or null.
	 *
	 * @return array<string, mixed> The template, or an empty array when there is none.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function activeTemplate(string $caseTypeId, string|array|null $named=null): array;
}//end interface
