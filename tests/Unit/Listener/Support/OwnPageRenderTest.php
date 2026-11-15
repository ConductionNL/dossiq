<?php

/**
 * OwnPageRender — dossiq's own logged-in page render, and nothing else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Listener\Support\OwnPageRender;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Which renders match.
 *
 * @covers \OCA\Dossiq\Listener\Support\OwnPageRender
 */
final class OwnPageRenderTest extends TestCase {

	/**
	 * A render of the given app's page.
	 *
	 * @param string $app      The app the page belongs to.
	 * @param bool   $loggedIn Whether a user is signed in.
	 *
	 * @return BeforeTemplateRenderedEvent
	 */
	private function render(string $app, bool $loggedIn): BeforeTemplateRenderedEvent {
		return new BeforeTemplateRenderedEvent($loggedIn, new TemplateResponse($app, 'main'));
	}//end render()

	/**
	 * Only dossiq's own page for a signed-in user matches.
	 *
	 * @return void
	 */
	public function testOnlyTheOwnLoggedInPageMatches(): void {
		$own = new OwnPageRender();

		self::assertTrue($own->matches(event: $this->render(app: AppIdentity::APP_ID, loggedIn: true)));
		self::assertFalse($own->matches(event: $this->render(app: AppIdentity::APP_ID, loggedIn: false)));
		self::assertFalse($own->matches(event: $this->render(app: 'files', loggedIn: true)));
		self::assertFalse($own->matches(event: new Event()));
	}//end testOnlyTheOwnLoggedInPageMatches()
}//end class
