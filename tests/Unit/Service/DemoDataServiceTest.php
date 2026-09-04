<?php

/**
 * DemoDataServiceTest.
 *
 * The failure this service must never have is the quiet one: reporting that
 * demo data was installed on an instance where nothing was written. So the
 * assertions here are about what it refuses to do — skip on a version gate,
 * swallow a missing descriptor, or claim success without OpenRegister.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\DemoDataService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the demo import's decision table.
 */
class DemoDataServiceTest extends TestCase {
	private IAppManager&MockObject $appManager;

	private ContainerInterface&MockObject $container;

	private DemoDataService $service;

	private string $appDir;

	protected function setUp(): void {
		$this->appDir = sys_get_temp_dir() . '/dossiq-demo-' . uniqid();
		mkdir($this->appDir . '/lib/Settings', 0777, true);

		$this->appManager = $this->createMock(IAppManager::class);
		$this->container  = $this->createMock(ContainerInterface::class);

		$this->appManager->method('getAppPath')->willReturn($this->appDir);
		$this->appManager->method('getAppVersion')->willReturn('1.2.3');
		$this->appManager->method('getInstalledApps')->willReturn(['dossiq', 'openregister']);

		$this->service = new DemoDataService(
			$this->appManager,
			$this->container,
			$this->createMock(LoggerInterface::class)
		);
	}

	protected function tearDown(): void {
		$file = $this->appDir . '/lib/Settings/dossiq_mock_register.json';
		if (is_file($file) === true) {
			unlink($file);
		}
		@rmdir($this->appDir . '/lib/Settings');
		@rmdir($this->appDir . '/lib');
		@rmdir($this->appDir);
	}

	private function shipDescriptor(int $objects = 2): void {
		file_put_contents(
			$this->appDir . '/lib/Settings/dossiq_mock_register.json',
			json_encode(
				[
					'x-openregister' => ['type' => 'mock', 'app' => 'dossiq'],
					'components' => [
						'registers' => ['dossiq' => ['schemas' => ['Thing']]],
						'schemas' => ['Thing' => ['type' => 'object']],
						'objects' => array_fill(0, $objects, ['@self' => ['register' => 'dossiq', 'schema' => 'Thing']]),
					],
				]
			)
		);
	}

