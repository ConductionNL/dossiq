<?php

/**
 * Unit tests for OpenIncidentSource: each report assigned to the reader is a
 * queue row, opening its case when it has one, due on the day it happened.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue\Source;

use OCA\Dossiq\Service\Cases\IncidentStore;
use OCA\Dossiq\Service\Queue\Source\OpenIncidentSource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Queue\Source\OpenIncidentSource
 *
 * @uses \OCA\Dossiq\Service\Queue\QueueItem
 */
class OpenIncidentSourceTest extends TestCase {
	use PersonalSourcesL10n;

	public function testAssignedReportsBecomeRows(): void {
		$incidents = $this->createMock(IncidentStore::class);
		$incident = ['id' => ' inc-1 ', 'case' => ' case-9 ', 'description' => ' Water damage ', 'eventDate' => '2026-03-04T10:00:00+01:00'];
		$incidents->expects($this->once())->method('ownedBy')->with('alice')->willReturn(
			[
				$incident,
				['uuid' => 'inc-2', 'description' => '   ', 'eventDate' => ''],
			]
		);

		$items = (new OpenIncidentSource($incidents, $this->l10n()))->itemsFor('alice');

		$this->assertCount(2, $items);
		$this->assertSame(
			[
				'id' => 'openIncidents:incident:inc-1',
				'source' => 'openIncidents',
				'subjectType' => 'incident',
				'subjectId' => 'inc-1',
				'title' => 'Water damage',
				'priority' => '',
				'dueAt' => '2026-03-04',
				'coveredFor' => null,
				'covered' => false,
				'route' => ['name' => 'CaseDetail', 'params' => ['id' => 'case-9']],
				'waiting' => [],
			],
			$items[0]->jsonSerialize()
		);
		$this->assertSame($incident, $items[0]->subject);

		$this->assertSame('inc-2', $items[1]->subjectId);
		$this->assertSame('A report with no description', $items[1]->title);
		$this->assertNull($items[1]->dueAt);
		$this->assertSame(['name' => 'PersonalQueue'], $items[1]->route);
	}//end testAssignedReportsBecomeRows()

	public function testNobodyHasNoReports(): void {
		$incidents = $this->createMock(IncidentStore::class);
		$incidents->expects($this->never())->method('ownedBy');

		$this->assertSame([], (new OpenIncidentSource($incidents, $this->l10n()))->itemsFor(''));
	}//end testNobodyHasNoReports()

	public function testTheSourceDescribesItself(): void {
		$source = new OpenIncidentSource($this->createMock(IncidentStore::class), $this->l10n());

		$this->assertSame('openIncidents', $source->name());
		$this->assertSame('Reports assigned to you', $source->label());
		$this->assertSame('The report is settled, or handed to somebody else.', $source->closesWhen());
		$this->assertSame(['incident-assignment'], $source->mechanisms());
	}//end testTheSourceDescribesItself()
}//end class
