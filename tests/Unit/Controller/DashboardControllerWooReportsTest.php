<?php

/**
 * The page tells the menu which Woo review reports this user is offered.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DashboardController;
use OCA\Dossiq\Woo\WooReportSwitches;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The `woo_reports` initial state offers the throughput screen only to a reader while it is on.
 *
 * @covers \OCA\Dossiq\Controller\DashboardController
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-both-reports-are-opt-in-per-organisation-off-by-default-req-wrr-001
 */
final class DashboardControllerWooReportsTest extends TestCase {

	/**
	 * Render the page and answer the `woo_reports` state it provided.
	 *
	 * @param array<string, string> $stored The stored app config.
	 * @param string|null $uid The signed-in user.
	 * @param bool $withSwitches Whether DI handed the switches in.
	 *
	 * @return mixed The provided state.
	 */
	private function provided(array $stored, ?string $uid, bool $withSwitches = true): mixed {
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

		$switches = null;
		if ($withSwitches === true) {
			$switches = new WooReportSwitches(appConfig: $appConfig, groupManager: $groups);
		}

		$controller = new DashboardController(
			$this->createMock(IRequest::class),
			$initialState,
			$appConfig,
			$this->createMock(IEventDispatcher::class),
			$switches,
			$session,
		);
		$controller->page();

		return $state['woo_reports'] ?? 'not provided';
	}//end provided()

	/**
	 * Off, a non-member, no user or no switches: nothing offered. On and a member: offered.
	 *
	 * @return void
	 */
	public function testOnlyAReaderIsOfferedTheThroughputWhileItIsOn(): void {
		$on = [WooReportSwitches::THROUGHPUT => 'true', WooReportSwitches::READERS => 'woo-leiding'];

		$this->assertSame(['throughput' => false], $this->provided(stored: [], uid: 'lead'));
		$this->assertSame(['throughput' => false], $this->provided(stored: $on, uid: 'reviewer-a'));
		$this->assertSame(['throughput' => false], $this->provided(stored: $on, uid: null));
		$this->assertSame(['throughput' => false], $this->provided(stored: $on, uid: 'lead', withSwitches: false));
		$this->assertSame(['throughput' => true], $this->provided(stored: $on, uid: 'lead'));
	}//end testOnlyAReaderIsOfferedTheThroughputWhileItIsOn()
}//end class
