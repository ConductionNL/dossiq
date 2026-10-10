<?php

/**
 * QueueWidgetItems unit tests.
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
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Dashboard;

use OCA\Dossiq\Dashboard\QueueWidgetItems;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Ordering, links and the cut-off of the widget items.
 *
 * @covers \OCA\Dossiq\Dashboard\QueueWidgetItems
 */
class QueueWidgetItemsTest extends TestCase {
	use MakesCaseDateNormaliser;


	/**
	 * The mapper under test, over doubled URL and translation services.
	 *
	 * @var QueueWidgetItems
	 */
	private QueueWidgetItems $items;

	/**
	 * Set up the mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route): string => ($route === 'dossiq.dashboard.page') ? 'https://cloud.example/apps/dossiq/' : 'https://cloud.example/wrong'
		);
		$url->method('imagePath')->willReturn('/apps/dossiq/img/app-dark.svg');
		$url->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count): string => str_replace('%n', (string)$count, ($count === 1 ? $singular : $plural))
		);

		$this->items = new QueueWidgetItems(url: $url, l10n: $l10n, dates: $this->caseDates());

	}//end setUp()

	/**
	 * A case item and a task item map to a title, a subtitle and a link to their page.
	 *
	 * @return void
	 */
	public function testMapsACaseAndATaskToTheirPages(): void {
		$result = $this->items->items(
			queueItems: [
				$this->caseItem(id: 'c-1', title: 'Bouwvergunning Dorpsstraat 4', dueAt: '2026-10-14'),
				$this->taskItem(id: 't-1', title: 'Advies opvragen', dueAt: null),
			],
			limit: 7
		);

		$this->assertCount(2, $result);
		$this->assertContainsOnlyInstancesOf(WidgetItem::class, $result);

		$this->assertSame('Bouwvergunning Dorpsstraat 4', $result[0]->getTitle());
		$this->assertSame('Case, due 14-10-2026', $result[0]->getSubtitle());
		$this->assertSame('https://cloud.example/apps/dossiq/cases/c-1', $result[0]->getLink());
		$this->assertSame('https://cloud.example/apps/dossiq/img/app-dark.svg', $result[0]->getIconUrl());

		$this->assertSame('Advies opvragen', $result[1]->getTitle());
		$this->assertSame('Task', $result[1]->getSubtitle());
		$this->assertSame('https://cloud.example/apps/dossiq/tasks/t-1', $result[1]->getLink());

	}//end testMapsACaseAndATaskToTheirPages()

	/**
	 * Items are ordered by due date, soonest first, undated last, ties in queue order.
	 *
	 * @return void
	 */
	public function testOrdersBySoonestDueWithUndatedLast(): void {
		$result = $this->items->items(
			queueItems: [
				$this->taskItem(id: 'undated', title: 'Undated', dueAt: null),
				$this->caseItem(id: 'later', title: 'Later', dueAt: '2026-11-01'),
				$this->caseItem(id: 'first-tie', title: 'First tie', dueAt: '2026-10-12'),
				$this->taskItem(id: 'second-tie', title: 'Second tie', dueAt: '2026-10-12'),
				$this->caseItem(id: 'garbled', title: 'Garbled date', dueAt: 'not a date'),
			],
			limit: 7
		);

		$this->assertSame(
			['First tie', 'Second tie', 'Later', 'Undated', 'Garbled date'],
			array_map(static fn (WidgetItem $item): string => $item->getTitle(), $result)
		);

	}//end testOrdersBySoonestDueWithUndatedLast()

	/**
	 * Twelve items and a limit of seven: six items and "6 more in My work".
	 *
	 * @return void
	 */
	public function testReplacesTheLastItemWhenTheQueueHoldsMore(): void {
		$queue = [];
		for ($day = 1; $day <= 12; $day++) {
			$queue[] = $this->caseItem(id: 'c-' . $day, title: 'Case ' . $day, dueAt: sprintf('2026-10-%02d', $day));
		}

		$result = $this->items->items(queueItems: $queue, limit: 7);

		$this->assertCount(7, $result);
		$this->assertSame('Case 6', $result[5]->getTitle());
		$this->assertSame('6 more in My work', $result[6]->getTitle());
		$this->assertSame('https://cloud.example/apps/dossiq/my-work', $result[6]->getLink());

	}//end testReplacesTheLastItemWhenTheQueueHoldsMore()

