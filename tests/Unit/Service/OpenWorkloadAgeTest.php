<?php

/**
 * The age of the open workload, per status, read live.
 *
 * The case that matters is the filter: closed cases must not enter the number.
 * The test asserts the FILTER that goes to the store, not only the answer that
 * comes back, because a reading that filtered in PHP would return the same
 * number here while pulling the whole register over in production.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\OpenWorkloadAgeService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-TERM-069: the age of the open workload is answerable per status.
 *
 * @covers \OCA\Dossiq\Service\OpenWorkloadAgeService
 */
class OpenWorkloadAgeTest extends TestCase {
	/**
	 * The filters the store was asked with, on the last call.
	 *
	 * @var array<string, mixed>
	 */
	private array $askedWith = [];

	/**
	 * The rows the store answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * The service under test.
	 *
	 * @var OpenWorkloadAgeService
	 */
	private OpenWorkloadAgeService $service;

	/**
	 * Wire the service against a store that records what it was asked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$store = new class ($this) {
			/**
			 * @param OpenWorkloadAgeTest $test The test that owns the recording.
			 */
			public function __construct(private readonly OpenWorkloadAgeTest $test) {
			}

			/**
			 * Answer a search, recording what it was asked for.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $query = []): array {
				return $this->test->answer(query: $query);
			}

			/**
			 * The numeric-id path, which this test does not take.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjects(array $query = []): array {
				return $this->test->answer(query: $query);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				default => '',
			}
		);

		$this->service = new OpenWorkloadAgeService(settingsService: $settings, logger: new NullLogger());
	}//end setUp()

	/**
	 * Record the query and answer with the seeded rows.
	 *
	 * @param array<string, mixed> $query The query the service built.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function answer(array $query): array {
		$this->askedWith = $query;

		return $this->rows;
	}//end answer()

	/**
	 * A teamleider sees the age of what is still standing, per status.
	 *
	 * @return void
	 */
	public function testAnAgePerStatusOverTheOpenCases(): void {
		$this->rows = [
			$this->case(status: 'In behandeling', start: '2026-09-05'),
			$this->case(status: 'In behandeling', start: '2026-08-16'),
			$this->case(status: 'Beoordeling', start: '2026-09-14'),
			$this->case(status: 'Wacht op aanvulling', start: '2026-03-15'),
		];

		$report = $this->service->report(now: new DateTimeImmutable('2026-09-15'));

		self::assertSame(4, $report['openCases']);
		self::assertCount(3, $report['perStatus']);

		$byStatus = array_column($report['perStatus'], null, 'status');

		self::assertSame(2, $byStatus['In behandeling']['cases']);
		self::assertSame(30, $byStatus['In behandeling']['oldestDays']);
		self::assertSame(20, $byStatus['In behandeling']['averageDays']);
		self::assertSame(1, $byStatus['Beoordeling']['cases']);
		self::assertSame(184, $byStatus['Wacht op aanvulling']['oldestDays']);
	}//end testAnAgePerStatusOverTheOpenCases()

	/**
	 * The oldest queue is listed first, because that is what is asked for.
	 *
	 * @return void
	 */
	public function testTheOldestQueueComesFirst(): void {
		$this->rows = [
			$this->case(status: 'Beoordeling', start: '2026-09-14'),
			$this->case(status: 'Wacht op aanvulling', start: '2026-03-15'),
		];

		$report = $this->service->report(now: new DateTimeImmutable('2026-09-15'));

		self::assertSame('Wacht op aanvulling', $report['perStatus'][0]['status']);
	}//end testTheOldestQueueComesFirst()

	/**
	 * Closed cases never enter the number, and the STORE is what keeps them out.
	 *
	 * @return void
	 */
	public function testClosedCasesAreFilteredByTheStore(): void {
		$this->rows = [$this->case(status: 'In behandeling', start: '2026-09-05')];

		$this->service->report(now: new DateTimeImmutable('2026-09-15'));

		self::assertArrayHasKey('isFinalStatus', $this->askedWith);
		self::assertSame(0, $this->askedWith['isFinalStatus'], 'Open cases only, decided server-side.');
	}//end testClosedCasesAreFilteredByTheStore()

	/**
	 * A case whose start cannot be read is skipped rather than counted as new.
	 *
	 * @return void
	 */
	public function testACaseWithNoReadableStartIsSkipped(): void {
		$this->rows = [
			$this->case(status: 'In behandeling', start: '2026-09-05'),
			['status' => 'In behandeling'],
		];

		$report = $this->service->report(now: new DateTimeImmutable('2026-09-15'));

		self::assertSame(10, $report['perStatus'][0]['averageDays'], 'A zero-day case would have halved this.');
		self::assertSame(1, $report['perStatus'][0]['cases']);
	}//end testACaseWithNoReadableStartIsSkipped()

	/**
	 * A case that has just changed status is counted under its new one.
	 *
	 * @return void
	 */
	public function testTheReportIsReadLive(): void {
		$this->rows = [$this->case(status: 'Beoordeling', start: '2026-09-05')];
		$first = $this->service->report(now: new DateTimeImmutable('2026-09-15'));

		$this->rows = [$this->case(status: 'Besluit', start: '2026-09-05')];
		$second = $this->service->report(now: new DateTimeImmutable('2026-09-15'));

		self::assertSame('Beoordeling', $first['perStatus'][0]['status']);
		self::assertSame('Besluit', $second['perStatus'][0]['status'], 'No cache, no nightly job.');
	}//end testTheReportIsReadLive()

	/**
	 * A status arriving as an expanded object is read by its name.
	 *
	 * @return void
	 */
	public function testAnExpandedStatusIsReadByName(): void {
		$this->rows = [
			['status' => ['id' => 'st1', 'name' => 'In behandeling'], 'startDate' => '2026-09-05'],
		];

		$report = $this->service->report(now: new DateTimeImmutable('2026-09-15'));

		self::assertSame('In behandeling', $report['perStatus'][0]['status']);
	}//end testAnExpandedStatusIsReadByName()

	/**
	 * One open case row.
	 *
	 * @param string $status The status it sits in.
	 * @param string $start The day it started.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function case(string $status, string $start): array {
		return ['status' => $status, 'startDate' => $start, 'isFinalStatus' => 0];
	}//end case()
}//end class
