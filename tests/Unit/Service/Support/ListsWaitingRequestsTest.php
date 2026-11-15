<?php

/**
 * ListsWaitingRequests — the waiting request rows as queue items.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\ListsWaitingRequests;
use PHPUnit\Framework\TestCase;

/**
 * Closed and id-less rows are skipped; the rest become items in order.
 *
 * @covers \OCA\Dossiq\Service\Support\ListsWaitingRequests
 */
final class ListsWaitingRequestsTest extends TestCase {

	/**
	 * Only open rows with an id become items, read with the given schema and filters.
	 *
	 * @return void
	 */
	public function testListsTheWaitingRows(): void {
		$subject = new class() {
			use ListsWaitingRequests {
				waitingItems as public;
			}

			private const CLOSED_STATUSES = ['afgerond'];

			/**
			 * The reads made, as [schema, filters].
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $reads = [];

			/**
			 * The canned rows.
			 *
			 * @param string               $schema  The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			private function rows(string $schema, array $filters): array {
				$this->reads[] = [$schema, $filters];
				return [
					['id' => 'a', 'status' => 'open'],
					['id' => 'b', 'status' => ' Afgerond '],
					['status' => 'open'],
					['id' => 'c'],
				];
			}

			/**
			 * The row's id.
			 *
			 * @param array<string, mixed> $row The row.
			 *
			 * @return string
			 */
			private function idOf(array $row): string {
				return (string)($row['id'] ?? '');
			}

			/**
			 * The item of a row.
			 *
			 * @param array<string, mixed> $row The row.
			 * @param string               $id  The id.
			 *
			 * @return string
			 */
			private function itemOf(array $row, string $id): string {
				return 'item:' . $id;
			}
		};

		self::assertSame(['item:a', 'item:c'], $subject->waitingItems('consultation', ['assignee' => 'jan']));
		self::assertSame([['consultation', ['assignee' => 'jan']]], $subject->reads);
	}//end testListsTheWaitingRows()
}//end class
