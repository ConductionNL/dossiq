<?php

/**
 * Contract of MenuCaseTypesService
 *
 * The public surface of MenuCaseTypesService that other components call, so they depend
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
 * Contract of MenuCaseTypesService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MenuCaseTypesServiceInterface {

	/**
	 * The current case types with one of the given identifiers, keyed by
	 * identifier, for views an instance names by identifier.
	 *
	 * @param array<int, string> $identifiers The case type identifiers.
	 *
	 * @return array<string, array{id: string, title: string}> The case types found.
	 *
	 * @spec openspec/specs/case-lists/spec.md
	 */
	public function byIdentifier(array $identifiers): array;

	/**
	 * The user's chosen case types, in their order, limited to what they may see.
	 *
	 * @param string $userId The user.
	 * @param array<int, array{id: string, title: string}> $visible The result of offeredCaseTypes().
	 *
	 * @return array<int, array{id: string, title: string}> The chosen case types in menu order.
	 *
	 * @spec openspec/specs/case-type-navigation/spec.md#REQ-CTN-004
	 */
	public function chosen(string $userId, array $visible): array;

	/**
	 * The case types offered to this user for their menu, sorted by title.
	 *
	 * The visible case types whose handling teams include a Nextcloud group
	 * the user is in. When that is none, because the user is in no handling
	 * team, every visible case type: a new employee or an instance that has
	 * not named its teams yet gets a useful picker, not an empty one.
	 *
	 * @param string $userId The user.
	 *
	 * @return array<int, array{id: string, title: string, icon?: string}> The offered case types.
	 *
	 * @spec openspec/specs/case-type-navigation/spec.md#REQ-CTN-004
	 * @spec openspec/specs/case-types/spec.md#requirement-a-case-type-names-the-teams-that-handle-it-req-ct-44
	 */
	public function offeredCaseTypes(string $userId): array;

	/**
	 * The current case types the current user may see, sorted by title.
	 *
	 * OpenRegister applies RBAC under the user session. A superseded version is
	 * left out: the list offers one row per case type, not one per version.
	 *
	 * @return array<int, array{id: string, title: string, icon?: string}> The case types; empty when
	 *   OpenRegister is unavailable or the register or schema is unconfigured.
	 *
	 * @spec openspec/specs/case-type-navigation/spec.md#REQ-CTN-004
	 */
	public function visibleCaseTypes(): array;
}//end interface
