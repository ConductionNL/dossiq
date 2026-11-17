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
		$this->settings = $this->createMock(SettingsService::class);
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

			public function __construct(private array $rows, private ?\Throwable $failure) {
			}

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
			[
				['@self' => ['id' => 'case-1'], 'title' => 'Dakkapel', 'priority' => 'high', 'deadline' => ' 2026-12-01 '],
				['id' => 'case-2', 'deadline' => ''],
				['uuid' => 'case-3', 'priority' => 'low'],
				['title' => 'No id'],
			]
		);

		$items = $this->source()->itemsFor('alice');

		$this->assertSame([['dossiq', 'case', ['assignee' => 'alice', 'isFinalStatus' => 0, '_limit' => 100]]], $store->searches);
		$this->assertSame(['case-1', 'case-2', 'case-3'], array_map(static fn ($item): string => $item->subjectId, $items));
		$this->assertSame(['assigned-cases', 'case', 'Dakkapel', 'high', '2026-12-01'], [$items[0]->source, $items[0]->subjectType, $items[0]->title, $items[0]->priority, $items[0]->dueAt]);
		$this->assertSame(['name' => 'CaseDetail', 'params' => ['id' => 'case-1']], $items[0]->route);
		$this->assertNull($items[0]->coveredFor);
		$this->assertSame(['case-2', '', null], [$items[1]->title, $items[1]->priority, $items[1]->dueAt], 'a case without a title is titled by its id');
		$this->assertSame(['uuid' => 'case-3', 'priority' => 'low'], $items[2]->subject);
		$this->assertSame([], $items[0]->waiting, 'a case not paused waits on nobody');
	}//end testOpenCasesOfTheAssigneeBecomeRowsThatOpenTheCase()

	/**
	 * The configured case schema is read, and a blank one falls back to `case`.
	 *
	 * @return void
	 */
	public function testTheConfiguredCaseSchemaIsRead(): void {
		$store = $this->store([], ['register' => 'dossiq', 'case_schema' => ' zaak ']);
		$this->source()->itemsFor('bob');
		$this->assertSame('zaak', $store->searches[0][1]);
	}//end testTheConfiguredCaseSchemaIsRead()

	/**
	 * A blank case schema setting reads the `case` schema.
	 *
	 * @return void
	 */
	public function testABlankCaseSchemaFallsBackToCase(): void {
		$store = $this->store([], ['register' => 'dossiq', 'case_schema' => '  ']);
		$this->source()->itemsFor('bob');
		$this->assertSame('case', $store->searches[0][1]);
	}//end testABlankCaseSchemaFallsBackToCase()

	/**
	 * A paused case carries who it waits on into its row.
	 *
	 * @return void
	 */
	public function testAPausedCaseCarriesItsWaitingFactsIntoTheRow(): void {
		$this->store([['id' => 'case-1', 'pauseWaitingOn' => ' applicant ', 'waitingSince' => '2000-01-01', 'chasesSent' => 1]]);

		$waiting = $this->source()->itemsFor('alice')[0]->waiting;

		$this->assertSame(['applicant', '2000-01-01', 1], [$waiting['on'], $waiting['since'], $waiting['chases']]);
		$this->assertGreaterThan(9000, $waiting['days']);
	}//end testAPausedCaseCarriesItsWaitingFactsIntoTheRow()

	/**
	 * A case waiting on the organisation itself has no waiting facts.
	 *
	 * @return void
	 */
	public function testACaseWaitingOnUsHasNoWaitingFacts(): void {
		$this->store([]);
		$this->assertSame([], $this->source()->waitingFactsOf(row: ['pauseWaitingOn' => 'us', 'waitingSince' => '2026-09-01']));
	}//end testACaseWaitingOnUsHasNoWaitingFacts()

	/**
	 * An unreadable date counts as zero days, and a negative chase count as none.
	 *
	 * @return void
	 */
	public function testAnUnreadableDateCountsZeroDaysAndNegativeChasesNone(): void {
		$this->store([]);

		$facts = $this->source()->waitingFactsOf(
			row: ['pauseWaitingOn' => 'adviser', 'waitingSince' => 'not a date', 'chasesSent' => -3],
			now: new DateTimeImmutable('2026-09-10')
		);

		$this->assertSame(['on' => 'adviser', 'since' => 'not a date', 'days' => 0, 'chases' => 0], $facts);
	}//end testAnUnreadableDateCountsZeroDaysAndNegativeChasesNone()

	/**
	 * Without either waiting date the days are zero.
	 *
	 * @return void
	 */
	public function testWithoutAWaitingDateTheDaysAreZero(): void {
		$this->store([]);

		$facts = $this->source()->waitingFactsOf(row: ['pauseWaitingOn' => 'applicant'], now: new DateTimeImmutable('2026-09-10'));

		$this->assertSame(['on' => 'applicant', 'since' => '', 'days' => 0, 'chases' => 0], $facts);
	}//end testWithoutAWaitingDateTheDaysAreZero()

	/**
	 * The source describes itself and the notifications that feed it.
	 *
	 * @return void
	 */
	public function testTheSourceDescribesItself(): void {
		$source = $this->source();

		$this->assertSame('assigned-cases', $source->name());
		$this->assertSame('Cases assigned to you', $source->label());
		$this->assertSame('A case leaves when it is closed or goes to somebody else.', $source->closesWhen());
		$this->assertSame(['notification:caseAssigned', 'notification:caseHandoffIntake', 'notification:termResumedAfterPause'], $source->mechanisms());
	}//end testTheSourceDescribesItself()

	/**
	 * Without a register the source fails rather than answering an empty queue.
	 *
	 * @return void
	 */
	public function testWithoutARegisterTheSourceFails(): void {
		$this->store([], ['register' => '  ']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Dossiq has no register configured yet.');
		$this->source()->itemsFor('alice');
	}//end testWithoutARegisterTheSourceFails()

	/**
	 * A failed read is raised as a RuntimeException keeping its cause.
	 *
	 * @return void
	 */
	public function testAFailedReadIsRaisedKeepingItsCause(): void {
		$cause = new \LogicException('schema case not found');
		$this->store([], ['register' => 'dossiq'], $cause);

		try {
			$this->source()->itemsFor('alice');
			$this->fail('a failed read must not answer an empty queue');
		} catch (RuntimeException $e) {
			$this->assertSame('schema case not found', $e->getMessage());
			$this->assertSame($cause, $e->getPrevious());
		}
	}//end testAFailedReadIsRaisedKeepingItsCause()
}//end class
