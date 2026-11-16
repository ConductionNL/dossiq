<?php

/**
 * The queue items of the requests still waiting on the reader.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue\Source
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
 * @spec openspec/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue\Source;

use OCA\Dossiq\Service\Queue\QueueItem;

/**
 * For a register-backed source with `CLOSED_STATUSES`; the source builds each item.
 *
 * @spec openspec/specs/my-work/spec.md
 */
trait WaitingRequestItems {

	/**
	 * One queue item per row of the schema that matches the filters and is still waiting.
	 *
	 * @param string                                           $schema  The schema of the requests.
	 * @param array<string, mixed>                             $filters The filters naming the reader.
	 * @param callable(array<string, mixed>, string):QueueItem $itemOf  Builds the item of a waiting row and its id.
	 *
	 * @return array<int, QueueItem> The items.
	 */
	private function waitingItems(string $schema, array $filters, callable $itemOf): array {
		$rows = new WaitingRequestRows(closedStatuses: self::CLOSED_STATUSES);
		$items = [];
		foreach ($this->rows(schema: $schema, filters: $filters) as $row) {
			$id = $this->idOf(row: $row);
			if ($rows->isWaiting(id: $id, row: $row) === true) {
				$items[] = $itemOf($row, $id);
			}
		}

		return $items;
	}//end waitingItems()
}//end trait
