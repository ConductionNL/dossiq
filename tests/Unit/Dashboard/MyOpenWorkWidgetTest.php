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
use OCA\Dossiq\Dashboard\MyTasksWidget;
use OCA\Dossiq\Dashboard\QueueWidgetItems;
use OCA\Dossiq\Service\Queue\PersonalQueueService;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCA\Dossiq\Service\Queue\Source\EngineTaskSource;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
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
use Psr\Log\LoggerInterface;

/**
 * The start page widgets answer through Nextcloud's item API.
 *
 * @covers \OCA\Dossiq\Dashboard\MyOpenWorkWidget
 * @covers \OCA\Dossiq\Dashboard\MyTasksWidget
 * @uses   \OCA\Dossiq\Dashboard\QueueWidgetItems
 * @uses   \OCA\Dossiq\Service\Queue\QueueItem
 * @uses   \OCA\Dossiq\Service\Queue\Source\EngineTaskSource
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
	 * Two cases and a task on the queue: three items, soonest due first, with links.
	 *
	 * @return void
	 */
	public function testListsTheQueueSoonestDueFirst(): void {
		$queue = $this->createMock(PersonalQueueService::class);
		$queue->expects($this->once())->method('forPerson')->with('handler')->willReturn(
			[
				'items' => [
					$this->item(source: 'assigned', type: 'case', id: 'c-late', title: 'Kapvergunning', due: '2026-10-20', route: 'CaseDetail'),
					$this->item(source: 'tasks', type: 'task', id: 't-1', title: 'Advies opvragen', due: '2026-10-11', route: 'TaskDetail'),
					$this->item(source: 'assigned', type: 'case', id: 'c-soon', title: 'Bouwvergunning', due: '2026-10-15', route: 'CaseDetail'),
				],
				'unavailable' => [],
				'total' => 3,
			]
		);

		$items = $this->widget(queue: $queue)->getItemsV2('handler', null, 7);

		$this->assertSame(
			['Advies opvragen', 'Bouwvergunning', 'Kapvergunning'],
			array_map(static fn (WidgetItem $item): string => $item->getTitle(), $items->getItems())
		);
		$this->assertSame('https://cloud.example/apps/dossiq/tasks/t-1', $items->getItems()[0]->getLink());
		$this->assertSame('https://cloud.example/apps/dossiq/cases/c-soon', $items->getItems()[1]->getLink());
		$this->assertSame('Nothing waiting for you.', $items->getEmptyContentMessage());

	}//end testListsTheQueueSoonestDueFirst()

	/**
	 * Twelve items and a host asking for seven: six and "6 more in My work".
	 *
	 * @return void
	 */
	public function testMoreWorkThanFits(): void {
		$rows = [];
		for ($day = 1; $day <= 12; $day++) {
			$rows[] = $this->item(source: 'assigned', type: 'case', id: 'c-' . $day, title: 'Case ' . $day, due: sprintf('2026-10-%02d', $day), route: 'CaseDetail');
		}

		$queue = $this->createMock(PersonalQueueService::class);
		$queue->method('forPerson')->willReturn(['items' => $rows, 'unavailable' => [], 'total' => 12]);

		$items = $this->widget(queue: $queue)->getItemsV2('handler', null, 7)->getItems();

		$this->assertCount(7, $items);
		$this->assertSame('6 more in My work', $items[6]->getTitle());
		$this->assertSame('https://cloud.example/apps/dossiq/my-work', $items[6]->getLink());

	}//end testMoreWorkThanFits()

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
		$this->assertSame('Open my work', $buttons[0]->getText());
		$this->assertSame('https://cloud.example/apps/dossiq/my-work', $buttons[0]->getLink());

		$this->assertSame('dossiq_my_open_work_widget', $widget->getId());
		$this->assertSame('My open work', $widget->getTitle());
		$this->assertSame('https://cloud.example/apps/dossiq/my-work', $widget->getUrl());
		$this->assertSame('https://cloud.example/apps/dossiq/img/app-dark.svg', $widget->getIconUrl());
		$this->assertSame('icon-dossiq-widget', $widget->getIconClass());
		$this->assertSame(300, $widget->getReloadInterval());
		$this->assertSame(11, $widget->getOrder());

		// No bundle to load: the host renders the items itself.
		$widget->load();

	}//end testDeclaresTheItemApiAndTheButton()

	/**
	 * My tasks keeps its id and answers two open tasks from the engine, each with a link.
	 *
	 * The engine source is the real one; only the inbox it reads is doubled.
	 *
	 * @return void
	 */
	public function testMyTasksAnswersItsOpenTasksThroughTheItemApi(): void {
		$inbox = $this->createMock(EngineTaskInbox::class);
		$inbox->method('openForAssignee')->with('handler')->willReturn(
			[
				['uuid' => 't-2', 'title' => 'Besluit tekenen', 'dueAt' => '2026-10-18'],
				['uuid' => 't-1', 'title' => 'Advies opvragen', 'dueAt' => '2026-10-12'],
			]
		);
		$inbox->method('lastError')->willReturn('');

		$widget = $this->myTasks(source: new EngineTaskSource(inbox: $inbox, l10n: $this->l10n));
		$items = $widget->getItemsV2('handler')->getItems();

		$this->assertSame('procest_my_tasks_widget', $widget->getId());
		$this->assertCount(2, $items);
		$this->assertSame('Advies opvragen', $items[0]->getTitle());
		$this->assertSame('https://cloud.example/apps/dossiq/tasks/t-1', $items[0]->getLink());
		$this->assertSame('https://cloud.example/apps/dossiq/tasks/t-2', $items[1]->getLink());

	}//end testMyTasksAnswersItsOpenTasksThroughTheItemApi()

	/**
	 * When the engine cannot be read, My tasks says so instead of claiming there are no tasks.
	 *
	 * @return void
	 */
	public function testMyTasksSaysWhenTheEngineCannotBeRead(): void {
		$inbox = $this->createMock(EngineTaskInbox::class);
		$inbox->method('openForAssignee')->willReturn([]);
		$inbox->method('lastError')->willReturn('engine offline');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$items = $this->myTasks(source: new EngineTaskSource(inbox: $inbox, l10n: $this->l10n), logger: $logger)->getItemsV2('handler');

		$this->assertSame([], $items->getItems());
		$this->assertSame('Your tasks could not be read just now.', $items->getEmptyContentMessage());

	}//end testMyTasksSaysWhenTheEngineCannotBeRead()

	/**
	 * The widget under test.
	 *
	 * @param PersonalQueueService $queue The queue double.
	 *
	 * @return MyOpenWorkWidget The widget.
	 */
	private function widget(PersonalQueueService $queue): MyOpenWorkWidget {
		return new MyOpenWorkWidget(
			l10n: $this->l10n,
			queue: $queue,
			items: new QueueWidgetItems(url: $this->url, l10n: $this->l10n, dates: $this->caseDates())
		);

	}//end widget()

	/**
	 * The My tasks widget over a given engine source.
	 *
	 * @param EngineTaskSource     $source The engine source.
	 * @param LoggerInterface|null $logger The logger, a silent double when null.
	 *
	 * @return MyTasksWidget The widget.
	 */
	private function myTasks(EngineTaskSource $source, ?LoggerInterface $logger = null): MyTasksWidget {
		return new MyTasksWidget(
			l10n: $this->l10n,
			url: $this->url,
			tasks: $source,
			items: new QueueWidgetItems(url: $this->url, l10n: $this->l10n, dates: $this->caseDates()),
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);

	}//end myTasks()

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
