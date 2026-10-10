<?php

/**
 * Turns queue items into the items Nextcloud's widget API renders.
 *
 * Shared by the widgets that read the one personal queue ("My open work" and
 * "My tasks"), so both order, link and cut off the same way.
 *
 * @category Dashboard
 * @package  OCA\Dossiq\Dashboard
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

namespace OCA\Dossiq\Dashboard;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Maps queue items onto widget items: soonest due first, at most the host's
 * limit, the last place taken by "{n} more in My work" when the queue holds more.
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
 */
class QueueWidgetItems {

	/**
	 * The frontend path of each route name a queue source writes.
	 *
	 * A route name nothing here knows leads to My work, where every item of
	 * the queue is listed, rather than to a page that does not exist.
	 */
	private const ROUTE_PATHS = [
		'CaseDetail' => 'cases/{id}',
		'TaskDetail' => 'tasks/{id}',
		'PersonalQueue' => 'my-queue',
		'MyWork' => 'my-work',
	];

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator      $url   URL generator.
	 * @param IL10N              $l10n  Translations.
	 * @param CaseDateNormaliser $dates The one rule for what a date is, and its zone.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
	 */
	public function __construct(
		private readonly IURLGenerator $url,
		private readonly IL10N $l10n,
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * The widget items for a list of queue items.
	 *
	 * @param array<int, QueueItem> $queueItems The items, in any order.
	 * @param int                   $limit      The most items the host shows.
	 *
	 * @return array<int, WidgetItem> The widget items.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
	 */
	public function items(array $queueItems, int $limit): array {
		$ordered = $this->bySoonestDue(items: $queueItems);
		$limit = max(1, $limit);

		$shown = $ordered;
		$rest = 0;
		if (count($ordered) > $limit) {
			$shown = array_slice($ordered, 0, ($limit - 1));
			$rest = (count($ordered) - count($shown));
		}

		$widgetItems = array_map(fn (QueueItem $item): WidgetItem => $this->widgetItem(item: $item), $shown);

		if ($rest > 0) {
			$widgetItems[] = new WidgetItem(
				$this->l10n->n('%n more in My work', '%n more in My work', $rest),
				'',
				$this->myWorkUrl(),
				$this->iconUrl(),
				'more'
			);
		}

		return $widgetItems;
	}//end items()

	/**
	 * The absolute address of the My work page.
	 *
	 * @return string The address.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
	 */
	public function myWorkUrl(): string {
		return $this->appUrl(path: 'my-work');
	}//end myWorkUrl()

	/**
	 * The absolute address of the app's icon, used for the widget and its items.
	 *
	 * @return string The address.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
	 */
	public function iconUrl(): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));
	}//end iconUrl()

	/**
	 * Order items by due date, soonest first, items without a date last.
	 *
	 * Stable: items with the same date keep the order the queue gave them,
	 * which is its urgency order.
	 *
	 * @param array<int, QueueItem> $items The items.
	 *
	 * @return array<int, QueueItem> The ordered items.
	 */
	private function bySoonestDue(array $items): array {
		$indexed = [];
		foreach (array_values($items) as $position => $item) {
			$indexed[] = ['item' => $item, 'position' => $position, 'due' => $this->dueKey(item: $item)];
		}

		usort(
			$indexed,
			static function (array $left, array $right): int {
				if ($left['due'] === $right['due']) {
					return ($left['position'] <=> $right['position']);
				}

				if ($left['due'] === null) {
					return 1;
				}

				if ($right['due'] === null) {
					return -1;
				}

				return ($left['due'] <=> $right['due']);
			}
		);

		return array_map(static fn (array $entry): QueueItem => $entry['item'], $indexed);
	}//end bySoonestDue()

	/**
	 * A sortable key for an item's due date, or null when it has none.
	 *
	 * @param QueueItem $item The item.
	 *
	 * @return int|null The timestamp, or null.
	 */
	private function dueKey(QueueItem $item): ?int {
		$due = $this->dueDate(item: $item);
		if ($due === null) {
			return null;
		}

		return $due->getTimestamp();
	}//end dueKey()

	/**
	 * An item's due date, or null when it has none or it does not parse.
	 *
	 * @param QueueItem $item The item.
	 *
	 * @return DateTimeImmutable|null The date.
	 */
	private function dueDate(QueueItem $item): ?DateTimeImmutable {
		$raw = trim((string)$item->dueAt);
		if ($raw === '') {
			return null;
		}

		return $this->dates->tryParse(value: $raw);
	}//end dueDate()

	/**
	 * One widget item.
	 *
	 * @param QueueItem $item The queue item.
	 *
	 * @return WidgetItem The widget item.
	 */
	private function widgetItem(QueueItem $item): WidgetItem {
		return new WidgetItem(
			$item->title,
			$this->subtitle(item: $item),
			$this->link(item: $item),
			$this->iconUrl(),
			$item->identity()
		);
	}//end widgetItem()

	/**
	 * What kind of work an item is, and when it is due.
	 *
	 * @param QueueItem $item The item.
	 *
	 * @return string The subtitle.
	 */
	private function subtitle(QueueItem $item): string {
		$kind = match ($item->subjectType) {
			'case' => $this->l10n->t('Case'),
			'task' => $this->l10n->t('Task'),
			default => $this->l10n->t('Work item'),
		};

		$due = $this->dueDate(item: $item);
		if ($due === null) {
			return $kind;
		}

		return $this->l10n->t('%1$s, due %2$s', [$kind, $due->format('d-m-Y')]);
	}//end subtitle()

	/**
	 * The absolute address an item opens.
	 *
	 * @param QueueItem $item The item.
	 *
	 * @return string The address.
	 */
	private function link(QueueItem $item): string {
		$name = (string)($item->route['name'] ?? '');
		$template = (self::ROUTE_PATHS[$name] ?? 'my-work');

		$params = $item->route['params'] ?? [];
		if (is_array($params) === false) {
			$params = [];
		}

		$path = $template;
		if (str_contains($template, '{id}') === true) {
			$id = trim((string)($params['id'] ?? ''));
			if ($id === '') {
				return $this->myWorkUrl();
			}

			$path = str_replace('{id}', rawurlencode($id), $template);
		}

		$query = $item->route['query'] ?? [];
		if (is_array($query) === true && $query !== []) {
			$path .= '?' . http_build_query($query);
		}

		return $this->appUrl(path: $path);
	}//end link()

	/**
	 * An absolute address inside the app.
	 *
	 * @param string $path The path below the app's root, without a leading slash.
	 *
	 * @return string The address.
	 */
	private function appUrl(string $path): string {
		return rtrim($this->url->linkToRouteAbsolute(Application::APP_ID . '.dashboard.page'), '/') . '/' . $path;
	}//end appUrl()
}//end class
