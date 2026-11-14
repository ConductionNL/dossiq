<?php

/**
 * MyOpenWorkWidget and the My tasks item API, unit tests.
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

use OCA\Dossiq\Dashboard\MyOpenWorkWidget;
use OCA\Dossiq\Dashboard\OpenWorkSummary;
use OCA\Dossiq\Dashboard\QueueWidgetItems;
use OCA\Dossiq\Service\Queue\PersonalQueueService;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The start page widgets answer through Nextcloud's item API.
 *
 * @covers \OCA\Dossiq\Dashboard\MyOpenWorkWidget
 * @covers \OCA\Dossiq\Dashboard\OpenWorkSummary
 * @uses   \OCA\Dossiq\Dashboard\QueueWidgetItems
 * @uses   \OCA\Dossiq\Dashboard\DashboardPageWidget
 * @uses   \OCA\Dossiq\Service\Queue\QueueItem
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 */
class MyOpenWorkWidgetTest extends TestCase {
	use MakesCaseDateNormaliser;


	/**
	 * Doubled translations.
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * Doubled URL generator.
	 *
	 * @var IURLGenerator
	 */
	private IURLGenerator $url;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->url = $this->createMock(IURLGenerator::class);
		$this->url->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/dossiq/');
		$this->url->method('imagePath')->willReturn('/apps/dossiq/img/app-dark.svg');
		$this->url->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$this->l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count): string => str_replace('%n', (string)$count, ($count === 1 ? $singular : $plural))
		);

	}//end setUp()

	/**
	 * Two cases and three tasks: a cases row and a tasks row with late and today counted.
	 *
	 * @return void
	 */
	public function testSummarisesCasesAndTasks(): void {
		$queue = $this->createMock(PersonalQueueService::class);
		$queue->expects($this->once())->method('forPerson')->with('handler')->willReturn(
			[
				'items' => [
					$this->item(source: 'assigned', type: 'case', id: 'c-1', title: 'Kapvergunning', due: '2026-10-20', route: 'CaseDetail'),
					$this->item(source: 'tasks', type: 'task', id: 't-1', title: 'Advies opvragen', due: '2026-10-11', route: 'TaskDetail'),
					$this->item(source: 'tasks', type: 'task', id: 't-2', title: 'Besluit opstellen', due: '2026-10-12', route: 'TaskDetail'),
					$this->item(source: 'tasks', type: 'task', id: 't-3', title: 'Hoorzitting plannen', due: '2026-10-20', route: 'TaskDetail'),
					$this->item(source: 'assigned', type: 'case', id: 'c-2', title: 'Bouwvergunning', due: '2026-10-15', route: 'CaseDetail'),
				],
				'unavailable' => [],
				'total' => 5,
			]
		);

		$items = $this->widget(queue: $queue)->getItemsV2('handler', null, 7)->getItems();

		$this->assertSame(
			[['2 open cases on your name', ''], ['3 open tasks', '1 late, 1 today']],
			array_map(static fn (WidgetItem $item): array => [$item->getTitle(), $item->getSubtitle()], $items)
		);
		$this->assertSame(expected: 'https://cloud.example/apps/dossiq/my-work', actual: $items[0]->getLink());

	}//end testSummarisesCasesAndTasks()

	/**
	 * Cases held as substitute are counted under the cases row.
	 *
	 * @return void
	 */
	public function testCountsCasesHeldAsSubstitute(): void {
		$covered = new QueueItem(source: 'covered', subjectType: 'case', subjectId: 'c-9', title: 'Subsidie', coveredFor: 'esra');
		$queue = $this->createMock(PersonalQueueService::class);
		$queue->method('forPerson')->willReturn(['items' => [$covered], 'unavailable' => [], 'total' => 1]);

		$items = $this->widget(queue: $queue)->getItemsV2('handler')->getItems();

		$this->assertCount(expectedCount: 1, haystack: $items);
		$this->assertSame(expected: '1 open case on your name', actual: $items[0]->getTitle());
		$this->assertSame(expected: '1 as substitute', actual: $items[0]->getSubtitle());

	}//end testCountsCasesHeldAsSubstitute()

	/**
	 * An empty queue answers no items and the empty message.
	 *
	 * @return void
	 */
	public function testAnEmptyQueueSaysSo(): void {
		$queue = $this->createMock(PersonalQueueService::class);
		$queue->method('forPerson')->willReturn(['items' => [], 'unavailable' => [], 'total' => 0]);

		$items = $this->widget(queue: $queue)->getItemsV2('handler');

		$this->assertSame([], $items->getItems());
		$this->assertSame('Nothing waiting for you.', $items->getEmptyContentMessage());

	}//end testAnEmptyQueueSaysSo()

	/**
	 * The widget declares the item API, one button to My work, an icon and a 300 second reload.
	 *
	 * @return void
	 */
	public function testDeclaresTheItemApiAndTheButton(): void {
		$widget = $this->widget(queue: $this->createMock(PersonalQueueService::class));

		$this->assertInstanceOf(IAPIWidgetV2::class, $widget);
		$this->assertInstanceOf(IButtonWidget::class, $widget);
		$this->assertInstanceOf(IIconWidget::class, $widget);
		$this->assertInstanceOf(IReloadableWidget::class, $widget);

		$buttons = $widget->getWidgetButtons('handler');
		$this->assertCount(1, $buttons);
		$this->assertSame(WidgetButton::TYPE_MORE, $buttons[0]->getType());
		$this->assertSame(expected: 'Go to My work', actual: $buttons[0]->getText());
		$this->assertSame('https://cloud.example/apps/dossiq/my-work', $buttons[0]->getLink());

		$this->assertSame('dossiq_my_open_work_widget', $widget->getId());
		$this->assertSame(expected: 'My work', actual: $widget->getTitle());
		$this->assertSame('https://cloud.example/apps/dossiq/my-work', $widget->getUrl());
		$this->assertSame('https://cloud.example/apps/dossiq/img/app-dark.svg', $widget->getIconUrl());
		$this->assertSame('icon-dossiq-widget', $widget->getIconClass());
		$this->assertSame(300, $widget->getReloadInterval());
		$this->assertSame(11, $widget->getOrder());

		// No bundle to load: the host renders the items itself.
		$widget->load();

	}//end testDeclaresTheItemApiAndTheButton()

	/**
	 * The widget under test.
	 *
	 * @param PersonalQueueService $queue The queue double.
	 *
	 * @return MyOpenWorkWidget The widget.
	 */
	private function widget(PersonalQueueService $queue): MyOpenWorkWidget {
		$dates = $this->caseDatesFrozenAt(instant: '2026-10-12T09:00:00+02:00');
		$items = new QueueWidgetItems(url: $this->url, dates: $dates);
		return new MyOpenWorkWidget(
			l10n: $this->l10n,
			queue: $queue,
			summary: new OpenWorkSummary(l10n: $this->l10n, items: $items)
		);

	}//end widget()

	/**
	 * A queue item.
	 *
	 * @param string $source The source name.
	 * @param string $type   The subject type.
	 * @param string $id     The subject id.
	 * @param string $title  The title.
	 * @param string $due    The due date.
	 * @param string $route  The route name.
	 *
	 * @return QueueItem The item.
	 */
	private function item(string $source, string $type, string $id, string $title, string $due, string $route): QueueItem {
		return new QueueItem(
			source: $source,
			subjectType: $type,
			subjectId: $id,
			title: $title,
			dueAt: $due,
			route: ['name' => $route, 'params' => ['id' => $id]]
		);

	}//end item()
}//end class
