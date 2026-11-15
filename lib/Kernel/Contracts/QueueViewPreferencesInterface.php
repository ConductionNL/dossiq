<?php

/**
 * Contract of QueueViewPreferences
 *
 * The public surface of QueueViewPreferences that other components call, so they depend
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
 * Contract of QueueViewPreferences.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface QueueViewPreferencesInterface {

	/**
	 * Hide one group until tomorrow.
	 *
	 * @param string $userId The reader.
	 * @param string $group  The group key to hide.
	 * @param string $today  The reader's today, `Y-m-d`.
	 *
	 * @return array<int, string> The groups hidden after this call.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function hideForToday(string $userId, string $group, string $today): array;

	/**
	 * Remember what this person groups their queue by.
	 *
	 * @param string $userId  The reader.
	 * @param string $groupBy One of GROUPINGS.
	 *
	 * @return string The grouping now stored.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function setGroupBy(string $userId, string $groupBy): string;

	/**
	 * Show a group again.
	 *
	 * @param string $userId The reader.
	 * @param string $group  The group key to show.
	 * @param string $today  The reader's today, `Y-m-d`.
	 *
	 * @return array<int, string> The groups hidden after this call.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function showAgain(string $userId, string $group, string $today): array;
}//end interface
