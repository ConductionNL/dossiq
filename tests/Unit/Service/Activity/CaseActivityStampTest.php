<?php

/**
 * The stamp writes only the activity date, and only onto a case.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Activity
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
 * @spec openspec/specs/case-last-activity-at/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Activity;

use DateTimeImmutable;
use OCA\Dossiq\Service\Activity\CaseActivityStamp;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the case activity stamp.
 *
 * @covers \OCA\Dossiq\Service\Activity\CaseActivityStamp
 */
class CaseActivityStampTest extends TestCase {

	/**
	 * The patches the object service received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $patches = [];

	/**
	 * A case is patched with the current time and nothing else.
	 *
	 * @return void
	 */
	public function testACaseIsPatchedWithOnlyItsActivityDate(): void {
		self::assertTrue(condition: $this->stamp()->stamp(caseId: 'case-1'));

		self::assertSame(
			expected: [['objectId' => 'case-1', 'data' => ['lastActivityAt' => '2026-10-26T14:30:00+00:00'], 'register' => 'dossiq', 'schema' => 'case']],
			actual: $this->patches
		);
	}//end testACaseIsPatchedWithOnlyItsActivityDate()

	/**
	 * An id that is not a case (a note on another object) writes nothing.
	 *
	 * @return void
	 */
	public function testAnIdThatIsNoCaseWritesNothing(): void {
		self::assertFalse(condition: $this->stamp()->stamp(caseId: 'not-a-case'));
		self::assertFalse(condition: $this->stamp()->stamp(caseId: ''));

		self::assertSame(expected: [], actual: $this->patches);
	}//end testAnIdThatIsNoCaseWritesNothing()

	/**
	 * The stamp over an object service that knows one case.
	 *
	 * @return CaseActivityStamp The stamp.
	 */
	private function stamp(): CaseActivityStamp {
		$objects = new class($this) {
			/**
			 * Constructor.
			 *
			 * @param CaseActivityStampTest $test Collects the patches.
			 */
			public function __construct(private CaseActivityStampTest $test) {
			}

			/**
			 * The one known case, or no object.
			 *
			 * @param string $id       The object id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed> The case.
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): array {
				if ($id !== 'case-1') {
					throw new DoesNotExistException('no such object');
				}

				return ['id' => 'case-1', 'status' => 'st-1'];
			}

			/**
			 * Record the patch.
			 *
			 * @param string               $objectId The object id.
			 * @param array<string, mixed> $data     The fields.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed> The patched object.
			 */
			public function patchObject(string $objectId, array $data, mixed $register = null, mixed $schema = null): array {
				$this->test->patches[] = compact('objectId', 'data', 'register', 'schema');
				return array_merge(['id' => $objectId], $data);
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
			['case_schema', '', 'case'],
		]);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-10-26T14:30:00+00:00'));

		return new CaseActivityStamp(
			settingsService: $settings,
			time: $time,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end stamp()

	/**
	 * A stamp over the given object service and register configuration.
	 *
	 * @param object|null     $objects  The object service, or null when OpenRegister is absent.
	 * @param string          $register The configured register.
	 * @param LoggerInterface $logger   The logger.
	 *
	 * @return CaseActivityStamp The stamp.
	 */
	private function stampOver(?object $objects, string $register, LoggerInterface $logger): CaseActivityStamp {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnMap([
			['register', '', $register],
			['case_schema', '', 'case'],
		]);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-10-26T14:30:00+00:00'));

		return new CaseActivityStamp(settingsService: $settings, time: $time, logger: $logger);
	}//end stampOver()

	/**
	 * A patch OpenRegister refuses is logged with the case and answers false.
	 *
	 * @return void
	 */
	public function testARefusedPatchIsLoggedAndAnswersFalse(): void {
		$objects = new class {
			/**
			 * The case exists.
			 *
			 * @param string $id       The object id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed> The case.
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): array {
				return ['id' => $id];
			}

			/**
			 * Refuse the patch.
			 *
			 * @param string               $objectId The object id.
			 * @param array<string, mixed> $data     The fields.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(string $objectId, array $data, mixed $register = null, mixed $schema = null): array {
				throw new RuntimeException('locked');
			}
		};

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'Dossiq: the case activity date could not be written',
				['case' => 'case-1', 'error' => 'locked']
			);

		self::assertFalse(condition: $this->stampOver(objects: $objects, register: 'dossiq', logger: $logger)->stamp(caseId: ' case-1 '));
	}//end testARefusedPatchIsLoggedAndAnswersFalse()

	/**
	 * Without OpenRegister or a configured register nothing is read or written.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredInstallWritesNothing(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		self::assertFalse(condition: $this->stampOver(objects: null, register: 'dossiq', logger: $logger)->stamp(caseId: 'case-1'));

		$untouchable = new class {
			/**
			 * Must not be reached.
			 *
			 * @return array<string, mixed>
			 */
			public function find(): array {
				throw new RuntimeException('no read expected');
			}
		};
		self::assertFalse(condition: $this->stampOver(objects: $untouchable, register: '', logger: $logger)->stamp(caseId: 'case-1'));
	}//end testAnUnconfiguredInstallWritesNothing()
}//end class
