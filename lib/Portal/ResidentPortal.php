<?php

/**
 * What the resident pages are built from: the citizen collections and actions, the page group and the labels.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
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

namespace OCA\Dossiq\Portal;

/**
 * What the resident pages are built from: the citizen collections and actions, the page group and the labels.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class ResidentPortal {
	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $collections The citizen collections
	 * @param array<int, array<string, mixed>> $actions The citizen actions
	 * @param string $group The group every page sits in
	 * @param array<string, string> $labels The label per page id
	 */
	public function __construct(
		public readonly array $collections,
		public readonly array $actions,
		public readonly string $group,
		public readonly array $labels,
	) {
	}//end __construct()
}//end class
