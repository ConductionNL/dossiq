<?php

/**
 * A dashboard widget that opens the app's dashboard page.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Dashboard;

use OCA\Dossiq\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * The icon, link and scripts every dashboard page widget shares; a widget
 * names only its id, title and order.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
abstract class DashboardPageWidget implements IWidget {
	/**
	 * Constructor.
	 *
	 * @param IL10N         $l10n L10N service
	 * @param IURLGenerator $url  URL generator
	 */
	public function __construct(
		protected readonly IL10N $l10n,
		private readonly IURLGenerator $url,
	) {
	}//end __construct()

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
		Util::addScript(Application::APP_ID, Application::APP_ID . '-dashboardWidgets');
		Util::addStyle(Application::APP_ID, 'dashboardWidgets');
	}//end load()
}//end class
