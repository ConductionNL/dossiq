<?php

/**
 * Dossiq FirstResponseOutcome test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Term
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Term\FirstResponseOutcome;
use OCA\Dossiq\Service\WorkingDayCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The promise that you hear from us within so many days, kept or not, and by
 * how much it was not.
 *
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md
 */
class FirstResponseOutcomeTest extends TestCase {
	/**
	 * The object store every test writes through.
	 *
	 * @var FakeOutcomeObjectService
	 */
	private FakeOutcomeObjectService $objects;

	/**
	 * Build the fake.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = new FakeOutcomeObjectService();
	}//end setUp()

	/**
	 * Scenario: A missed first response stores how late it was.
	 *
	 * Promised on the third working day, sent on the sixth: three working
	 * days late, and it is the number that is stored rather than the fact.
	 *
	 * @return void
	 */
	public function testAMissedFirstResponseStoresHowLateItWas(): void {
		$this->objects->store('case-1', ['id' => 'case-1']);

		$recorded = $this->outcome()->record(
			caseId: 'case-1',
			due: new DateTimeImmutable('2026-09-09T17:00:00+02:00'),
			sent: new DateTimeImmutable('2026-09-14T09:00:00+02:00')
		);

		$this->assertSame('missed', $recorded['status']);
		$this->assertSame(3, $recorded['overrunDays']);
		$this->assertSame('missed', $this->objects->read('case-1')['firstResponseStatus']);
		$this->assertSame(3, $this->objects->read('case-1')['firstResponseOverrunDays']);
	}//end testAMissedFirstResponseStoresHowLateItWas()

	/**
	 * Scenario: A met first response is recorded too. Storing only the misses
	 * makes the denominator a second query with different filters, and the two
	 * then disagree.
	 *
	 * @return void
	 */
	public function testAMetFirstResponseIsRecordedToo(): void {
		$this->objects->store('case-1', ['id' => 'case-1']);

		$recorded = $this->outcome()->record(
			caseId: 'case-1',
			due: new DateTimeImmutable('2026-09-14T17:00:00+02:00'),
			sent: new DateTimeImmutable('2026-09-10T09:00:00+02:00')
		);

		$this->assertSame('met', $recorded['status']);
		$this->assertSame(0, $recorded['overrunDays']);
		$this->assertSame('met', $this->objects->read('case-1')['firstResponseStatus']);
		$this->assertSame(0, $this->objects->read('case-1')['firstResponseOverrunDays']);
	}//end testAMetFirstResponseIsRecordedToo()

	/**
	 * A term that counts calendar days counts the weekend against us, because
	 * it counted for us too.
	 *
	 * @return void
	 */
	public function testACalendarDayTermCountsTheWeekend(): void {
		$this->objects->store('case-1', ['id' => 'case-1']);

		$recorded = $this->outcome()->record(
			caseId: 'case-1',
			due: new DateTimeImmutable('2026-09-09T17:00:00+02:00'),
			sent: new DateTimeImmutable('2026-09-14T09:00:00+02:00'),
			working: false
		);

		$this->assertSame(4, $recorded['overrunDays']);
	}//end testACalendarDayTermCountsTheWeekend()

	/**
	 * Scenario: The overrun is reportable. The count and the average come out
	 * of the stored numbers, not out of dates that may since have moved.
	 *
	 * @return void
	 */
	public function testTheOverrunIsReportedFromTheStoredNumbers(): void {
		$overruns = [1, 2, 3, 4, 5, 1, 2, 3, 4, 5, 3];
		foreach ($overruns as $index => $days) {
			$this->objects->store(
				'missed-' . $index,
				['id' => 'missed-' . $index, 'firstResponseStatus' => 'missed', 'firstResponseOverrunDays' => $days]
			);
		}

		$this->objects->store('met-1', ['id' => 'met-1', 'firstResponseStatus' => 'met', 'firstResponseOverrunDays' => 0]);
		$this->objects->store('open-1', ['id' => 'open-1']);

		$report = $this->outcome()->report(filters: ['caseType' => 'klacht']);

		$this->assertSame(11, $report['missed']);
		$this->assertSame(1, $report['met']);
		$this->assertSame(round((33 / 11), 2), $report['averageOverrunDays']);
	}//end testTheOverrunIsReportedFromTheStoredNumbers()

	/**
	 * Nothing missed is an average of nought, not a division by it.
	 *
	 * @return void
	 */
	public function testNothingMissedIsAnAverageOfNought(): void {
		$this->objects->store('met-1', ['id' => 'met-1', 'firstResponseStatus' => 'met']);

		$this->assertSame(0.0, $this->outcome()->report()['averageOverrunDays']);
	}//end testNothingMissedIsAnAverageOfNought()

	/**
	 * The service over the fakes.
	 *
	 * @return FirstResponseOutcome The service under test.
	 */
	private function outcome(): FirstResponseOutcome {
		$config = ['register' => '1', 'case_schema' => '2'];

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (string)($config[$key] ?? $default)
		);
		$settings->method('getObjectService')->willReturn($this->objects);

		return new FirstResponseOutcome(
			settingsService: $settings,
			workingDays: new WorkingDayCalculator(),
			logger: new NullLogger()
		);
	}//end outcome()
}//end class

/**
 * A stand-in for OpenRegister's ObjectService holding case rows.
 *
 * Written out rather than stubbed, so no method the real service lacks can be
 * invented here: these two are the two the outcome service actually calls.
 */
final class FakeOutcomeObjectService {
	/**
	 * Stored cases by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $cases = [];

	/**
	 * Put a case in the store.
	 *
	 * @param string               $uuid    The case uuid.
	 * @param array<string, mixed> $payload The case.
	 *
	 * @return void
	 */
	public function store(string $uuid, array $payload): void {
		$this->cases[$uuid] = $payload;
	}//end store()

	/**
	 * Read a case back.
	 *
	 * @param string $uuid The case uuid.
	 *
	 * @return array<string, mixed> The case.
	 */
	public function read(string $uuid): array {
		return ($this->cases[$uuid] ?? []);
	}//end read()

	/**
	 * Write a few fields onto one case.
	 *
	 * @param string          $objectId The case uuid.
	 * @param array           $data     The fields.
	 * @param int|string|null $register Unused.
	 * @param int|string|null $schema   Unused.
	 *
	 * @return array<string, mixed>|null The stored case.
	 */
	public function patchObject(
		string $objectId,
		array $data,
		int|string|null $register = null,
		int|string|null $schema = null,
	): ?array {
		if (isset($this->cases[$objectId]) === false) {
			return null;
		}

		$this->cases[$objectId] = array_merge($this->cases[$objectId], $data);

		return $this->cases[$objectId];
	}//end patchObject()

	/**
	 * Every stored case. The filters are carried but not applied: what this
	 * fake exists to exercise is the arithmetic over the rows, and a filter
	 * implemented here would be a filter asserted here rather than in
	 * OpenRegister.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<int, array<string, mixed>> The cases.
	 */
	public function searchObjects(array $query): array {
		return array_values($this->cases);
	}//end searchObjects()
}//end class
