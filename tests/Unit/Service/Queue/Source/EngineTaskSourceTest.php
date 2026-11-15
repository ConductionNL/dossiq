<?php

/**
 * Unit tests for EngineTaskSource: the reader's open engine tasks become queue
 * rows with their due date, and an inbox that could not be read fails the
 * source instead of showing an empty group.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue\Source;

use OCA\Dossiq\Service\Queue\Source\EngineTaskSource;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Queue\Source\EngineTaskSource
 *
 * @uses \OCA\Dossiq\Service\Queue\QueueItem
 */
class EngineTaskSourceTest extends TestCase {
	use PersonalSourcesL10n;

	private EngineTaskInbox&MockObject $inbox;

	protected function setUp(): void {
		parent::setUp();
		$this->inbox = $this->createMock(EngineTaskInbox::class);
	}//end setUp()

	public function testOpenTasksBecomeRowsWithTheirDueDate(): void {
		$this->inbox->expects($this->once())->method('openForAssignee')->with('alice')->willReturn(
			[
				['uuid' => ' t-1 ', 'title' => 'Beoordeel aanvraag', 'priority' => 'high', 'dueAt' => ' 2026-12-01 '],
				['id' => 't-2', 'name' => 'Bel aanvrager', 'dueDate' => '2026-12-05'],
				['id' => 't-3', 'dueAt' => '   '],
				['title' => 'No id'],
			]
		);
		$this->inbox->method('lastError')->willReturn('');

		$items = (new EngineTaskSource($this->inbox, $this->l10n()))->itemsFor('alice');

		$this->assertCount(3, $items);
		$this->assertSame(['tasks:task:t-1', 'Beoordeel aanvraag', 'high', '2026-12-01'], [$items[0]->identity(), $items[0]->title, $items[0]->priority, $items[0]->dueAt]);
		$this->assertSame(['name' => 'TaskDetail', 'params' => ['id' => 't-1']], $items[0]->route);
		$this->assertSame(['Bel aanvrager', '', '2026-12-05'], [$items[1]->title, $items[1]->priority, $items[1]->dueAt]);
		$this->assertSame(['t-3', null], [$items[2]->title, $items[2]->dueAt]);
		$this->assertNull($items[0]->coveredFor);
	}//end testOpenTasksBecomeRowsWithTheirDueDate()

	public function testAnInboxThatCouldNotBeReadFailsTheSource(): void {
		$this->inbox->method('openForAssignee')->willReturn([]);
		$this->inbox->method('lastError')->willReturn(' the task store is unreachable ');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('the task store is unreachable');

		(new EngineTaskSource($this->inbox, $this->l10n()))->itemsFor('alice');
	}//end testAnInboxThatCouldNotBeReadFailsTheSource()

	public function testTheSourceDescribesItself(): void {
		$source = new EngineTaskSource($this->inbox, $this->l10n());

		$this->assertSame('tasks', $source->name());
		$this->assertSame('Your open tasks', $source->label());
		$this->assertSame('A task leaves when it is completed or cancelled.', $source->closesWhen());
		$this->assertSame(['flow:DossiqAskPersonNode', 'flow:DossiqTxCreateTaskNode'], $source->mechanisms());
	}//end testTheSourceDescribesItself()
}//end class
