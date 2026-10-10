<?php

/**
 * Unit tests for the Woo reports initial state listener.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\AppInfo\Registrar\WooListenerRegistrar;
use OCA\Dossiq\Listener\WooReportsInitialStateListener;
use OCA\Dossiq\Woo\WooReportSwitches;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Dossiq's page gets `woo_reports`, offering the throughput only to a reader while it is on.
 *
 * @covers \OCA\Dossiq\Listener\WooReportsInitialStateListener
 * @covers \OCA\Dossiq\AppInfo\Registrar\WooListenerRegistrar
 * @covers \OCA\Dossiq\Woo\WooReportSwitches
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
 */
class WooReportsInitialStateListenerTest extends TestCase {

	/**
	 * Handle a real event and answer the provided state.
	 *
	 * @param array<string, string> $stored The stored app config.
	 * @param string|null $uid The signed-in user.
	 * @param Event|null $event The event, or dossiq's logged-in page.
	 *
	 * @return mixed The provided state, or 'not provided'.
	 */
	private function provided(array $stored, ?string $uid, ?Event $event = null): mixed {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid === 'woo-leiding');
		$groups->method('isInGroup')->willReturnCallback(static fn (string $u, string $g): bool => $u === 'lead' && $g === 'woo-leiding');
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$state = [];
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			static function (string $key, mixed $value) use (&$state): void {
				$state[$key] = $value;
			}
		);

		$listener = new WooReportsInitialStateListener(
			initialState: $initialState,
			switches: new WooReportSwitches(appConfig: $appConfig, groupManager: $groups, userSession: $session),
		);
		$listener->handle($event ?? new BeforeTemplateRenderedEvent(true, new TemplateResponse('dossiq', 'index')));

		return $state['woo_reports'] ?? 'not provided';
	}//end provided()

	/**
	 * Off, a non-member or signed out: nothing offered. On and a member: offered.
	 *
	 * @return void
	 */
	public function testOnlyAReaderIsOfferedTheThroughputWhileItIsOn(): void {
		$on = [WooReportSwitches::THROUGHPUT => 'true', WooReportSwitches::READERS => 'woo-leiding'];

		$this->assertSame(['throughput' => false], $this->provided(stored: [], uid: 'lead'));
		$this->assertSame(['throughput' => false], $this->provided(stored: $on, uid: 'reviewer-a'));
		$this->assertSame(['throughput' => false], $this->provided(stored: $on, uid: null));
		$this->assertSame(['throughput' => true], $this->provided(stored: $on, uid: 'lead'));
	}//end testOnlyAReaderIsOfferedTheThroughputWhileItIsOn()

	/**
	 * Another app's page, a logged-out page or another event gets nothing.
	 *
	 * @return void
	 */
	public function testOnlyDossiqsOwnLoggedInPageIsTouched(): void {
		$on = [WooReportSwitches::THROUGHPUT => 'true', WooReportSwitches::READERS => 'woo-leiding'];

		$this->assertSame('not provided', $this->provided(stored: $on, uid: 'lead', event: new BeforeTemplateRenderedEvent(true, new TemplateResponse('files', 'index'))));
		$this->assertSame('not provided', $this->provided(stored: $on, uid: 'lead', event: new BeforeTemplateRenderedEvent(false, new TemplateResponse('dossiq', 'index'))));
		$this->assertSame('not provided', $this->provided(stored: $on, uid: 'lead', event: new Event()));
	}//end testOnlyDossiqsOwnLoggedInPageIsTouched()

	/**
	 * The registrar binds the listener to the template event.
	 *
	 * @return void
	 */
	public function testTheRegistrarBindsTheListener(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())->method('registerEventListener')
			->with(BeforeTemplateRenderedEvent::class, WooReportsInitialStateListener::class);

		(new WooListenerRegistrar())->register(context: $context);
	}//end testTheRegistrarBindsTheListener()
}//end class