	/**
	 * A stand-in for OpenRegister's importer that records how it was called.
	 *
	 * 🔴 IT ANSWERS IN THE IMPORTER'S REAL SHAPE, AND THAT IS THE POINT. The
	 * first version of this fake returned `registers` and `schemas` and NO
	 * `objects` key at all, while the assertion below expected five objects.
	 * A fake written from the call site encodes the caller's bug: the only
	 * reason those two agreed is that the service was reading its object count
	 * out of the FILE. `ImportHandler::importFromJson()` returns
	 * `objects: array<ObjectEntity>` — what it created or updated — alongside
	 * `skipped: array{objects: int, ...}` — what it refused.
	 *
	 * @param integer $landed  Objects the importer says it created or updated.
	 * @param integer $refused Objects the importer says it refused.
	 *
	 * @return object The fake.
	 */
	private function importerSpy(int $landed = 2, int $refused = 0, int $unchanged = 0): object {
		return new class($landed, $refused, $unchanged) {
			/** @var array<string, mixed> */
			public array $seen = [];

			/**
			 * @param integer $landed    Objects created or updated.
			 * @param integer $refused   Objects refused.
			 * @param integer $unchanged Objects already present at the same version.
			 */
			public function __construct(
				private readonly int $landed,
				private readonly int $refused,
				private readonly int $unchanged,
			) {
			}

			/**
			 * @param string               $appId   Config identity.
			 * @param array<string, mixed> $data    Descriptor.
			 * @param string               $version App version.
			 * @param boolean              $force   Whether the version gate is bypassed.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->seen = ['appId' => $appId, 'version' => $version, 'force' => $force];
				return [
					'registers' => ['dossiq'],
					'schemas'   => ['Thing'],
					'objects'   => array_fill(0, $this->landed, 'entity'),
					'skipped'   => ['registers' => 0, 'schemas' => 0, 'objects' => $this->refused],
					'unchanged' => ['objects' => $this->unchanged],
				];
			}
		};
	}

	public function testItImportsTheDescriptorAndReportsTheCounts(): void {
		$this->shipDescriptor(objects: 5);
		$spy = $this->importerSpy(landed: 5);
		$this->container->method('get')->willReturn($spy);

		$result = $this->service->install();

		$this->assertSame(5, $result['objects']);
		$this->assertSame(5, $result['requested']);
		$this->assertSame(0, $result['refused']);
		$this->assertSame(1, $result['registers']);
		$this->assertSame(1, $result['schemas']);
	}

	/**
	 * 🔴 THE COUNT IS WHAT LANDED, NOT WHAT WAS ASKED FOR.
	 *
	 * `install()` used to count `components.objects` in the shipped file and
	 * report that number as the import's result, with a comment saying the
	 * number reported is "the number ASKED FOR". So a descriptor carrying 456
	 * objects reported "456 objects" whether the importer stored 456, 3 or
	 * none — and the ten undeclared demo keys of #1782 were stripped on the
	 * way in under exactly that green message. The ask and the landing are two
	 * different numbers and the service must report both.
	 */
	public function testTheObjectCountComesFromTheImporterNotFromTheFile(): void {
		$this->shipDescriptor(objects: 9);
		// The file asks for nine; the importer says it stored three and refused two.
		$this->container->method('get')->willReturn($this->importerSpy(landed: 3, refused: 2));

		$result = $this->service->install();

		$this->assertSame(3, $result['objects'], 'the count must be the importer\'s reply, not the file\'s length');
		$this->assertSame(9, $result['requested']);
		$this->assertSame(2, $result['refused'], 'a refusal is a real outcome and must be counted');
	}

	/**
	 * 🔴 AN IMPORT THAT STORES NOTHING IS NOT A SUCCESS.
	 *
	 * Same shape as the seed steps of #1767 and #1769: a step that touched
	 * nothing reported `success: true` with every counter at zero, was recorded
	 * as done, and was never offered again. With the count read from the file
	 * this state was unreachable — zero landed still printed the file's length
	 * — so there was nothing for a caller to test against.
	 */
	public function testAnImportThatStoresNothingThrowsRatherThanReportingSuccess(): void {
		$this->shipDescriptor(objects: 4);
		$this->container->method('get')->willReturn($this->importerSpy(landed: 0));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/0 of 4/');
		$this->service->install();
	}

	/**
	 * A RE-INSTALL STORES NOTHING AND IS STILL A SUCCESS.
	 *
	 * The setup step's own body tells the operator it is "safe to run more than
	 * once", and an idempotent import necessarily stores zero the second time.
	 * Reading `objects === 0` as failure broke that promise: measured on CI,
	 * dossiq development, every run since 2026-09-03 reported a hard failure on
	 * an install of 444 objects that had nothing left to do.
	 *
	 * `unchanged` is the importer's own count, added in openregister for this,
	 * NOT `requested - stored - refused`. The subtraction looks equivalent and
	 * is not: it reclassifies an object the importer dropped without saying so
	 * as "already present", which is the failure the guard above exists to catch.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	public function testAnImportThatOnlyFoundExistingObjectsIsStillASuccess(): void {
		$this->shipDescriptor(objects: 4);
		$this->container->method('get')->willReturn($this->importerSpy(landed: 0, unchanged: 4));

		$result = $this->service->install();

		$this->assertSame(0, $result['objects'], 'nothing needed storing');
		$this->assertSame(4, $result['unchanged'], 'and the reason is that all four were already there');
	}

	/**
	 * The refusal count is what tells an operator WHY nothing landed, so it has
	 * to survive into the message rather than being folded into a bare zero.
	 */
	public function testWhenEveryObjectIsRefusedTheMessageSaysSo(): void {
		$this->shipDescriptor(objects: 4);
		$this->container->method('get')->willReturn($this->importerSpy(landed: 0, refused: 4));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/4 refused/');
		$this->service->install();
	}

	/**
	 * An importer that answers without an `objects` key has told us nothing
	 * about objects. Reading that absence as "zero landed" is right; reading it
	 * as "as many as the file asked for" is the defect.
	 */
	public function testAnImporterReplyWithNoObjectsKeyCountsAsNothingLanded(): void {
		$this->shipDescriptor(objects: 3);
		$mute = new class {
			/**
			 * @param string               $appId   Config identity.
			 * @param array<string, mixed> $data    Descriptor.
			 * @param string               $version App version.
			 * @param boolean              $force   Whether the version gate is bypassed.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				return ['registers' => ['dossiq'], 'schemas' => ['Thing']];
			}
		};
		$this->container->method('get')->willReturn($mute);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/0 of 3/');
		$this->service->install();
	}

	/**
	 * A descriptor with no objects at all is a different condition from an
	 * import that lost them, and must not be turned into a failure: registers
	 * and schemas are a legitimate thing to ship on their own.
	 */
	public function testADescriptorThatShipsNoObjectsIsNotTreatedAsALostImport(): void {
		$this->shipDescriptor(objects: 0);
		$this->container->method('get')->willReturn($this->importerSpy(landed: 0));

		$result = $this->service->install();

		$this->assertSame(0, $result['objects']);
		$this->assertSame(0, $result['requested']);
	}

	/**
	 * 🔴 THE IMPORT IS FORCED. OpenRegister version-gates a non-forced import
	 * and SKIPS silently when the version has not moved. An operator who asks
	 * for demo data and is told it worked, on an instance where nothing was
	 * written, has been lied to by a version compare.
	 */
	public function testTheImportIsForcedSoAVersionGateCannotSilentlySkipIt(): void {
		$this->shipDescriptor();
		$spy = $this->importerSpy();
		$this->container->method('get')->willReturn($spy);

		$this->service->install();

		$this->assertTrue($spy->seen['force'], 'a version gate must not be able to skip an explicit request');
	}

	/**
	 * Its own configuration identity, so the demo import and the real
	 * configuration import cannot mask one another's version gate.
	 */
	public function testItImportsUnderItsOwnConfigurationIdentity(): void {
		$this->shipDescriptor();
		$spy = $this->importerSpy();
		$this->container->method('get')->willReturn($spy);

		$this->service->install();

		$this->assertSame('dossiq.demo', $spy->seen['appId']);
	}

	public function testAMissingDescriptorThrowsRatherThanReportingSuccess(): void {
		$this->container->expects($this->never())->method('get');

		$this->expectException(RuntimeException::class);
		$this->service->install();
	}

	public function testUnparsableJsonThrowsRatherThanImportingNothing(): void {
		file_put_contents($this->appDir . '/lib/Settings/dossiq_mock_register.json', 'not json');
		$this->container->expects($this->never())->method('get');

		$this->expectException(RuntimeException::class);
		$this->service->install();
	}

	/**
	 * 🔴 NAMES THE MISSING APP. "Something went wrong" on a cross-app lookup
	 * leaves an operator with nothing to act on; a cross-app class is a runtime
	 * lookup that finds nobody rather than erroring usefully.
	 */
	public function testWithoutOpenRegisterItRefusesAndSaysWhichAppIsMissing(): void {
		$this->shipDescriptor();
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($this->appDir);
		$appManager->method('getAppVersion')->willReturn('1.2.3');
		$appManager->method('getInstalledApps')->willReturn(['dossiq']);

		$service = new DemoDataService($appManager, $this->container, $this->createMock(LoggerInterface::class));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/OpenRegister/');
		$service->install();
	}

	public function testIsAvailableReflectsWhetherTheDescriptorShips(): void {
		$this->assertFalse($this->service->isAvailable(), 'no descriptor on disk');
		$this->shipDescriptor();
		$this->assertTrue($this->service->isAvailable());
	}
}
