<?php

/**
 * Contract of Iv3TaakveldList
 *
 * The public surface of Iv3TaakveldList that other components call, so they depend
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
 * Contract of Iv3TaakveldList.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface Iv3TaakveldListInterface {

	/**
	 * Return every taakveld as a flat list, in category then code order.
	 *
	 * `deprecated` is TRUE for a pre-2023-refinement taakveld-6 code that
	 * was split into finer codes (`6.71`, `6.72`, `6.81`, `6.82`) — it
	 * remains resolvable (`isValidCode()`/`labelFor()`) for backward
	 * compatibility with cases classified before the refinement.
	 * `aggregatesUnder` is set on a 2023-refinement code to the pre-2023
	 * parent code it rolls up under for quarterly reporting (see
	 * {@see aggregationKeyFor()}); `null` for every other taakveld.
	 *
	 * @return array<int, array{
	 *     code: string, label: string, categoryCode: string,
	 *     categoryLabel: string, deprecated: bool, aggregatesUnder: string|null
	 * }>
	 *
	 * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
	 * @spec openspec/changes/archive/2026-07-14-iv3-taakveld-2023-refinement/specs/iv3-taakveld-2023-refinement/spec.md
	 */
	public function allTaakvelden(): array;

	/**
	 * The version tag of the shipped taakveld list.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
	 */
	public function version(): string;
}//end interface
