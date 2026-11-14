<?php

/**
 * The silence close: when a case type's silence period warns, when it
 * closes, what counts as the last activity, and what a warning and a close
 * write.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Lifecycle
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-status-machinery/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\Lifecycle\LifecycleCaseTypeRules;
use OCA\Dossiq\Service\Lifecycle\SilenceCloseService;
use OCA\Dossiq\Service\Lifecycle\SilenceCloseSteps;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Lifecycle\SilenceCloseService
 * @uses   \OCA\Dossiq\Service\Lifecycle\SilenceCloseSteps
 * @uses   \OCA\Dossiq\Service\Lifecycle\CaseJournal
 * @uses   \OCA\Dossiq\Service\Support\RowList
 * @uses   \OCA\Dossiq\Service\Term\DayCount
 */
class SilenceCloseServiceTest extends TestCase {

	/**
	 * Case store.
	 *
	 * @var CaseStatusStore&MockObject
	 */
	private CaseStatusStore&MockObject $store;

	/**
	 * Case type rules.
	 *
	 * @var LifecycleCaseTypeRules&MockObject
	 */
	private LifecycleCaseTypeRules&MockObject $rules;

	/**
	 * Ending acts.
	 *
	 * @var CaseEndingActs&MockObject
	 */
	private CaseEndingActs&MockObject $endings;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the collaborators; the journal is real, with no session.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store   = $this->createMock(CaseStatusStore::class);
		$this->rules   = $this->createMock(LifecycleCaseTypeRules::class);
		$this->endings = $this->createMock(CaseEndingActs::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service, with a date normaliser reading ISO strings in UTC.
	 *
	 * @return SilenceCloseService
	 */
	private function service(): SilenceCloseService {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$dates = $this->createMock(CaseDateNormaliser::class);
		$dates->method('tryParse')->willReturnCallback(
			static function (mixed $value): ?DateTimeImmutable {
				if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
					return null;
				}

				return new DateTimeImmutable($value, new \DateTimeZone('UTC'));
			}
		);

		return new SilenceCloseService(
			store: $this->store,
			steps: new SilenceCloseSteps(rules: $this->rules, endings: $this->endings, journal: new CaseJournal(userSession: $session)),
			dates: $dates,
			logger: $this->logger
		);
	}//end service()

	/**
	 * A case type with this silence period and warning lead time.
	 *
	 * @param int $period  The silence period in days.
	 * @param int $warning The warning lead time in days.
	 *
	 * @return void
	 */
	private function caseType(int $period, int $warning=7): void {
		$this->rules->method('silenceDays')->with('ct-1')->willReturn($period);
		$this->rules->method('warningDays')->with('ct-1')->willReturn($warning);
	}//end caseType()

	/**
	 * A case of type ct-1 with these journal entries.
	 *
	 * @param array<int, array<string, mixed>> $entries The journal.
	 * @param array<string, mixed>             $extra   Other fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function aCase(array $entries, array $extra=[]): array {
		return array_merge(['caseType' => 'ct-1', 'activity' => json_encode($entries)], $extra);
	}//end aCase()

	/**
	 * Today, at midnight UTC.
	 *
	 * @param string $date The date.
	 *
	 * @return DateTimeImmutable
	 */
	private static function day(string $date): DateTimeImmutable {
		return new DateTimeImmutable($date, new \DateTimeZone('UTC'));
	}//end day()

	/**
	 * A case type without a silence period never acts.
	 *
	 * @return void
	 */
	public function testNoPeriodMeansNoAction(): void {
		$this->caseType(period: 0);

		$decision = $this->service()->decide(case: self::aCase([['type' => 'note', 'at' => '2020-01-01']]), today: self::day('2026-01-01'));

		$this->assertSame(['action' => 'none', 'silentDays' => 0, 'period' => 0, 'closesOn' => ''], $decision);
	}//end testNoPeriodMeansNoAction()

