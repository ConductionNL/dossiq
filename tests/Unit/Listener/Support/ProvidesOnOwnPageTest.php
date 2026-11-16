<?php

/**
 * ProvidesOnOwnPage — initial state only on this app's own pages.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Listener\Support\ProvidesOnOwnPage;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * The provider runs for an own page and not for anything else.
 *
 * @covers \OCA\Dossiq\Listener\Support\ProvidesOnOwnPage
 */
final class ProvidesOnOwnPageTest extends TestCase {

	/**
	 * How often the provider ran for the event.
	 *
	 * @param Event $event The event.
	 *
	 * @return int
	 */
	private function runsFor(Event $event): int {
		$subject = new class() {
			use ProvidesOnOwnPage {
				provideOnOwnPage as public;
			}
		};
		$runs = 0;
		$subject->provideOnOwnPage($event, static function () use (&$runs): void {
			$runs++;
		});

		return $runs;
	}//end runsFor()

	/**
	 * An own logged-in page runs the provider; another app or event does not.
	 *
	 * @return void
	 */
	public function testRunsOnlyOnOwnPages(): void {
		self::assertSame(1, $this->runsFor(new BeforeTemplateRenderedEvent(true, new TemplateResponse(AppIdentity::APP_ID, 'main'))));
		self::assertSame(0, $this->runsFor(new BeforeTemplateRenderedEvent(true, new TemplateResponse('files', 'main'))));
		self::assertSame(0, $this->runsFor(new Event()));
	}//end testRunsOnlyOnOwnPages()
}//end class
