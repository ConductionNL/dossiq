<?php

/**
 * Which term definitions are in force for a case type, and the planned clock
 * a service norm gives.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermDefinitions;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The lookup reads the configured register, keeps only definitions valid
 * today, newest first, and degrades to nothing when it cannot read.
 *
 * Validity dates are far in the past or future, so no test depends on today.
 *
 * @covers \OCA\Dossiq\Service\Termijn\TermDefinitions
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\TermKind
 */
class TermDefinitionsLookupTest extends TestCase {

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the logger before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Definitions over an object service answering the given rows (or failing).
	 *
	 * @param object|null         $objectService The object service, null when OpenRegister has none.
	 * @param array<string,string> $config       Config values by key.
	 * @param WorkingDayRoll|null $roll          The working-day roll.
	 *
	 * @return TermDefinitions The subject.
	 */
	private function definitions(?object $objectService, array $config=['register' => '4', 'termijn_definitie_schema' => '9'], ?WorkingDayRoll $roll=null): TermDefinitions {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		return new TermDefinitions(settingsService: $settings, logger: $this->logger, roll: $roll);
	}//end definitions()

	/**
	 * An object service that records its queries and answers fixed rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return object The service.
	 */
	private function answering(array $rows): object {
		return new class($rows) {
			/**
			 * The queries received.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $queries = [];

			/**
			 * @param array<int, array<string, mixed>> $rows The rows.
			 */
			public function __construct(private readonly array $rows) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjects(array $query): array {
				$this->queries[] = $query;
				return $this->rows;
			}
		};
	}//end answering()

	/**
	 * The search goes to the configured register and schema, filtered on the case type.
	 *
	 * @return void
	 */
	public function testTheLookupQueriesTheConfiguredSchemaForTheCaseType(): void {
		$service = $this->answering([]);

		$this->assertSame([], $this->definitions(objectService: $service)->allActiveFor(caseType: 'ct-1'));
		$this->assertSame([['caseType' => 'ct-1', '@self' => ['register' => 4, 'schema' => 9]]], $service->queries);
	}//end testTheLookupQueriesTheConfiguredSchemaForTheCaseType()

	/**
	 * Without the object service, the register or the schema nothing is searched.
	 *
	 * @return void
	 */
	public function testWithoutConfigurationNothingIsSearched(): void {
		$service = $this->answering([['id' => 'x']]);

		$this->assertSame([], $this->definitions(objectService: null)->allActiveFor(caseType: 'ct-1'));
		$this->assertSame([], $this->definitions(objectService: $service, config: ['register' => '4'])->allActiveFor(caseType: 'ct-1'));
		$this->assertSame([], $this->definitions(objectService: $service, config: ['termijn_definitie_schema' => '9'])->allActiveFor(caseType: 'ct-1'));
		$this->assertSame([], $service->queries);
	}//end testWithoutConfigurationNothingIsSearched()

	/**
	 * A failed search is logged with the case type and answers nothing.
	 *
	 * @return void
	 */
	public function testAFailedSearchIsLoggedAndAnswersNothing(): void {
		$service = new class {
			/**
			 * @return never
			 */
			public function searchObjects(): never {
				throw new RuntimeException('index down');
			}
		};
		$this->logger->expects($this->once())
			->method('warning')
			->with('TermDefinitions.allActiveFor lookup failed', ['caseType' => 'ct-1', 'error' => 'index down']);

		$this->assertSame([], $this->definitions(objectService: $service)->allActiveFor(caseType: 'ct-1'));
	}//end testAFailedSearchIsLoggedAndAnswersNothing()

	/**
	 * Only definitions valid today are kept, newest validFrom first.
	 *
	 * @return void
	 */
	public function testOnlyCurrentDefinitionsAreKeptNewestFirst(): void {
		$rows = [
			['id' => 'old', 'validFrom' => '2000-01-01'],
			['id' => 'expired', 'validFrom' => '2000-01-01', 'validUntil' => '2001-01-01'],
			['id' => 'future', 'validFrom' => '2999-01-01'],
			['id' => 'open'],
			['id' => 'new', 'validFrom' => '2010-06-01', 'validUntil' => '2999-12-31'],
		];

		$active = $this->definitions(objectService: $this->answering($rows))->allActiveFor(caseType: 'ct-1');

		$this->assertSame(['new', 'old', 'open'], array_column($active, 'id'));
	}//end testOnlyCurrentDefinitionsAreKeptNewestFirst()

