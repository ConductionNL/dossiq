<?php

/**
 * Dossiq RolePermissionsInitialStateListener test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\RolePermissionsInitialStateListener;
use OCA\Dossiq\Service\Access\RolePermissions;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Dossiq's own logged-in pages get the current user's role permissions.
 *
 * @covers \OCA\Dossiq\Listener\RolePermissionsInitialStateListener
 */
final class RolePermissionsInitialStateListenerTest extends TestCase {

	/**
	 * What the listener provides for one page render.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>
	 */
	private function provided(Event $event): array {
		$provided = [];
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			static function (string $key, mixed $data) use (&$provided): void {
				$provided[$key] = $data;
			}
		);
		$roles = $this->createMock(RolePermissions::class);
		$roles->method('forCurrentUser')->willReturn(['case.create' => true, 'case.delete' => false]);

		(new RolePermissionsInitialStateListener(initialState: $initialState, roles: $roles))->handle(event: $event);

		return $provided;
	}//end provided()

	/**
	 * A render event.
	 *
	 * @param string  $app      The response's app.
	 * @param boolean $loggedIn Whether a user is logged in.
	 *
	 * @return BeforeTemplateRenderedEvent
	 */
	private function rendered(string $app, bool $loggedIn): BeforeTemplateRenderedEvent {
		$response = $this->createMock(TemplateResponse::class);
		$response->method('getApp')->willReturn($app);
		$event = $this->createMock(BeforeTemplateRenderedEvent::class);
		$event->method('isLoggedIn')->willReturn($loggedIn);
		$event->method('getResponse')->willReturn($response);

		return $event;
	}//end rendered()

	/**
	 * Dossiq's page gets the permissions under the state key.
	 *
	 * @return void
	 */
	public function testDossiqsPageGetsThePermissions(): void {
		$this->assertSame(
			['role_permissions' => ['case.create' => true, 'case.delete' => false]],
			$this->provided($this->rendered(app: 'dossiq', loggedIn: true))
		);
	}//end testDossiqsPageGetsThePermissions()

	/**
	 * Another app's page, an anonymous render or another event gets nothing.
	 *
	 * @return void
	 */
	public function testEverythingElseGetsNothing(): void {
		$this->assertSame([], $this->provided($this->rendered(app: 'files', loggedIn: true)));
		$this->assertSame([], $this->provided($this->rendered(app: 'dossiq', loggedIn: false)));
		$this->assertSame([], $this->provided(new Event()));
	}//end testEverythingElseGetsNothing()
}//end class
