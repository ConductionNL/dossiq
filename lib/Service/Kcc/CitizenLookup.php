<?php

/**
 * One citizen lookup as it is recorded: who looked, at whom, whether it was allowed, what was answered and why.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

/**
 * One citizen lookup as it is recorded: who looked, at whom, whether it was allowed, what was answered and why.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class CitizenLookup {
	/**
	 * Constructor.
	 *
	 * @param string $employeeId The account that looked
	 * @param string $subjectId The citizen reference it looked with
	 * @param bool $allowed Whether the guard permitted it
	 * @param array<int, string> $fields The sensitive fields answered, [] when none
	 * @param string $ground Why the caller was allowed, or why not
	 */
	public function __construct(
		public readonly string $employeeId,
		public readonly string $subjectId,
		public readonly bool $allowed,
		public readonly array $fields,
		public readonly string $ground,
	) {
	}//end __construct()
}//end class
