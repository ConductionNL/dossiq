<?php

/**
 * My open work dashboard widget.
 *
 * Lists the signed-in person's open cases and work items from the one personal
 * queue, through Nextcloud's widget item API, so any host that reads items
 * (the Nextcloud dashboard, the mobile apps, a start page built on them)
 * renders it without dossiq's JavaScript.
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

use OCA\Dossiq\Service\Queue\PersonalQueueService;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;

/**
 * The person's open work, soonest due first, with a button to My work.
 *
 * It reads the queue through the same service the My work page uses, so the
 * widget and the page cannot disagree about what is open.
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
 */
class MyOpenWorkWidget implements IButtonWidget, IIconWidget, IReloadableWidget {

	/**
	 * How often a host reloads the items, in seconds.
	 *
	 * 300 rather than the default 60: the queue reads eleven sources, and the
	 * widget endpoint is called by every open start page.
	 */
	private const RELOAD_SECONDS = 300;

	/**
	 * Constructor.
	 *
	 * @param IL10N                $l10n  Translations.
	 * @param PersonalQueueService $queue The one personal queue.
	 * @param QueueWidgetItems     $items Maps queue items onto widget items.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly PersonalQueueService $queue,
		private readonly QueueWidgetItems $items,
	) {
	}//end __construct()

	/**
	 * The widget's id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
	 */
	public function getId(): string {
		// A new widget, so it takes the app's current id. The seven older ones
		// stay frozen at the old prefix, see CasesOverviewWidget::getId().
		return 'dossiq_my_open_work_widget';
	}//end getId()

	/**
	 * The widget's title.
	 *
	 * @return string The title.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
	 */
	public function getTitle(): string {
		return $this->l10n->t('My open work');
	}//end getTitle()

	/**
	 * The widget's place among the others.
	 *
	 * @return int The order.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
	 */
	public function getOrder(): int {
		return 11;
	}//end getOrder()

	/**
	 * The widget's icon class.
	 *
	 * @return string The class.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
	 */
	public function getIconClass(): string {
		return 'icon-dossiq-widget';
	}//end getIconClass()

	/**
	 * The widget's icon as an address, for hosts that draw no CSS class.
	 *
	 * @return string The address.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
	 */
	public function getIconUrl(): string {
		return $this->items->iconUrl();
	}//end getIconUrl()

	/**
	 * Where the widget's title leads: the My work page.
	 *
	 * @return string|null The address.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
	 */
	public function getUrl(): ?string {
		return $this->items->myWorkUrl();
	}//end getUrl()

	/**
	 * Nothing to load: the host renders the items itself.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
	 */
	public function load(): void {
	}//end load()

	/**
	 * The person's open work as widget items.
	 *
	 * A queue source that cannot be read is left out by the queue service,
	 * which names it as unavailable; the widget shows what the others answer.
	 *
	 * @param string      $userId The person.
	 * @param string|null $since  Not used: the queue has no "since" cursor.
	 * @param int         $limit  The most items the host shows.
	 *
	 * @return WidgetItems The items.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $since is part of the interface.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
	 */
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$queue = $this->queue->forPerson(userId: $userId);
		$queueItems = [];
		$rows = ($queue['items'] ?? []);
		if (is_array($rows) === true) {
			foreach ($rows as $item) {
				if ($item instanceof QueueItem) {
					$queueItems[] = $item;
				}
			}
		}

		return new WidgetItems(
			$this->items->items(queueItems: $queueItems, limit: $limit),
			$this->l10n->t('Nothing waiting for you.')
		);
	}//end getItemsV2()

	/**
	 * The one button: Open my work.
	 *
	 * @param string $userId The person.
	 *
	 * @return list<WidgetButton> The buttons.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $userId is part of the interface.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
	 */
	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(WidgetButton::TYPE_MORE, $this->items->myWorkUrl(), $this->l10n->t('Open my work')),
		];
	}//end getWidgetButtons()

	/**
	 * How often a host reloads the items.
	 *
	 * @return int Seconds.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-023
	 */
	public function getReloadInterval(): int {
		return self::RELOAD_SECONDS;
	}//end getReloadInterval()
}//end class
