<?php

/**
 * OpenWorkSummary unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Dashboard
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
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Dashboard;

use OCA\Dossiq\Dashboard\MyOpenWorkWidget;
use OCA\Dossiq\Dashboard\OpenWorkSummary;
use OCA\Dossiq\Dashboard\QueueWidgetItems;
use OCA\Dossiq\Service\Queue\PersonalQueueService;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The summary rows count the queue by subject type, due date and substitution.
 *
 * @covers \OCA\Dossiq\Dashboard\OpenWorkSummary
 * @covers \OCA\Dossiq\Dashboard\MyOpenWorkWidget
 * @uses   \OCA\Dossiq\Dashboard\QueueWidgetItems
 * @uses   \OCA\Dossiq\Service\Queue\QueueItem
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 */
class OpenWorkSummaryTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * Doubled translations.
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * The links, with today frozen at 2026-10-12 on the Amsterdam calendar.
	 *
	 * @var QueueWidgetItems
	 */
	private QueueWidgetItems $items;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/dossiq/');
		$url->method('imagePath')->willReturn('/apps/dossiq/img/app-dark.svg');
		$url->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$this->l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count): string => str_replace('%n', (string)$count, ($count === 1 ? $singular : $plural))
		);

		$this->items = new QueueWidgetItems(url: $url, dates: $this->caseDatesFrozenAt(instant: '2026-10-12T09:00:00+02:00'));
	}//end setUp()

	/**
	 * Only tasks: a single tasks row, no cases row in front of it.
	 *
	 * @return void
	 */
	public function testOnlyTasksAnswersOnlyTheTasksRow(): void {
		$rows = $this->summary()->rows(
			[
				new QueueItem(source: 'tasks', subjectType: 'task', subjectId: 't-1', title: 'A', dueAt: '2026-10-11'),
			]
		);

		$this->assertSame([['1 open task', '1 late, 0 today']], $this->titles(rows: $rows));
	}//end testOnlyTasksAnswersOnlyTheTasksRow()

	/**
	 * A task without a due date is neither late nor due today; a timestamp is compared by its date.
	 *
	 * @return void
	 */
	public function testDueDatesAreComparedByCalendarDate(): void {
		$rows = $this->summary()->rows(
			[
				new QueueItem(source: 'tasks', subjectType: 'task', subjectId: 't-1', title: 'No date'),
				new QueueItem(source: 'tasks', subjectType: 'task', subjectId: 't-2', title: 'Today, late hour', dueAt: '2026-10-12T23:59:00+02:00'),
				new QueueItem(source: 'tasks', subjectType: 'task', subjectId: 't-3', title: 'Yesterday', dueAt: '2026-10-11T08:00:00+02:00'),
				new QueueItem(source: 'tasks', subjectType: 'task', subjectId: 't-4', title: 'Tomorrow', dueAt: '2026-10-13'),
			]
		);

		$this->assertSame([['4 open tasks', '1 late, 1 today']], $this->titles(rows: $rows));
	}//end testDueDatesAreComparedByCalendarDate()

	/**
	 * Other subject types are not counted, and a queue of only those answers no rows.
	 *
	 * @return void
	 */
	public function testOtherSubjectTypesAreLeftOut(): void {
		$rows = $this->summary()->rows(
			[
				new QueueItem(source: 'consultations', subjectType: 'consultation', subjectId: 'x-1', title: 'Advice'),
				new QueueItem(source: 'mentions', subjectType: 'mention', subjectId: 'x-2', title: 'Mention'),
			]
		);

		$this->assertSame([], $rows);
	}//end testOtherSubjectTypesAreLeftOut()

	/**
	 * A blank substitute name is not a substitution, so the cases row has no subtitle.
	 *
	 * @return void
	 */
	public function testABlankSubstituteIsNotCounted(): void {
		$rows = $this->summary()->rows(
			[
				new QueueItem(source: 'assigned', subjectType: 'case', subjectId: 'c-1', title: 'Own', coveredFor: '  '),
				new QueueItem(source: 'covered', subjectType: 'case', subjectId: 'c-2', title: 'Covered', coveredFor: 'esra'),
				new QueueItem(source: 'covered', subjectType: 'case', subjectId: 'c-3', title: 'Covered too', coveredFor: 'joost'),
			]
		);

		$this->assertSame([['3 open cases on your name', '2 as substitute']], $this->titles(rows: $rows));
	}//end testABlankSubstituteIsNotCounted()

	/**
	 * Every row links to My work, carries the app icon and repeats its title as the item id.
	 *
	 * @return void
	 */
	public function testARowLinksToMyWorkWithTheAppIcon(): void {
		$summary = $this->summary();
		$rows = $summary->rows([new QueueItem(source: 'assigned', subjectType: 'case', subjectId: 'c-1', title: 'Own')]);

		$this->assertSame($this->items, $summary->links());
		$this->assertCount(1, $rows);
		$this->assertSame('https://cloud.example/apps/dossiq/my-work', $rows[0]->getLink());
		$this->assertSame('https://cloud.example/apps/dossiq/img/app-dark.svg', $rows[0]->getIconUrl());
		$this->assertSame('1 open case on your name', $rows[0]->getSinceId());
	}//end testARowLinksToMyWorkWithTheAppIcon()

	/**
	 * The widget drops anything in the queue answer that is not a queue item, and survives a missing items key.
	 *
	 * @return void
	 */
	public function testTheWidgetIgnoresWhatIsNotAQueueItem(): void {
		$queue = $this->createMock(PersonalQueueService::class);
		$queue->expects($this->exactly(2))->method('forPerson')->with('handler')->willReturnOnConsecutiveCalls(
			[
				'items' => [
					['subjectType' => 'case'],
					'case',
					new QueueItem(source: 'tasks', subjectType: 'task', subjectId: 't-1', title: 'A', dueAt: '2026-10-12'),
				],
			],
			['unavailable' => ['tasks']]
		);

		$widget = new MyOpenWorkWidget(l10n: $this->l10n, queue: $queue, summary: $this->summary());

		$this->assertSame([['1 open task', '0 late, 1 today']], $this->titles(rows: $widget->getItemsV2('handler')->getItems()));

		$empty = $widget->getItemsV2('handler');
		$this->assertSame([], $empty->getItems());
		$this->assertSame('Nothing waiting for you.', $empty->getEmptyContentMessage());
	}//end testTheWidgetIgnoresWhatIsNotAQueueItem()

	/**
	 * The summary under test.
	 *
	 * @return OpenWorkSummary The summary.
	 */
	private function summary(): OpenWorkSummary {
		return new OpenWorkSummary(l10n: $this->l10n, items: $this->items);
	}//end summary()

	/**
	 * Each row's title and subtitle.
	 *
	 * @param array<int, WidgetItem> $rows The rows.
	 *
	 * @return array<int, array{string, string}> Title and subtitle per row.
	 */
	private function titles(array $rows): array {
		return array_map(static fn (WidgetItem $item): array => [$item->getTitle(), $item->getSubtitle()], $rows);
	}//end titles()
}//end class
