<?php

/**
 * One Dutch column to move to its English name in a shard table.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Support;

/**
 * A Dutch column, its English name, and whether the English one is already there.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class ColumnMove {

	/**
	 * Constructor.
	 *
	 * @param string $old          The Dutch column.
	 * @param string $new          The English column.
	 * @param bool   $targetExists Whether the English column is already on the table.
	 */
	public function __construct(
		public readonly string $old,
		public readonly string $new,
		public readonly bool $targetExists,
	) {
	}//end __construct()
}//end class
