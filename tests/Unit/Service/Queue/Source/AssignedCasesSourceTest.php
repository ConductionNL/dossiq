<?php

/**
 * The assigned-cases source reads the open cases of one assignee off the
 * configured case schema and turns each into a queue row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue\Source;

use DateTimeImmutable;
use OCA\Dossiq\Service\Queue\Source\AssignedCasesSource;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Term\DayCount;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Queue\Source\AssignedCasesSource
 * @covers \OCA\Dossiq\Service\Queue\Source\RegisterBackedSource
 *
 * @uses \OCA\Dossiq\Service\Queue\QueueItem
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Support\ReadsQueueCaseSchema
 * @uses \OCA\Dossiq\Service\Term\DayCount
 */
class AssignedCasesSourceTest extends TestCase {
	use PersonalSourcesL10n;

	/**
	 * The settings double.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Build the settings double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(originalClassName: SettingsService::class);
	}//end setUp()

	/**
	 * Wire a store answering the given rows, or throwing, and the given config.
	 *
	 * @param list<mixed>           $rows    The case rows.
	 * @param array<string, string> $config  The configured values.
	 * @param \Throwable|null       $failure What the read throws, if anything.
	 *
	 * @return object The store, which records its searches.
	 */
	private function store(array $rows, array $config = ['register' => ' dossiq '], ?\Throwable $failure = null): object {
		$store = new class($rows, $failure) {
			/** @var list<array<int|string, mixed>> */
			public array $searches = [];

			/**
			 * Constructor.
			 *
			 * @param array $rows The rows.
			 * @param ?\Throwable $failure The failure.
			 */
			public function __construct(private array $rows, private ?\Throwable $failure) {
			}

			/**
			 * Stand-in for searchObjectsBySlug().
			 *
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param array $filters The filters.
			 *
			 * @return array
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->searches[] = [$register, $schema, $filters];
				if ($this->failure !== null) {
					throw $this->failure;
				}

				return $this->rows;
			}
		};
		$this->settings->method('getObjectService')->willReturn($store);
		$this->settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => $config[$key] ?? $default);
		return $store;
	}//end store()

	/**
	 * The source under test.
	 *
	 * @return AssignedCasesSource The source.
	 */
	private function source(): AssignedCasesSource {
		return new AssignedCasesSource(settings: $this->settings, l10n: $this->l10n(), days: new DayCount());
	}//end source()

	/**
	 * Open cases of the assignee become rows that open the case; a row without an id is skipped.
	 *
	 * @return void
	 */
	public function testOpenCasesOfTheAssigneeBecomeRowsThatOpenTheCase(): void {
		$store = $this->store(
			rows: [
				['@self' => ['id' => 'case-1'], 'title' => 'Dakkapel', 'priority' => 'high', 'deadline' => ' 2026-12-01 '],
				['id' => 'case-2', 'deadline' => ''],
				['uuid' => 'case-3', 'priority' => 'low'],
				['title' => 'No id'],
			]
		);

		$items = $this->source()->itemsFor('alice');

		$this->assertSame(expected: [['dossiq', 'case', ['assignee' => 'alice', 'isFinalStatus' => 0, '_limit' => 100]]], actual: $store->searches);
		$this->assertSame(expected: ['case-1', 'case-2', 'case-3'], actual: array_map(static fn ($item): string => $item->subjectId, $items));
		$this->assertSame(
			expected: ['assigned-cases', 'case', 'Dakkapel', 'high', '2026-12-01'],
			actual: [$items[0]->source, $items[0]->subjectType, $items[0]->title, $items[0]->priority, $items[0]->dueAt]
		);
		$this->assertSame(expected: ['name' => 'CaseDetail', 'params' => ['id' => 'case-1']], actual: $items[0]->route);
		$this->assertNull(actual: $items[0]->coveredFor);
		$this->assertSame(
			expected: ['case-2', '', null],
			actual: [$items[1]->title, $items[1]->priority, $items[1]->dueAt],
			message: 'a case without a title is titled by its id'
		);
		$this->assertSame(expected: ['uuid' => 'case-3', 'priority' => 'low'], actual: $items[2]->subject);
		$this->assertSame(expected: [], actual: $items[0]->waiting, message: 'a case not paused waits on nobody');
	}//end testOpenCasesOfTheAssigneeBecomeRowsThatOpenTheCase()

	/**
	 * The configured case schema is read, and a blank one falls back to `case`.
	 *
	 * @return void
	 */
	public function testTheConfiguredCaseSchemaIsRead(): void {
		$store = $this->store(rows: [], config: ['register' => 'dossiq', 'case_schema' => ' zaak ']);
		$this->source()->itemsFor('bob');
		$this->assertSame(expected: 'zaak', actual: $store->searches[0][1]);
	}//end testTheConfiguredCaseSchemaIsRead()

	/**
	 * A blank case schema setting reads the `case` schema.
	 *
	 * @return void
	 */
	public function testABlankCaseSchemaFallsBackToCase(): void {
		$store = $this->store(rows: [], config: ['register' => 'dossiq', 'case_schema' => '  ']);
		$this->source()->itemsFor('bob');
		$this->assertSame(expected: 'case', actual: $store->searches[0][1]);
	}//end testABlankCaseSchemaFallsBackToCase()

	/**
	 * A paused case carries who it waits on into its row.
	 *
	 * @return void
	 */
	public function testAPausedCaseCarriesItsWaitingFactsIntoTheRow(): void {
		$this->store(rows: [['id' => 'case-1', 'pauseWaitingOn' => ' applicant ', 'waitingSince' => '2000-01-01', 'chasesSent' => 1]]);

		$waiting = $this->source()->itemsFor('alice')[0]->waiting;

		$this->assertSame(expected: ['applicant', '2000-01-01', 1], actual: [$waiting['on'], $waiting['since'], $waiting['chases']]);
		$this->assertGreaterThan(expected: 9000, actual: $waiting['days']);
	}//end testAPausedCaseCarriesItsWaitingFactsIntoTheRow()

	/**
	 * A case waiting on the organisation itself has no waiting facts.
	 *
	 * @return void
	 */
	public function testACaseWaitingOnUsHasNoWaitingFacts(): void {
		$this->store(rows: []);
		$this->assertSame(expected: [], actual: $this->source()->waitingFactsOf(row: ['pauseWaitingOn' => 'us', 'waitingSince' => '2026-09-01']));
	}//end testACaseWaitingOnUsHasNoWaitingFacts()

	/**
	 * An unreadable date counts as zero days, and a negative chase count as none.
	 *
	 * @return void
	 */
	public function testAnUnreadableDateCountsZeroDaysAndNegativeChasesNone(): void {
		$this->store(rows: []);

		$facts = $this->source()->waitingFactsOf(
			row: ['pauseWaitingOn' => 'adviser', 'waitingSince' => 'not a date', 'chasesSent' => -3],
			now: new DateTimeImmutable('2026-09-10')
		);

		$this->assertSame(expected: ['on' => 'adviser', 'since' => 'not a date', 'days' => 0, 'chases' => 0], actual: $facts);
	}//end testAnUnreadableDateCountsZeroDaysAndNegativeChasesNone()

	/**
	 * Without either waiting date the days are zero.
	 *
	 * @return void
	 */
	public function testWithoutAWaitingDateTheDaysAreZero(): void {
		$this->store(rows: []);

		$facts = $this->source()->waitingFactsOf(row: ['pauseWaitingOn' => 'applicant'], now: new DateTimeImmutable('2026-09-10'));

		$this->assertSame(expected: ['on' => 'applicant', 'since' => '', 'days' => 0, 'chases' => 0], actual: $facts);
	}//end testWithoutAWaitingDateTheDaysAreZero()

	/**
	 * The source describes itself and the notifications that feed it.
	 *
	 * @return void
	 */
	public function testTheSourceDescribesItself(): void {
		$source = $this->source();

		$this->assertSame(expected: 'assigned-cases', actual: $source->name());
		$this->assertSame(expected: 'Cases assigned to you', actual: $source->label());
		$this->assertSame(expected: 'A case leaves when it is closed or goes to somebody else.', actual: $source->closesWhen());
		$this->assertSame(
			expected: ['notification:caseAssigned', 'notification:caseHandoffIntake', 'notification:termResumedAfterPause'],
			actual: $source->mechanisms()
		);
	}//end testTheSourceDescribesItself()

	/**
	 * Without a register the source fails rather than answering an empty queue.
	 *
	 * @return void
	 */
	public function testWithoutARegisterTheSourceFails(): void {
		$this->store(rows: [], config: ['register' => '  ']);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Dossiq has no register configured yet.');
		$this->source()->itemsFor('alice');
	}//end testWithoutARegisterTheSourceFails()

	/**
	 * A failed read is raised as a RuntimeException keeping its cause.
	 *
	 * @return void
	 */
	public function testAFailedReadIsRaisedKeepingItsCause(): void {
		$cause = new \LogicException('schema case not found');
		$this->store(rows: [], config: ['register' => 'dossiq'], failure: $cause);

		try {
			$this->source()->itemsFor('alice');
			$this->fail(message: 'a failed read must not answer an empty queue');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'schema case not found', actual: $e->getMessage());
			$this->assertSame(expected: $cause, actual: $e->getPrevious());
		}
	}//end testAFailedReadIsRaisedKeepingItsCause()
}//end class