	/**
	 * The statutory term is the newest statutory definition; a service norm is never it.
	 *
	 * @return void
	 */
	public function testTheActiveTermIsTheNewestStatutoryOneAndIsCached(): void {
		$service = $this->answering([
			['id' => 'norm', 'kind' => 'planned', 'validFrom' => '2020-01-01'],
			['id' => 'awb', 'kind' => 'statutory', 'validFrom' => '2010-01-01'],
			['id' => 'legacy', 'validFrom' => '2005-01-01'],
		]);
		$definitions = $this->definitions(objectService: $service);

		$this->assertSame('awb', $definitions->activeFor(caseType: 'ct-1')['id'] ?? null);
		$this->assertSame('awb', $definitions->activeFor(caseType: 'ct-1')['id'] ?? null);
		$this->assertCount(1, $service->queries);
	}//end testTheActiveTermIsTheNewestStatutoryOneAndIsCached()

	/**
	 * A case type with only a service norm has no statutory term, and that answer is not cached.
	 *
	 * @return void
	 */
	public function testOnlyAServiceNormMeansNoStatutoryTerm(): void {
		$service     = $this->answering([['id' => 'norm', 'kind' => 'planned', 'validFrom' => '2020-01-01']]);
		$definitions = $this->definitions(objectService: $service);

		$this->assertNull($definitions->activeFor(caseType: 'ct-1'));
		$this->assertNull($definitions->activeFor(caseType: 'ct-1'));
		$this->assertCount(2, $service->queries);
	}//end testOnlyAServiceNormMeansNoStatutoryTerm()

	/**
	 * Declared days win and count working days; without a definition there is nothing more.
	 *
	 * @return void
	 */
	public function testDeclaredDaysMakeThePlannedClock(): void {
		$expected = ['days' => 5, 'mode' => WorkingDayRoll::MODE_WORKING_DAYS, 'extra' => []];

		$this->assertSame($expected, TermDefinitions::plannedClock(declaredDays: 5, definition: ['standardDurationDays' => 30]));
		$this->assertSame(['days' => 0, 'mode' => WorkingDayRoll::MODE_WORKING_DAYS, 'extra' => []], TermDefinitions::plannedClock(declaredDays: 0, definition: null));
	}//end testDeclaredDaysMakeThePlannedClock()

	/**
	 * Without declared days the definition's duration and mode make the clock, and its id is recorded.
	 *
	 * @return void
	 */
	public function testTheDefinitionMakesThePlannedClockWithoutDeclaredDays(): void {
		$this->assertSame(
			['days' => 10, 'mode' => WorkingDayRoll::MODE_WORKING_DAYS, 'extra' => ['deadlineDefinition' => 'def-1']],
			TermDefinitions::plannedClock(declaredDays: 0, definition: ['id' => ' def-1 ', 'standardDurationDays' => '10', 'countingMode' => 'workingDays'])
		);
		$this->assertSame(
			['days' => 0, 'mode' => WorkingDayRoll::MODE_CALENDAR_DAYS, 'extra' => []],
			TermDefinitions::plannedClock(declaredDays: -1, definition: ['id' => '  '])
		);
	}//end testTheDefinitionMakesThePlannedClockWithoutDeclaredDays()

	/**
	 * A working-day term ends where the roll says, without a warning.
	 *
	 * @return void
	 */
	public function testAWorkingDayTermEndsWhereTheRollSays(): void {
		$start = new DateTimeImmutable('2026-04-24');
		$roll  = $this->createMock(WorkingDayRoll::class);
		$roll->expects($this->once())
			->method('endAfter')
			->with($start, 3, WorkingDayRoll::MODE_WORKING_DAYS)
			->willReturn(new DateTimeImmutable('2026-04-29'));
		$this->logger->expects($this->never())->method('warning');

		$end = $this->definitions(objectService: null, roll: $roll)->countedEndDateFor(start: $start, days: 3, definitie: ['countingMode' => 'workingDays']);

		$this->assertSame('2026-04-29', $end->format('Y-m-d'));
	}//end testAWorkingDayTermEndsWhereTheRollSays()
}//end class
