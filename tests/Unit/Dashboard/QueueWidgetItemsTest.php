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
 * @spec openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Dashboard;

use OCA\Dossiq\Dashboard\QueueWidgetItems;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The My work widget's links.
 *
 * @covers \OCA\Dossiq\Dashboard\QueueWidgetItems
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 */
class QueueWidgetItemsTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * My work and the icon are absolute addresses under the app; today is the organisation's.
	 *
	 * @return void
	 */
	public function testLinksToMyWorkAndTheAppIcon(): void {
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route): string => ($route === 'dossiq.dashboard.page') ? 'https://cloud.example/apps/dossiq/' : 'https://cloud.example/wrong'
		);
		$url->method('imagePath')->willReturn('/apps/dossiq/img/app-dark.svg');
		$url->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		$links = new QueueWidgetItems(url: $url, dates: $this->caseDatesFrozenAt(instant: '2026-10-12T23:30:00+02:00'));

		$this->assertSame(expected: 'https://cloud.example/apps/dossiq/my-work', actual: $links->myWorkUrl());
		$this->assertSame(expected: 'https://cloud.example/apps/dossiq/img/app-dark.svg', actual: $links->iconUrl());
		$this->assertSame(expected: '2026-10-12', actual: $links->today());
	}//end testLinksToMyWorkAndTheAppIcon()
}//end class
