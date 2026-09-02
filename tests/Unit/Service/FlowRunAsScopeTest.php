<?php

/**
 * Unit tests for FlowRunAsScope — a flow step's storage work runs as the
 * run's acting identity, or not at all.
 *
 * The behaviour worth protecting is the pair of refusals. A runAs that names
 * nobody usable must STOP the step, never fall back to the ambient session:
 * under FlowRunWorker that session carries no user, and the silent fallback is
 * exactly the anonymous write this class exists to prevent. Every refusal
 * below also asserts the operation NEVER RAN, because a refusal that still
 * performed the work is worse than no refusal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FlowRunAsScope;
use OCA\Dossiq\Service\SettingsService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\FlowRunAsScope
 */
class FlowRunAsScopeTest extends TestCase {

	/**
	 * The uids the object service's runAs seam was asked to act as.
	 *
	 * @var string[]
	 */
	private array $actedAs = [];

	/**
	 * How many times the guarded operation actually ran.
	 *
	 * @var integer
	 */
	private int $operationRuns = 0;

	protected function setUp(): void {
		$this->actedAs = [];
		$this->operationRuns = 0;
	}//end setUp()

	/**
	 * The operation handed to the scope: counts itself and returns a marker.
	 *
	 * @return callable The operation.
	 */
	private function operation(): callable {
		return function (): string {
			$this->operationRuns++;

			return 'operation-result';
		};
	}//end operation()

	/**
	 * A user manager that resolves exactly one uid.
	 *
	 * @param string $uid     The uid that resolves.
	 * @param bool   $enabled Whether that account is enabled.
	 *
	 * @return IUserManager The user manager double.
	 */
	private function users(string $uid, bool $enabled = true): IUserManager {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			static fn (string $asked): ?IUser => ($asked === $uid) ? $user : null
		);

		return $users;
	}//end users()

	/**
	 * Settings resolving to an object service with a recording runAs seam.
	 *
	 * @return SettingsService The settings double.
	 */
	private function settingsWithSeam(): SettingsService {
		$objectService = new class($this->actedAs) {
			public function __construct(private array &$actedAs) {
			}

			public function runAs(IUser $user, callable $operation): mixed {
				$this->actedAs[] = $user->getUID();

				return $operation();
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		return $settings;
	}//end settingsWithSeam()

	/**
	 * A context naming NOBODY runs the operation bare: that is the
	 * interactive path, where the ambient session already answers the
	 * permission checks and no seam is needed.
	 */
	public function testAContextNamingNobodyRunsBare(): void {
		$scope = new FlowRunAsScope($this->settingsWithSeam(), $this->users('admin'));

		$result = $scope->call(context: [], operation: $this->operation());

		self::assertSame('operation-result', $result);
		self::assertSame(1, $this->operationRuns);
		self::assertSame([], $this->actedAs, 'No identity declared means no seam involved.');
	}//end testAContextNamingNobodyRunsBare()

	/**
	 * A blank runAs is the same as no runAs at all.
	 */
	public function testABlankIdentityRunsBare(): void {
		$scope = new FlowRunAsScope($this->settingsWithSeam(), $this->users('admin'));

		$result = $scope->call(context: ['runAs' => '   '], operation: $this->operation());

		self::assertSame('operation-result', $result);
		self::assertSame([], $this->actedAs);
	}//end testABlankIdentityRunsBare()

	/**
	 * 🔴 A named identity is obeyed: the operation runs through the object
	 * service's runAs seam, as that user, and its result passes through.
	 */
	public function testANamedIdentityRunsThroughTheSeam(): void {
		$scope = new FlowRunAsScope($this->settingsWithSeam(), $this->users('admin'));

		$result = $scope->call(context: ['runAs' => 'admin'], operation: $this->operation());

		self::assertSame('operation-result', $result);
		self::assertSame(1, $this->operationRuns);
		self::assertSame(['admin'], $this->actedAs);
	}//end testANamedIdentityRunsThroughTheSeam()

	/**
	 * An identity that resolves to no account refuses, and the operation
	 * never runs.
	 */
	public function testAnUnknownIdentityRefuses(): void {
		$scope = new FlowRunAsScope($this->settingsWithSeam(), $this->users('admin'));

		try {
			$scope->call(context: ['runAs' => 'ghost'], operation: $this->operation());
			self::fail('An identity nobody answers to must refuse.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('not a user account', $e->getMessage());
		}

		self::assertSame(0, $this->operationRuns, 'The refusal must come BEFORE the work.');
		self::assertSame([], $this->actedAs);
	}//end testAnUnknownIdentityRefuses()

	/**
	 * 🔴 A DISABLED account refuses. Disabling is the most common revocation
	 * there is, and a run parked for weeks must not resume with the rights of
	 * somebody who has since been offboarded.
	 */
	public function testADisabledIdentityRefuses(): void {
		$scope = new FlowRunAsScope($this->settingsWithSeam(), $this->users('admin', enabled: false));

		try {
			$scope->call(context: ['runAs' => 'admin'], operation: $this->operation());
			self::fail('A disabled account must refuse.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('disabled', $e->getMessage());
		}

		self::assertSame(0, $this->operationRuns);
	}//end testADisabledIdentityRefuses()

	/**
	 * No object service at all: the run DECLARES an identity this
	 * installation cannot honour, so the step refuses rather than running
	 * bare as whoever the ambient session happens to carry.
	 */
	public function testAMissingObjectServiceRefusesADeclaredIdentity(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$scope = new FlowRunAsScope($settings, $this->users('admin'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('cannot scope one');

		try {
			$scope->call(context: ['runAs' => 'admin'], operation: $this->operation());
		} finally {
			self::assertSame(0, $this->operationRuns);
		}
	}//end testAMissingObjectServiceRefusesADeclaredIdentity()

	/**
	 * An object service WITHOUT the runAs seam refuses the same way: running
	 * bare would perform the write as nobody under a worker, silently.
	 */
	public function testAnObjectServiceWithoutTheSeamRefusesADeclaredIdentity(): void {
		$objectService = new class {
			// Deliberately no runAs(): an object service predating the seam.
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$scope = new FlowRunAsScope($settings, $this->users('admin'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('cannot scope one');

		try {
			$scope->call(context: ['runAs' => 'admin'], operation: $this->operation());
		} finally {
			self::assertSame(0, $this->operationRuns);
		}
	}//end testAnObjectServiceWithoutTheSeamRefusesADeclaredIdentity()
}//end class
