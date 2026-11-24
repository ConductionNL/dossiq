<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\MailCreateDialogListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Dossiq offers its New case dialog on Mail's page, for a signed-in user, and nowhere else.
 *
 * @covers \OCA\Dossiq\Listener\MailCreateDialogListener
 */
class MailCreateDialogListenerTest extends TestCase {

	/**
	 * A page render of an app, signed in or not.
	 *
	 * @param string $app      The app that renders.
	 * @param bool   $loggedIn Whether someone is signed in.
	 *
	 * @return BeforeTemplateRenderedEvent
	 */
	private function render(string $app, bool $loggedIn): BeforeTemplateRenderedEvent {
		return new BeforeTemplateRenderedEvent(loggedIn: $loggedIn, response: new TemplateResponse(appName: $app, templateName: 'main'));
	}//end render()

	/**
	 * Only Mail's page for a signed-in user gets the script.
	 *
	 * @return void
	 */
	public function testOnlyMailsPageForASignedInUser(): void {
		$listener = new MailCreateDialogListener();

		$this->assertTrue(condition: $listener->rendersMailForASignedInUser(event: $this->render(app: 'mail', loggedIn: true)));
		$this->assertFalse(condition: $listener->rendersMailForASignedInUser(event: $this->render(app: 'mail', loggedIn: false)));
		$this->assertFalse(condition: $listener->rendersMailForASignedInUser(event: $this->render(app: 'dossiq', loggedIn: true)));
		$this->assertFalse(condition: $listener->rendersMailForASignedInUser(event: new Event()));
	}//end testOnlyMailsPageForASignedInUser()

	/**
	 * Without the server's script pipeline the listener does nothing, and does not fail.
	 *
	 * @return void
	 */
	public function testItAddsNothingOutsideTheServer(): void {
		(new MailCreateDialogListener())->handle(event: $this->render(app: 'mail', loggedIn: true));
		$this->addToAssertionCount(count: 1);
	}//end testItAddsNothingOutsideTheServer()
}//end class
