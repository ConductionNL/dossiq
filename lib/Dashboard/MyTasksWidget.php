<?php

/**
 * My Tasks Dashboard Widget
 *
 * Displays tasks assigned to the current user in the Nextcloud Dashboard.
 *
 * @category Dashboard
 * @package  OCA\Dossiq\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-procest/tasks.md#task-5
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Dashboard;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Queue\Source\EngineTaskSource;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dashboard widget showing tasks assigned to the current user.
 *
 * The Nextcloud dashboard still mounts the widget's own bundle through
 * `load()`. A host that reads items instead (the dashboard item API, the
 * mobile apps, a start page) gets the same open tasks from `getItemsV2()`.
 *
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-024
 */
class MyTasksWidget implements IAPIWidgetV2 {
	/**
	 * Constructor.
	 *
	 * @param IL10N            $l10n   L10N service
	 * @param IURLGenerator    $url    URL generator
	 * @param EngineTaskSource $tasks  The engine's open tasks, as the personal queue reads them.
	 * @param QueueWidgetItems $items  Maps queue items onto widget items.
	 * @param LoggerInterface  $logger Logger.
	 */
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
		private EngineTaskSource $tasks,
		private QueueWidgetItems $items,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the unique identifier for this widget.
	 *
	 * @inheritDoc
	 * @return string The widget identifier
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getId(): string {
		// FROZEN at the old app-id prefix — see CasesOverviewWidget::getId()
		// for why: Nextcloud's Dashboard app stores each user's chosen widgets
		// by id in its OWN app namespace, which this app's MigrateUserPreferences
		// cannot reach, so a renamed id silently drops the widget from every
		// dashboard that had it.
		return 'procest_my_tasks_widget';
	}//end getId()

	/**
	 * Get the display title for this widget.
	 *
	 * @inheritDoc
	 * @return string The widget title
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getTitle(): string {
		return $this->l10n->t('My Tasks');
	}//end getTitle()

	/**
	 * Get the display order for this widget.
	 *
	 * @inheritDoc
	 * @return int The widget order
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getOrder(): int {
		return 12;
	}//end getOrder()

	/**
	 * Get the CSS icon class for this widget.
	 *
	 * @inheritDoc
	 * @return string The icon CSS class
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getIconClass(): string {
		return 'icon-dossiq-widget';
	}//end getIconClass()

	/**
	 * Get the URL for the widget's full view.
	 *
	 * @inheritDoc
	 * @return string|null The widget URL or null
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getUrl(): ?string {
		return $this->url->linkToRouteAbsolute(Application::APP_ID . '.dashboard.page');
	}//end getUrl()

	/**
	 * Load the widget scripts and styles.
	 *
	 * @inheritDoc
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) — Nextcloud Util API is static by design
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function load(): void {
		// Shared vendor chunks emitted by webpack splitChunks (see webpack.config.js).
		Util::addScript(Application::APP_ID, Application::APP_ID . '-shared-vendor');
		Util::addScript(Application::APP_ID, Application::APP_ID . '-shared-nc-vue');
		Util::addScript(Application::APP_ID, Application::APP_ID . '-myTasksWidget');
		Util::addStyle(Application::APP_ID, 'dashboardWidgets');

	}//end load()

	/**
	 * The person's open tasks as widget items, soonest due first.
	 *
	 * When the engine cannot be read the list is empty and SAYS so: "no tasks"
	 * would be a claim about the reader's day that nobody checked.
	 *
	 * @param string      $userId The person.
	 * @param string|null $since  Not used: the engine has no "since" cursor.
	 * @param int         $limit  The most items the host shows.
	 *
	 * @return WidgetItems The items.
	 *
	 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-024
	 */
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		// The interface passes a cursor this widget has no use for.
		unset($since);
		try {
			$open = $this->tasks->itemsFor(userId: $userId);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the My tasks widget could not read the engine: ' . $e->getMessage());
			return new WidgetItems([], $this->l10n->t('Your tasks could not be read just now.'));
		}

		return new WidgetItems(
			$this->items->items(queueItems: $open, limit: $limit),
			$this->l10n->t('No open tasks.')
		);
	}//end getItemsV2()
}//end class
