<?php

/**
 * A group this repair step just created, with its id for messages.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\IGroup;

/**
 * A group this repair step just created, with its id for messages.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class MintedGroup {

	/**
	 * Constructor.
	 *
	 * @param IGroup $group   The group that was just created.
	 * @param string $groupId The group id, for messages.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function __construct(
		public readonly IGroup $group,
		public readonly string $groupId,
	) {
	}//end __construct()
}//end class
