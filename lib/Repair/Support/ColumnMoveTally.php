<?php

/**
 * How many columns a column repair renamed, back-filled and refused.
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
 * The counts one run of a column repair reports.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class ColumnMoveTally {

	/**
	 * The outcome of a move that renamed the column.
	 *
	 * @var string
	 */
	public const RENAMED = 'renamed';

	/**
	 * The outcome of a move that back-filled the English column.
	 *
	 * @var string
	 */
	public const COPIED = 'copied';

	/**
	 * Columns renamed.
	 *
	 * @var int
	 */
	public int $renamed = 0;

	/**
	 * English columns back-filled from the Dutch one.
	 *
	 * @var int
	 */
	public int $copied = 0;

	/**
	 * Columns left alone because two sources target one destination.
	 *
	 * @var int
	 */
	public int $refused = 0;

	/**
	 * Count the outcome of one move.
	 *
	 * @param string $outcome What the move did: renamed, copied, or nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function count(string $outcome): void {
		if ($outcome === self::RENAMED) {
			$this->renamed++;
			return;
		}

		if ($outcome === self::COPIED) {
			$this->copied++;
		}
	}//end count()
}//end class
