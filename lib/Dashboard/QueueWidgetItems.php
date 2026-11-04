<?php

/**
 * The My work widget's links: the My work page and the app icon.
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
 * @spec openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Dashboard;

use OCA\Dossiq\AppInfo\Application;
use OCP\IURLGenerator;

/**
 * Absolute addresses the My work widget links to.
 *
 * @spec openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md
 */
class QueueWidgetItems {
	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $url Builds absolute addresses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md
	 */
	public function __construct(
		private readonly IURLGenerator $url,
	) {
	}//end __construct()

	/**
	 * The absolute address of the My work page.
	 *
	 * @return string The address.
	 *
	 * @spec openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md
	 */
	public function myWorkUrl(): string {
		return $this->appUrl(path: 'my-work');
	}//end myWorkUrl()

	/**
	 * The absolute address of the app's icon, used for the widget and its items.
	 *
	 * @return string The address.
	 *
	 * @spec openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md
	 */
	public function iconUrl(): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));
	}//end iconUrl()

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
