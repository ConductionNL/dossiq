<?php

/**
 * What the My work widget reads besides the queue: its links and today's date.
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
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Dashboard;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCP\IURLGenerator;

/**
 * The My work widget's links, and today on the organisation's calendar.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
class QueueWidgetItems {
	/**
	 * Constructor.
	 *
	 * @param IURLGenerator      $url   Builds absolute addresses.
	 * @param CaseDateNormaliser $dates The organisation's calendar.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function __construct(
		private readonly IURLGenerator $url,
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * The absolute address of the My work page.
	 *
	 * @return string The address.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function myWorkUrl(): string {
		return $this->appUrl(path: 'my-work');
	}//end myWorkUrl()

	/**
	 * The absolute address of the app's icon, used for the widget and its items.
	 *
	 * @return string The address.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function iconUrl(): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(AppIdentity::APP_ID, 'app-dark.svg'));
	}//end iconUrl()

	/**
	 * Today, `Y-m-d`, on the organisation's calendar.
	 *
	 * @return string The date.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function today(): string {
		return $this->dates->todayAsCalendarDate();
	}//end today()

	/**
	 * An absolute address inside the app.
	 *
	 * @param string $path The path below the app's root, without a leading slash.
	 *
	 * @return string The address.
	 */
	private function appUrl(string $path): string {
		return rtrim($this->url->linkToRouteAbsolute(AppIdentity::APP_ID . '.dashboard.page'), '/') . '/' . $path;
	}//end appUrl()
}//end class
