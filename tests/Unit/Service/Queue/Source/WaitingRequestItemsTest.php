<?php

/**
 * WaitingRequestItems — one queue item per request still waiting on the reader.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue\Source;

use OCA\Dossiq\Service\Queue\QueueItem;
use OCA\Dossiq\Service\Queue\Source\WaitingRequestItems;
use PHPUnit\Framework\TestCase;

/**
 * Rows with an id and an open status become items; closed or id-less rows do not.
 *
 * @covers \OCA\Dossiq\Service\Queue\Source\WaitingRequestItems
 * @uses \OCA\Dossiq\Service\Queue\Source\WaitingRequestRows
 * @uses \OCA\Dossiq\Service\Queue\QueueItem
 */
final class WaitingRequestItemsTest extends TestCase {

	/**
	 * Only waiting rows of the asked schema and filters become items, in order.
	 *
	 * @return void
	 */
	public function testWaitingRowsBecomeItems(): void {
		$subject = new class() {
			use WaitingRequestItems {
				waitingItems as public;
			}

			private const CLOSED_STATUSES = ['afgerond'];

			/**
			 * The schema and filters the rows were asked for.
			 *
			 * @var array<int, mixed>
			 */
			public array $asked = [];

			/**
			 * The rows of a schema.
			 *
			 * @param string               $schema  The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			private function rows(string $schema, array $filters): array {
				$this->asked = [$schema, $filters];

				return [
					['id' => 'a', 'status' => 'open'],
					['id' => 'b', 'status' => 'Afgerond'],
					['id' => '', 'status' => 'open'],
					['id' => 'c'],
				];
			}//end rows()

			/**
			 * The row's id.
			 *
			 * @param array<string, mixed> $row The row.
			 *
			 * @return string
			 */
			private function idOf(array $row): string {
				return (string)($row['id'] ?? '');
			}//end idOf()

		};

		$items = $subject->waitingItems(
			schema: 'consultation',
			filters: ['assignee' => 'alice'],
			itemOf: static fn (array $row, string $id): QueueItem => new QueueItem(source: 'test', subjectType: 'request', subjectId: $id, title: $id)
		);

		self::assertSame(expected: ['consultation', ['assignee' => 'alice']], actual: $subject->asked);
		self::assertSame(expected: ['a', 'c'], actual: array_map(static fn (QueueItem $item): string => $item->subjectId, $items));
	}//end testWaitingRowsBecomeItems()
}//end class
