<?php

/**
 * The silence period: off by default, announced first, attributed afterwards.
 *
 * The decision is driven here rather than the sweep, because the interesting
 * cases are all dates: the day before the warning, the day of it, the day of
 * the close, and the day after a warning has already gone out. A test that had
 * to stand a register up to reach them would reach one of them.
 *
 * 🔑 THE WARNING MUST NOT POSTPONE THE CLOSE IT ANNOUNCES. A silence count
 * taken from the last write would be reset by the service's own warning, so no
 * case would ever reach its period, and the feature would look exactly like
 * being switched off. That is the last assertion in this file.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\Lifecycle\LifecycleCaseTypeRules;
use OCA\Dossiq\Service\Lifecycle\SilenceCloseService;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;

/**
 * What a declared silence period decides, one day at a time.
 *
 * @covers \OCA\Dossiq\Service\Lifecycle\SilenceCloseService
 */
class AutoCloseOnSilenceJobTest extends TestCase {

	// The one normaliser, on a stated zone. Real and not a double: every
	// assertion in this file is about how many DAYS a case has been silent,
	// and a stubbed parser would be answering that question itself.
	use MakesCaseDateNormaliser;

	/**
	 * Today, as every case in this file counts from it.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $today;

	/**
	 * Fix today once, so a test cannot straddle midnight.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->today = new DateTimeImmutable('2026-09-15');
	}//end setUp()

	/**
	 * The service, against a case type declaring the given period.
	 *
	 * @param int $period The silence period in days, 0 for off.
	 * @param int $warning How many days ahead the applicant is warned.
	 *
	 * @return SilenceCloseService The service under test.
	 */
	private function service(int $period, int $warning = 7): SilenceCloseService {
		$rules = $this->createMock(originalClassName: LifecycleCaseTypeRules::class);
		$rules->method('silenceDays')->willReturn($period);
		$rules->method('warningDays')->willReturn($warning);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return new SilenceCloseService(
			store: $this->createMock(originalClassName: CaseStatusStore::class),
			rules: $rules,
			endings: $this->createMock(originalClassName: CaseEndingActs::class),
			journal: new CaseJournal(userSession: $session),
			dates: $this->caseDates(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end service()

	/**
	 * A case whose last activity was the given number of days ago.
	 *
	 * @param int $daysAgo How long ago the last activity was.
	 * @param array<int, array<string, mixed>> $extra Further journal entries, newest last.
	 *
	 * @return array<string, mixed> The case payload.
	 */
	private function caseSilentFor(int $daysAgo, array $extra = []): array {
		$entries = [
			['type' => 'suspend', 'at' => $this->today->modify('-'.$daysAgo.' days')->format('c')],
		];

		return ['id' => 'case-1', 'caseType' => 'ct-1', 'activity' => json_encode(array_merge($entries, $extra))];
	}//end caseSilentFor()

	/**
	 * A case type that declares nothing never closes a case, whatever happens.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testOffByDefault(): void {
		$decision = $this->service(period: 0)->decide(
			case: $this->caseSilentFor(daysAgo: 400),
			today: $this->today,
		);

		$this->assertSame(expected: 'none', actual: $decision['action'], message: 'a case type declaring nothing must close nothing');
	}//end testOffByDefault()

	/**
	 * A bezwaar waiting on the indiener is closed once the period is reached.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testACaseReachingItsPeriodIsClosed(): void {
		$decision = $this->service(period: 60)->decide(
			case: $this->caseSilentFor(daysAgo: 60),
			today: $this->today,
		);

		$this->assertSame(expected: 'close', actual: $decision['action']);
		$this->assertSame(expected: 60, actual: $decision['silentDays']);
		$this->assertSame(expected: 60, actual: $decision['period']);
	}//end testACaseReachingItsPeriodIsClosed()

	/**
	 * The applicant is warned before it happens, and told the date.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testTheApplicantIsWarnedFirst(): void {
		$decision = $this->service(period: 60, warning: 7)->decide(
			case: $this->caseSilentFor(daysAgo: 53),
			today: $this->today,
		);

		$this->assertSame(expected: 'warn', actual: $decision['action']);
		$this->assertSame(expected: '2026-09-22', actual: $decision['closesOn'], message: 'the warning has to name the date');
	}//end testTheApplicantIsWarnedFirst()

	/**
	 * A day before the warning is due, nothing happens.
	 *
	 * The control for the warning above: without it, a decision that always
	 * answered `warn` would pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testNothingHappensBeforeTheWarningIsDue(): void {
		$decision = $this->service(period: 60, warning: 7)->decide(
			case: $this->caseSilentFor(daysAgo: 52),
			today: $this->today,
		);

		$this->assertSame(expected: 'none', actual: $decision['action']);
	}//end testNothingHappensBeforeTheWarningIsDue()

	/**
	 * The applicant is told once, not once a day for a week.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testTheWarningIsNotRepeatedDaily(): void {
		$case = $this->caseSilentFor(
			daysAgo: 53,
			extra: [
				[
					'type' => SilenceCloseService::WARNING_TYPE,
					'at' => $this->today->modify('-1 day')->format('c'),
				],
			],
		);

		$this->assertSame(expected: 'none', actual: $this->service(period: 60)->decide(case: $case, today: $this->today)['action']);
	}//end testTheWarningIsNotRepeatedDaily()

	/**
	 * The service's own warning does not reset the silence it announced.
	 *
	 * This is the failure that would look identical to the feature being off,
	 * so it gets the sharpest assertion in the file: a case one day short of
	 * its period, warned yesterday, still closes on the day it was always
	 * going to close.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testAWarningDoesNotPostponeTheClose(): void {
		$case = $this->caseSilentFor(
			daysAgo: 60,
			extra: [
				[
					'type' => SilenceCloseService::WARNING_TYPE,
					'at' => $this->today->modify('-1 day')->format('c'),
				],
			],
		);

		$decision = $this->service(period: 60)->decide(case: $case, today: $this->today);

		$this->assertSame(expected: 'close', actual: $decision['action']);
		$this->assertSame(expected: 60, actual: $decision['silentDays'], message: 'the count must ignore the service\'s own writes');
	}//end testAWarningDoesNotPostponeTheClose()

	/**
	 * Anything a person does resets the silence, which is the point of it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testARealActivityResetsTheSilence(): void {
		$case = $this->caseSilentFor(
			daysAgo: 60,
			extra: [['type' => 'hold', 'at' => $this->today->modify('-2 days')->format('c')]],
		);

		$decision = $this->service(period: 60)->decide(case: $case, today: $this->today);

		$this->assertSame(expected: 'none', actual: $decision['action']);
		$this->assertSame(expected: 2, actual: $decision['silentDays']);
	}//end testARealActivityResetsTheSilence()

	/**
	 * A case with no journal counts from the row's own last update.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testACaseWithNoJournalCountsFromTheRow(): void {
		$case = [
			'id' => 'case-1',
			'caseType' => 'ct-1',
			'@self' => ['updated' => $this->today->modify('-90 days')->format('c')],
		];

		$this->assertSame(expected: 'close', actual: $this->service(period: 60)->decide(case: $case, today: $this->today)['action']);
	}//end testACaseWithNoJournalCountsFromTheRow()

	/**
	 * A case nothing can be counted from is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testACaseWithNoReadableMomentIsLeftAlone(): void {
		$decision = $this->service(period: 60)->decide(
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			today: $this->today,
		);

		$this->assertSame(expected: 'none', actual: $decision['action']);
	}//end testACaseWithNoReadableMomentIsLeftAlone()
}//end class
