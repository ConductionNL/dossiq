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
 * A Dutch column, its English name, and the columns of the table it is on.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class ColumnMove {

	/**
	 * Constructor.
	 *
	 * @param string             $old     The Dutch column.
	 * @param string             $new     The English column.
	 * @param array<int, string> $columns The column names of the table.
	 */
	public function __construct(
		public readonly string $old,
		public readonly string $new,
		public readonly array $columns,
	) {
	}//end __construct()

	/**
	 * Whether the English column is already on the table.
	 *
	 * @return bool True when it is.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function targetExists(): bool {
		return in_array($this->new, $this->columns, true);
	}//end targetExists()
}//end class
