<?php

/**
 * The day a case first reached each status, read from its status records.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseStageDatesService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * reachedDatesFor() over a settings double: the search, the fold, the case's
 * creation day for the first status, and every missing-configuration exit.
 *
 * @covers \OCA\Dossiq\Service\CaseStageDatesService
 */
class CaseStageDatesServiceReachedTest extends TestCase {

	/**
	 * An object service double: status records by slug search, the case by find().
	 *
	 * @param array<int, mixed> $records The status records.
	 * @param mixed $case What find() returns.
	 *
	 * @return object
	 */
	private static function objectService(array $records, mixed $case = null): object {
		return new class($records, $case) {
			/** @var list<array<int, mixed>> */
			public array $calls = [];

			/**
			 * @param array<int, mixed> $records
			 */
			public function __construct(private array $records, private mixed $case) {
			}

			/**
			 * @param array<string, mixed> $filters
			 *
			 * @return array<int, mixed>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->calls[] = ['search', $register, $schema, $filters];

				return $this->records;
			}

			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$register = $options?->register;
				$schema = $options?->schema;
				$this->calls[] = ['find', $id, $register, $schema];

				return $this->case;
			}
		};
	}//end objectService()

	/**
	 * The service over a settings double.
	 *
	 * @param object|null $objectService The object service.
	 * @param array<string, string> $config Config values by key.
	 *
	 * @return CaseStageDatesService
	 */
	private function service(?object $objectService, array $config = ['register' => 'dossiq', 'status_record_schema' => 'statusRecord', 'case_schema' => 'case']): CaseStageDatesService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => $config[$key] ?? $default);

		return new CaseStageDatesService($settings);
	}//end service()

	/**
	 * The records are searched by case with the record cap, and the earliest day per status wins.
	 *
	 * @return void
	 */
	public function testTheEarliestDayPerStatusFromTheCasesRecords(): void {
		$objects = self::objectService(
			[
				['statusType' => 'st-intake', 'createdAt' => '2026-03-05T10:00:00+00:00'],
				['statusType' => 'st-intake', '@self' => ['createdAt' => '2026-03-02T09:00:00+00:00']],
				['statusType' => 'st-decision', '@self' => ['created' => '2026-04-01T00:00:00+00:00']],
				['statusType' => '', 'createdAt' => '2026-01-01'],
				['statusType' => 'st-bad', 'createdAt' => 'yesterday'],
			]
		);

		$this->assertSame(['st-intake' => '2026-03-02', 'st-decision' => '2026-04-01'], $this->service($objects)->reachedDatesFor('case-1'));
		$this->assertSame([['search', 'dossiq', 'statusRecord', ['case' => 'case-1', '_limit' => 500]]], $objects->calls);
	}//end testTheEarliestDayPerStatusFromTheCasesRecords()

	/**
	 * With status types, the lowest-ordered one without a record gets the day the case was created.
	 *
	 * @return void
	 */
	public function testTheFirstStatusGetsTheCaseCreationDay(): void {
		$case = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['@self' => ['created' => '2026-02-20T08:00:00+00:00']];
			}
		};
		$objects = self::objectService([['statusType' => 'st-decision', 'createdAt' => '2026-04-01']], $case);
		$statusTypes = [['id' => 'st-decision', 'order' => 3], ['id' => 'st-intake', 'order' => 1]];

		$this->assertSame(['st-decision' => '2026-04-01', 'st-intake' => '2026-02-20'], $this->service($objects)->reachedDatesFor('case-1', $statusTypes));
		$this->assertSame(['find', 'case-1', 'dossiq', 'case'], $objects->calls[1]);
	}//end testTheFirstStatusGetsTheCaseCreationDay()

	/**
	 * A recorded first status keeps its own day; a case that cannot be read, or no case schema, adds nothing.
	 *
	 * @return void
	 */
	public function testTheCreationDayNeverOverridesARecordOrAppearsWithoutACase(): void {
		$statusTypes = [['id' => 'st-intake', 'order' => 1]];
		$records = [['statusType' => 'st-intake', 'createdAt' => '2026-03-02']];

		$this->assertSame(['st-intake' => '2026-03-02'], $this->service(self::objectService($records, ['@self' => ['created' => '2026-02-20']]))->reachedDatesFor('case-1', $statusTypes));
		$this->assertSame([], $this->service(self::objectService([], null))->reachedDatesFor('case-1', $statusTypes));
		$this->assertSame([], $this->service(self::objectService([], ['@self' => []]))->reachedDatesFor('case-1', $statusTypes));

		$noCaseSchema = self::objectService([], ['@self' => ['created' => '2026-02-20']]);
		$this->assertSame([], $this->service($noCaseSchema, ['register' => 'dossiq', 'status_record_schema' => 'statusRecord'])->reachedDatesFor('case-1', $statusTypes));
		$this->assertCount(1, $noCaseSchema->calls);
	}//end testTheCreationDayNeverOverridesARecordOrAppearsWithoutACase()

	/**
	 * Without a case id, object service, register or record schema nothing is searched.
	 *
	 * @return void
	 */
	public function testMissingConfigurationReadsNothing(): void {
		$objects = self::objectService([['statusType' => 'st-intake', 'createdAt' => '2026-03-02']]);

		$this->assertSame([], $this->service($objects)->reachedDatesFor(''));
		$this->assertSame([], $this->service(null)->reachedDatesFor('case-1'));
		$this->assertSame([], $this->service($objects, ['status_record_schema' => 'statusRecord'])->reachedDatesFor('case-1'));
		$this->assertSame([], $this->service($objects, ['register' => 'dossiq'])->reachedDatesFor('case-1'));
		$this->assertSame([], $objects->calls);
	}//end testMissingConfigurationReadsNothing()

	/**
	 * withInitialStatus() ignores a creation stamp that is not a date, and an empty status type list.
	 *
	 * @return void
	 */
	public function testTheInitialStatusNeedsADateAndStatusTypes(): void {
		$service = $this->service(null);

		$this->assertSame(['x' => '2026-01-01'], $service->withInitialStatus(['x' => '2026-01-01'], [['id' => 'st-intake']], 'soon'));
		$this->assertSame([], $service->withInitialStatus([], [], '2026-02-20T08:00:00+00:00'));
		$this->assertSame([], $service->withInitialStatus([], [['order' => 1]], '2026-02-20'));
	}//end testTheInitialStatusNeedsADateAndStatusTypes()
}//end class