	/**
	 * A case with no readable activity at all never acts.
	 *
	 * @return void
	 */
	public function testNoKnownActivityMeansNoAction(): void {
		$this->caseType(period: 30);

		$decision = $this->service()->decide(case: self::aCase([]), today: self::day('2026-01-01'));

		$this->assertSame(['action' => 'none', 'silentDays' => 0, 'period' => 30, 'closesOn' => ''], $decision);
	}//end testNoKnownActivityMeansNoAction()

	/**
	 * Silent for the full period: close, on the day the period ends.
	 *
	 * @return void
	 */
	public function testSilentForThePeriodCloses(): void {
		$this->caseType(period: 30);

		$decision = $this->service()->decide(
			case: self::aCase([['type' => 'note', 'at' => '2026-01-01T00:00:00+00:00']]),
			today: self::day('2026-01-31')
		);

		$this->assertSame(['action' => 'close', 'silentDays' => 30, 'period' => 30, 'closesOn' => '2026-01-31'], $decision);
	}//end testSilentForThePeriodCloses()

	/**
	 * Within the warning window and not yet warned: warn.
	 *
	 * @return void
	 */
	public function testInsideTheWarningWindowWarns(): void {
		$this->caseType(period: 30, warning: 5);

		$decision = $this->service()->decide(
			case: self::aCase([['type' => 'note', 'at' => '2026-01-01T00:00:00+00:00']]),
			today: self::day('2026-01-26')
		);

		$this->assertSame(['action' => 'warn', 'silentDays' => 25, 'period' => 30, 'closesOn' => '2026-01-31'], $decision);
	}//end testInsideTheWarningWindowWarns()

	/**
	 * One day before the warning window: nothing yet, but the count is reported.
	 *
	 * @return void
	 */
	public function testBeforeTheWarningWindowDoesNothing(): void {
		$this->caseType(period: 30, warning: 5);

		$decision = $this->service()->decide(
			case: self::aCase([['type' => 'note', 'at' => '2026-01-01T00:00:00+00:00']]),
			today: self::day('2026-01-25')
		);

		$this->assertSame(['action' => 'none', 'silentDays' => 24, 'period' => 30, 'closesOn' => '2026-01-31'], $decision);
	}//end testBeforeTheWarningWindowDoesNothing()

	/**
	 * A case already warned is not warned twice, and the warning itself does
	 * not reset the silence it announces.
	 *
	 * @return void
	 */
	public function testAWarnedCaseIsNotWarnedAgainAndTheWarningIsNotActivity(): void {
		$this->caseType(period: 30, warning: 5);
		$case = self::aCase(
			[
				['type' => 'note', 'at' => '2026-01-01T00:00:00+00:00'],
				['type' => SilenceCloseService::WARNING_TYPE, 'at' => '2026-01-26T00:00:00+00:00'],
			]
		);

		$decision = $this->service()->decide(case: $case, today: self::day('2026-01-28'));

		$this->assertSame(['action' => 'none', 'silentDays' => 27, 'period' => 30, 'closesOn' => '2026-01-31'], $decision);
	}//end testAWarnedCaseIsNotWarnedAgainAndTheWarningIsNotActivity()

	/**
	 * The last activity is the newest journal entry with a readable moment.
	 *
	 * @return void
	 */
	public function testLastActivitySkipsUnreadableEntries(): void {
		$case = self::aCase(
			[
				['type' => 'note', 'at' => '2026-02-01T10:00:00+00:00'],
				['type' => 'note', 'at' => '2026-03-01T10:00:00+00:00'],
				['type' => 'note', 'at' => 'yesterday'],
				['type' => 'note'],
			]
		);

		$this->assertSame('2026-03-01T10:00:00+00:00', $this->service()->lastActivity(case: $case)?->format('c'));
	}//end testLastActivitySkipsUnreadableEntries()

	/**
	 * Without journal activity the last update counts, then the start date.
	 *
	 * @return void
	 */
	public function testLastActivityFallsBackToUpdatedThenStartDate(): void {
		$service = $this->service();

		$this->assertSame(
			'2026-04-02',
			$service->lastActivity(case: ['@self' => ['updated' => '2026-04-02T00:00:00+00:00'], 'startDate' => '2026-01-01'])?->format('Y-m-d')
		);
		$this->assertSame('2026-01-01', $service->lastActivity(case: ['startDate' => '2026-01-01'])?->format('Y-m-d'));
		$this->assertNull($service->lastActivity(case: []));
	}//end testLastActivityFallsBackToUpdatedThenStartDate()

