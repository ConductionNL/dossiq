<?php

/**
 * Unit tests for DashboardWidgetScope::narrowFor(): a dashboard payload loses
 * the figures of every role-bound widget the reader may not see, a widget
 * whose role no group answers to is hidden from everyone and reported, and a
 * missing manifest scopes nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Dashboard;

use OCA\Dossiq\Service\Dashboard\DashboardWidgetScope;
use OCA\Dossiq\Service\Dashboard\WidgetRoles;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Dashboard\DashboardWidgetScope
 *
 * @uses \OCA\Dossiq\Service\Dashboard\WidgetRoles
 */
class DashboardWidgetScopeNarrowTest extends TestCase {

	private const PAYLOAD = ['openCases' => 12, 'revenue' => 5000, 'budget' => 8000, 'legacy' => 3, 'other' => 'x'];

	private string $root;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/dossiq-scope-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/src', 0777, true);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	protected function tearDown(): void {
		@unlink($this->root . '/src/manifest.json');
		@rmdir($this->root . '/src');
		@rmdir($this->root);
		parent::tearDown();
	}//end tearDown()

	private function writeManifest(string $json): void {
		file_put_contents($this->root . '/src/manifest.json', $json);
	}//end writeManifest()

	private function dashboard(): string {
		return (string)json_encode(
			[
				'pages' => [
					[
						'type' => 'dashboard',
						'config' => [
							'widgets' => [
								['id' => 'w-open', 'content' => ['valueField' => 'openCases']],
								['id' => 'w-finance', 'roles' => ['finance'], 'content' => ['valueField' => 'revenue', 'caption' => '{revenue} of {budget}']],
								['id' => 'w-legacy', 'roles' => ['renamed-role'], 'content' => ['valueField' => 'legacy']],
							],
						],
					],
				],
			]
		);
	}//end dashboard()

	/**
	 * A scope where the given users hold the given groups, and finance and admin exist.
	 *
	 * @param array<string, list<string>> $memberships Group ids by user id.
	 */
	private function scope(array $memberships): DashboardWidgetScope {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid) use ($memberships): ?IUser {
				if (isset($memberships[$uid]) === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			}
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(static fn (IUser $user): array => $memberships[$user->getUID()]);
		$groups->method('search')->with('')->willReturnCallback(
			function (): array {
				return array_map(
					function (string $gid): IGroup {
						$group = $this->createMock(IGroup::class);
						$group->method('getGID')->willReturn($gid);
						return $group;
					},
					['finance', 'admin']
				);
			}
		);

		return new DashboardWidgetScope(new WidgetRoles(), $groups, $users, $this->logger, $this->root);
	}//end scope()

	public function testAMemberKeepsTheFiguresOfTheirWidgets(): void {
		$this->writeManifest($this->dashboard());

		$this->assertSame(
			['openCases' => 12, 'revenue' => 5000, 'budget' => 8000, 'other' => 'x'],
			$this->scope(['bob' => ['finance']])->narrowFor(self::PAYLOAD, 'bob')
		);
	}//end testAMemberKeepsTheFiguresOfTheirWidgets()

	public function testANonMemberUnknownOrAnonymousReaderLosesThem(): void {
		$this->writeManifest($this->dashboard());
		$scope = $this->scope(['alice' => ['admin']]);
		$expected = ['openCases' => 12, 'other' => 'x'];

		$this->assertSame($expected, $scope->narrowFor(self::PAYLOAD, 'alice'));
		$this->assertSame($expected, $scope->narrowFor(self::PAYLOAD, 'ghost'));
		$this->assertSame($expected, $scope->narrowFor(self::PAYLOAD, ''));
	}//end testANonMemberUnknownOrAnonymousReaderLosesThem()

	public function testAnUnresolvableRoleIsReported(): void {
		$this->writeManifest($this->dashboard());
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq dashboard: a widget declares a role no group answers to, so it is hidden from everyone',
			['widget' => 'w-legacy']
		);

		$this->scope(['bob' => ['finance']])->narrowFor(self::PAYLOAD, 'bob');
	}//end testAnUnresolvableRoleIsReported()

	public function testTheManifestIsReadOnce(): void {
		$this->writeManifest($this->dashboard());
		$scope = $this->scope(['alice' => []]);
		$scope->narrowFor(self::PAYLOAD, 'alice');
		unlink($this->root . '/src/manifest.json');

		$this->assertSame(['openCases' => 12, 'other' => 'x'], $scope->narrowFor(self::PAYLOAD, 'alice'));
	}//end testTheManifestIsReadOnce()

	public function testAMissingManifestScopesNothingAndSaysSo(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq dashboard: the manifest could not be read, so no widget scoping is applied',
			['path' => $this->root . '/src/manifest.json']
		);
		$scope = $this->scope(['alice' => []]);

		$this->assertSame(self::PAYLOAD, $scope->narrowFor(self::PAYLOAD, 'alice'));
		$this->assertSame([], $scope->visibilityFor('alice'));
	}//end testAMissingManifestScopesNothingAndSaysSo()

	public function testAManifestThatIsNoJsonObjectScopesNothing(): void {
		$this->writeManifest('not json');
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(self::PAYLOAD, $this->scope(['alice' => []])->narrowFor(self::PAYLOAD, 'alice'));
	}//end testAManifestThatIsNoJsonObjectScopesNothing()
}//end class
