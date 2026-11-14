<?php

/**
 * Unit tests for CoveredWorkSource: the cases and tasks the reader covers for
 * an absent colleague become rows naming that colleague; rows without an id
 * or an absentee are not covered work.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue\Source;

use OCA\Dossiq\Service\Queue\Source\CoveredWorkSource;
use OCA\Dossiq\Service\SubstitutionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Queue\Source\CoveredWorkSource
 *
 * @uses \OCA\Dossiq\Service\Queue\QueueItem
 */
class CoveredWorkSourceTest extends TestCase {
	use PersonalSourcesL10n;

	private SubstitutionService&MockObject $substitutions;

	protected function setUp(): void {
		parent::setUp();
		$this->substitutions = $this->createMock(SubstitutionService::class);
	}//end setUp()

	public function testCoveredCasesThenTasksBecomeRowsNamingTheAbsentee(): void {
		$this->substitutions->expects($this->once())->method('getSubstitutedWorkFor')->with('alice')->willReturn(
			[
				'cases' => [
					['id' => 'case-1', 'title' => 'Bezwaar', 'priority' => 'high', 'deadline' => ' 2026-12-01 ', '_substituted' => ['absentee' => ' bob ']],
					['uuid' => 'case-2', 'deadline' => '', '_substituted' => ['absentee' => 'bob']],
					['id' => 'case-3', '_substituted' => ['absentee' => '']],
					['id' => '', '_substituted' => ['absentee' => 'bob']],
					['id' => 'case-5', '_substituted' => 'bob'],
				],
				'tasks' => [
					['id' => 't-1', 'title' => 'Bel', 'dueAt' => '2026-12-03', 'deadline' => '2026-01-01', '_substituted' => ['absentee' => 'carol']],
				],
			]
		);

		$items = (new CoveredWorkSource($this->substitutions, $this->l10n()))->itemsFor('alice');

		$this->assertSame(
			['covered-work:case:case-1', 'covered-work:case:case-2', 'covered-work:task:t-1'],
			array_map(static fn ($item): string => $item->identity(), $items)
		);
		$this->assertSame(['Bezwaar', 'high', '2026-12-01', 'bob', true], [$items[0]->title, $items[0]->priority, $items[0]->dueAt, $items[0]->coveredFor, $items[0]->isCovered()]);
		$this->assertSame(['name' => 'CaseDetail', 'params' => ['id' => 'case-1']], $items[0]->route);
		$this->assertSame(['case-2', null], [$items[1]->title, $items[1]->dueAt]);
		$this->assertSame(['2026-12-03', 'carol'], [$items[2]->dueAt, $items[2]->coveredFor]);
		$this->assertSame(['name' => 'TaskDetail', 'params' => ['id' => 't-1']], $items[2]->route);
	}//end testCoveredCasesThenTasksBecomeRowsNamingTheAbsentee()

	public function testNoOrMalformedCoveredWorkGivesNoRows(): void {
		$this->substitutions->method('getSubstitutedWorkFor')->willReturnOnConsecutiveCalls([], ['cases' => 'none', 'tasks' => null]);
		$source = new CoveredWorkSource($this->substitutions, $this->l10n());

		$this->assertSame([], $source->itemsFor('alice'));
		$this->assertSame([], $source->itemsFor('alice'));
	}//end testNoOrMalformedCoveredWorkGivesNoRows()

	public function testTheSourceDescribesItself(): void {
		$source = new CoveredWorkSource($this->substitutions, $this->l10n());

		$this->assertSame('covered-work', $source->name());
		$this->assertSame('Work you are covering', $source->label());
		$this->assertSame('Covered work leaves when your colleague is back, or when the work is done.', $source->closesWhen());
		$this->assertSame(['notification:substitutionRegisteredForSubstitute'], $source->mechanisms());
	}//end testTheSourceDescribesItself()
}//end class