	/**
	 * A warning appends a product entry with the close date and saves once.
	 *
	 * @return void
	 */
	public function testWarnJournalsAndSaves(): void {
		$saved = null;
		$this->store->expects($this->once())->method('saveCase')->willReturnCallback(
			static function (array $case) use (&$saved): array {
				$saved = $case;
				return $case;
			}
		);
		$this->logger->expects($this->once())->method('info')->with(
			'SilenceCloseService: the applicant was told the case will close',
			['caseId' => 'c-1', 'closesOn' => '2026-01-31']
		);

		$this->service()->warn(caseId: 'c-1', case: self::aCase([['type' => 'note', 'at' => '2026-01-01']]), closesOn: '2026-01-31');

		$journal = json_decode($saved['activity'], true);
		$this->assertCount(2, $journal);
		$this->assertSame(SilenceCloseService::WARNING_TYPE, $journal[1]['type']);
		$this->assertSame('2026-01-31', $journal[1]['closesOn']);
		$this->assertTrue($journal[1]['product']);
		$this->assertSame('', $journal[1]['by']);
	}//end testWarnJournalsAndSaves()

	/**
	 * A close aborts with the period as reason, then marks the reloaded case
	 * as closed by the product and journals it.
	 *
	 * @return void
	 */
	public function testCloseAbortsThenRecordsTheAutoClose(): void {
		$reason = 'Closed after 30 days without activity, the period this case type declares.';
		$this->endings->expects($this->once())->method('abort')->with('c-1', $reason, '')->willReturn([]);
		$this->store->expects($this->once())->method('loadCase')->with('c-1')->willReturn(['id' => 'c-1', 'activity' => '[]']);
		$saved = null;
		$this->store->expects($this->once())->method('saveCase')->willReturnCallback(
			static function (array $case) use (&$saved): array {
				$saved = $case;
				return $case;
			}
		);

		$this->service()->close(caseId: 'c-1', decision: ['action' => 'close', 'period' => 30, 'silentDays' => 31, 'closesOn' => '']);

		$this->assertSame('autoClose', $saved[CaseEndingActs::ENDING_FIELD]);
		$entry = json_decode($saved['activity'], true)[0];
		$this->assertSame(
			['autoClose', $reason, true, 30, 31],
			[$entry['type'], $entry['reason'], $entry['product'], $entry['period'], $entry['silentDays']]
		);
	}//end testCloseAbortsThenRecordsTheAutoClose()

	/**
	 * A case the abort refuses stays open, is not touched, and the refusal is logged.
	 *
	 * @return void
	 */
	public function testARefusedAbortLeavesTheCaseOpen(): void {
		$this->endings->method('abort')->willThrowException(new \RuntimeException('a result type is required'));
		$this->store->expects($this->never())->method('loadCase');
		$this->store->expects($this->never())->method('saveCase');
		$this->logger->expects($this->once())->method('info')->with(
			'SilenceCloseService: the case was not closed automatically',
			['caseId' => 'c-1', 'reason' => 'a result type is required']
		);

		$this->service()->close(caseId: 'c-1', decision: ['period' => 30, 'silentDays' => 30]);
	}//end testARefusedAbortLeavesTheCaseOpen()

	/**
	 * A case gone after the abort is not written back.
	 *
	 * @return void
	 */
	public function testACaseGoneAfterTheAbortIsNotSaved(): void {
		$this->endings->method('abort')->willReturn([]);
		$this->store->method('loadCase')->willReturn(null);
		$this->store->expects($this->never())->method('saveCase');

		$this->service()->close(caseId: 'c-1', decision: ['period' => 30, 'silentDays' => 30]);
	}//end testACaseGoneAfterTheAbortIsNotSaved()
}//end class
