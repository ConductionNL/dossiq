<?php

/**
 * Dossiq repair support: SerialisedRows.
 *
 * OpenRegister answers a search with arrays or with entities that serialise
 * to one; the readers that only want the arrays keep them through this.
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
 * @spec exclude row plumbing shared by repair steps
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Support;

/**
 * Rows as arrays: serialisable objects serialised, anything that is not an array dropped.
 *
 * @spec exclude row plumbing shared by repair steps
 */
final class SerialisedRows {

	/**
	 * The rows that are, or serialise to, an array, in their order.
	 *
	 * @param array<mixed> $rows The rows as OpenRegister answered them.
	 *
	 * @return array<int, array<mixed>>
	 *
	 * @spec exclude row plumbing shared by repair steps
	 */
	public function arrays(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end arrays()
}//end class
