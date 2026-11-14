<?php

/**
 * Unit tests for CaseTimerSweeper: a timer repair visits every matching case
 * in the configured case schema under the system identity, skips itself when
 * OpenRegister or its configuration is missing, and logs instead of failing
 * when the cases cannot be read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use OCA\Dossiq\Repair\Support\CaseTimerSweep;
use OCA\Dossiq\Repair\Support\CaseTimerSweeper;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\Support\CaseTimerSweeper
 *
 * @uses \OCA\Dossiq\Repair\Support\CaseTimerSweep
 * @uses \OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseTimerSweeperTest extends TestCase {

	/**
	 * Settings answering the given availability, object service and config.
	 *
	 * @param array<string, string> $config Config values by key.
	 */
	private function settings(bool $available, ?object $objectService, array $config): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => $config[$key] ?? ''
		);
		return $settings;
	}//end settings()

	/**
	 * An object service answering the given rows, recording searches and
	 * whether each search ran under the system identity.
	 *
	 * @param list<mixed>    $rows  What the search answers.
	 * @param \Throwable|null $fails Thrown by the search instead.
	 */
	private function objectService(array $rows, ?\Throwable $fails = null): object {
		return new class($rows, $fails) {
			public bool $system = false;

			/** @var list<array{string, string, array<string, mixed>, bool}> */
			public array $searches = [];

			public function __construct(private array $rows, private ?\Throwable $fails) {
			}

			public function runAsSystem(callable $work): void {
				$this->system = true;
				$work();
				$this->system = false;
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->searches[] = [$register, $schema, $filters, $this->system];
				if ($this->fails !== null) {
					throw $this->fails;
				}

				return $this->rows;
			}
		};
	}//end objectService()

	/**
	 * A sweep recording every case it is handed.
	 *
	 * @param list<array<string, mixed>> $seen Filled with the visited cases.
	 */
	private function sweep(array &$seen): CaseTimerSweep {
		$sweep = new CaseTimerSweep();
		$sweep->migration = 'arm-timers';
		$sweep->filters = ['status' => 'open'];
		$sweep->each = static function (array $case) use (&$seen): void {
			$seen[] = $case;
		};
		return $sweep;
	}//end sweep()

	public function testEveryMatchingCaseIsVisitedUnderTheSystemIdentity(): void {
		$service = $this->objectService(
			[
				['id' => 'a'],
				new class implements \JsonSerializable {
					public function jsonSerialize(): array {
						return ['id' => 'b'];
					}
				},
				'not a case',
			]
		);
		$logger  = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$sweeper = new CaseTimerSweeper(
			$this->settings(true, $service, ['register' => 'dossiq', 'case_schema' => 'case']),
			$logger
		);

		$seen  = [];
		$sweep = $this->sweep($seen);

		$this->assertTrue($sweeper->sweep($sweep));
		$this->assertSame([['id' => 'a'], ['id' => 'b']], $seen);
		$this->assertSame([['dossiq', 'case', ['status' => 'open'], true]], $service->searches);
		$this->assertSame($service, $sweep->objectService);
		$this->assertSame('dossiq', $sweep->register);
		$this->assertSame('case', $sweep->schema);
	}//end testEveryMatchingCaseIsVisitedUnderTheSystemIdentity()

	public function testNothingRunsWhenOpenRegisterIsUnavailable(): void {
		$service = $this->objectService([['id' => 'a']]);
		$sweeper = new CaseTimerSweeper(
			$this->settings(false, $service, ['register' => 'dossiq', 'case_schema' => 'case']),
			$this->createMock(LoggerInterface::class)
		);

		$seen = [];
		$this->assertFalse($sweeper->sweep($this->sweep($seen)));
		$this->assertSame([], $seen);
		$this->assertSame([], $service->searches);
	}//end testNothingRunsWhenOpenRegisterIsUnavailable()

	public function testNothingRunsWithoutAnObjectService(): void {
		$sweeper = new CaseTimerSweeper(
			$this->settings(true, null, ['register' => 'dossiq', 'case_schema' => 'case']),
			$this->createMock(LoggerInterface::class)
		);

		$seen = [];
		$this->assertFalse($sweeper->sweep($this->sweep($seen)));
		$this->assertSame([], $seen);
	}//end testNothingRunsWithoutAnObjectService()

	public function testNothingRunsWithoutARegister(): void {
		$service = $this->objectService([['id' => 'a']]);
		$sweeper = new CaseTimerSweeper(
			$this->settings(true, $service, ['case_schema' => 'case']),
			$this->createMock(LoggerInterface::class)
		);

		$seen = [];
		$this->assertFalse($sweeper->sweep($this->sweep($seen)));
		$this->assertSame([], $service->searches);
	}//end testNothingRunsWithoutARegister()

	public function testNothingRunsWithoutACaseSchema(): void {
		$service = $this->objectService([['id' => 'a']]);
		$sweeper = new CaseTimerSweeper(
			$this->settings(true, $service, ['register' => 'dossiq']),
			$this->createMock(LoggerInterface::class)
		);

		$seen = [];
		$this->assertFalse($sweeper->sweep($this->sweep($seen)));
		$this->assertSame([], $service->searches);
	}//end testNothingRunsWithoutACaseSchema()

	public function testAnUnreadableCaseListIsLoggedAndVisitsNothing(): void {
		$service = $this->objectService([], new RuntimeException('database gone'));
		$logger  = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Dossiq arm-timers migration: the cases could not be read',
			['error' => 'database gone']
		);
		$sweeper = new CaseTimerSweeper(
			$this->settings(true, $service, ['register' => 'dossiq', 'case_schema' => 'case']),
			$logger
		);

		$seen = [];
		$this->assertTrue($sweeper->sweep($this->sweep($seen)));
		$this->assertSame([], $seen);
	}//end testAnUnreadableCaseListIsLoggedAndVisitsNothing()
}//end class
