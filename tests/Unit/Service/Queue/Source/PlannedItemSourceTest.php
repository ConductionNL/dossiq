<?php

/**
 * Unit tests for PlannedItemSource: each upcoming item the reader planned is a
 * queue row in their own queue, due on the day it starts.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue\Source;

use OCA\Dossiq\Service\Queue\PersonalAgendaItemService;
use OCA\Dossiq\Service\Queue\Source\PlannedItemSource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Queue\Source\PlannedItemSource
 *
 * @uses \OCA\Dossiq\Service\Queue\QueueItem
 */
class PlannedItemSourceTest extends TestCase {
	use PersonalSourcesL10n;

	public function testUpcomingItemsBecomeRowsDueOnTheirDay(): void {
		$agenda = $this->createMock(PersonalAgendaItemService::class);
		$agenda->expects($this->once())->method('upcomingFor')->with('alice')->willReturn(
			[
				['uid' => 'p-1', 'title' => 'Call the applicant', 'startsAt' => ' 2026-05-06T09:30:00Z '],
				['uid' => 'p-2', 'title' => 'Think it over', 'startsAt' => ''],
			]
		);

		$items = (new PlannedItemSource($agenda, $this->l10n()))->itemsFor('alice');

		$this->assertCount(2, $items);
		$this->assertSame(
			[
				'id' => 'planned-items:plannedItem:p-1',
				'source' => 'planned-items',
				'subjectType' => 'plannedItem',
				'subjectId' => 'p-1',
				'title' => 'Call the applicant',
				'priority' => '',
				'dueAt' => '2026-05-06',
				'coveredFor' => null,
				'covered' => false,
				'route' => ['name' => 'PersonalQueue'],
				'waiting' => [],
			],
			$items[0]->jsonSerialize()
		);
		$this->assertNull($items[1]->dueAt);
	}//end testUpcomingItemsBecomeRowsDueOnTheirDay()

	public function testTheSourceDescribesItself(): void {
		$source = new PlannedItemSource($this->createMock(PersonalAgendaItemService::class), $this->l10n());

		$this->assertSame('planned-items', $source->name());
		$this->assertSame('Planned by you', $source->label());
		$this->assertSame('A planned item leaves when its moment has passed, or when you delete it.', $source->closesWhen());
		$this->assertSame([], $source->mechanisms());
	}//end testTheSourceDescribesItself()
}//end class
