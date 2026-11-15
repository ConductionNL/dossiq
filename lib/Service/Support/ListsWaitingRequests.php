<?php

/**
 * Dossiq ListsWaitingRequests trait
 *
 * The queue items of the request rows that still wait on the reader, for a
 * register-backed queue source that declares its closed statuses.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared private step; each using class carries its own spec
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCA\Dossiq\Service\Queue\QueueItem;
use OCA\Dossiq\Service\Queue\Source\WaitingRequestRows;

/**
 * Waiting request rows as queue items, through the source's `rows()`, `idOf()` and `itemOf()`.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait ListsWaitingRequests {
	/**
	 * The queue items of the rows still waiting, skipping those in `self::CLOSED_STATUSES`.
	 *
	 * @param string               $schema  The schema of the request rows.
	 * @param array<string, mixed> $filters The filters naming the reader.
	 *
	 * @return array<int, QueueItem> The items.
	 */
	private function waitingItems(string $schema, array $filters): array {
		$rows = new WaitingRequestRows(closedStatuses: self::CLOSED_STATUSES);
		$items = [];
		foreach ($this->rows(schema: $schema, filters: $filters) as $row) {
			$id = $this->idOf(row: $row);
			if ($rows->isWaiting(id: $id, row: $row) === true) {
				$items[] = $this->itemOf(row: $row, id: $id);
			}
		}

		return $items;
	}//end waitingItems()
}//end trait