	/**
	 * Exactly as many items as the limit: no "more" item.
	 *
	 * @return void
	 */
	public function testShowsEveryItemWhenTheyFit(): void {
		$result = $this->items->items(
			queueItems: [
				$this->caseItem(id: 'a', title: 'A', dueAt: '2026-10-01'),
				$this->caseItem(id: 'b', title: 'B', dueAt: '2026-10-02'),
			],
			limit: 2
		);

		$this->assertSame(['A', 'B'], array_map(static fn (WidgetItem $item): string => $item->getTitle(), $result));

	}//end testShowsEveryItemWhenTheyFit()

	/**
	 * A route with a query keeps it; an unknown route or a missing id leads to My work.
	 *
	 * @return void
	 */
	public function testLinksFallBackToMyWork(): void {
		$result = $this->items->items(
			queueItems: [
				new QueueItem(
					source: 'mentions',
					subjectType: 'mention',
					subjectId: 'm-1',
					title: 'Mentioned',
					dueAt: '2026-10-01',
					route: ['name' => 'CaseDetail', 'params' => ['id' => 'c-9'], 'query' => ['tab' => 'notes']]
				),
				new QueueItem(source: 'x', subjectType: 'plannedItem', subjectId: 'p-1', title: 'Unknown route', dueAt: '2026-10-02', route: ['name' => 'Nowhere']),
				new QueueItem(source: 'x', subjectType: 'case', subjectId: 'c-0', title: 'No id', dueAt: '2026-10-03', route: ['name' => 'CaseDetail']),
			],
			limit: 7
		);

		$this->assertSame('https://cloud.example/apps/dossiq/cases/c-9?tab=notes', $result[0]->getLink());
		$this->assertSame('Work item, due 01-10-2026', $result[0]->getSubtitle());
		$this->assertSame('https://cloud.example/apps/dossiq/my-work', $result[1]->getLink());
		$this->assertSame('https://cloud.example/apps/dossiq/my-work', $result[2]->getLink());

	}//end testLinksFallBackToMyWork()

	/**
	 * An empty queue answers no items.
	 *
	 * @return void
	 */
	public function testAnEmptyQueueAnswersNoItems(): void {
		$this->assertSame([], $this->items->items(queueItems: [], limit: 7));

	}//end testAnEmptyQueueAnswersNoItems()

	/**
	 * A case on the queue, as AssignedCasesSource writes it.
	 *
	 * @param string      $id    The case id.
	 * @param string      $title The title.
	 * @param string|null $dueAt The deadline.
	 *
	 * @return QueueItem The item.
	 */
	private function caseItem(string $id, string $title, ?string $dueAt): QueueItem {
		return new QueueItem(
			source: 'assigned',
			subjectType: 'case',
			subjectId: $id,
			title: $title,
			dueAt: $dueAt,
			route: ['name' => 'CaseDetail', 'params' => ['id' => $id]]
		);

	}//end caseItem()

	/**
	 * A task on the queue, as EngineTaskSource writes it.
	 *
	 * @param string      $id    The task id.
	 * @param string      $title The title.
	 * @param string|null $dueAt The due date.
	 *
	 * @return QueueItem The item.
	 */
	private function taskItem(string $id, string $title, ?string $dueAt): QueueItem {
		return new QueueItem(
			source: 'tasks',
			subjectType: 'task',
			subjectId: $id,
			title: $title,
			dueAt: $dueAt,
			route: ['name' => 'TaskDetail', 'params' => ['id' => $id]]
		);

	}//end taskItem()
}//end class
