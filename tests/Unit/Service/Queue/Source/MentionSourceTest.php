<?php

/**
 * Unit tests for MentionSource: each open mention becomes a queue row that
 * opens the case's notes, titled by who mentioned the reader when known.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue\Source;

use OCA\Dossiq\Service\Queue\MentionQueueRecords;
use OCA\Dossiq\Service\Queue\Source\MentionSource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Queue\Source\MentionSource
 *
 * @uses \OCA\Dossiq\Service\Queue\QueueItem
 */
class MentionSourceTest extends TestCase {
	use PersonalSourcesL10n;

	public function testOpenMentionsBecomeRowsThatOpenTheNotes(): void {
		$records = $this->createMock(MentionQueueRecords::class);
		$records->expects($this->once())->method('openFor')->with('alice')->willReturn(
			[
				['subjectId' => ' case-1 ', 'actor' => ' bob '],
				['subjectId' => 'case-2'],
				['subjectId' => '  ', 'actor' => 'carol'],
				['actor' => 'dave'],
			]
		);

		$items = (new MentionSource($records, $this->l10n()))->itemsFor('alice');

		$this->assertCount(2, $items);
		$this->assertSame(
			[
				'id' => 'mentions:mention:case-1',
				'source' => 'mentions',
				'subjectType' => 'mention',
				'subjectId' => 'case-1',
				'title' => 'bob mentioned you in a note',
				'priority' => '',
				'dueAt' => null,
				'coveredFor' => null,
				'covered' => false,
				'route' => ['name' => 'CaseDetail', 'params' => ['id' => 'case-1'], 'query' => ['tab' => 'notes']],
				'waiting' => [],
			],
			$items[0]->jsonSerialize()
		);
		$this->assertSame('You were mentioned in a note', $items[1]->title);
	}//end testOpenMentionsBecomeRowsThatOpenTheNotes()

	public function testTheSourceDescribesItself(): void {
		$source = new MentionSource($this->createMock(MentionQueueRecords::class), $this->l10n());

		$this->assertSame('mentions', $source->name());
		$this->assertSame('You were mentioned', $source->label());
		$this->assertSame('A mention leaves once you have opened what you were mentioned in.', $source->closesWhen());
		$this->assertSame([], $source->mechanisms());
	}//end testTheSourceDescribesItself()
}//end class
