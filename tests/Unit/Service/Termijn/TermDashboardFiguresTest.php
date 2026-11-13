<?php

/**
 * The figures the termijnen dashboard shows: the window, the running terms, the penalties and on-time shares.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use DateTimeImmutable;
use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermCaseTypeResolver;
use OCA\Dossiq\Service\Termijn\TermDashboardFigures;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Termijn\TermDashboardFigures
 * @uses   \OCA\Dossiq\Service\Termijn\TermCaseTypeResolver
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 */
class TermDashboardFiguresTest extends TestCase {

	/**
	 * The store behind the settings bridge.
	 *
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $objects;

	/**
	 * Seed two case types and three cases.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$this->objects->seed('caseType', ['id' => 'ct-woo', 'identifier' => 'woo-verzoek', 'title' => 'Woo-verzoek']);
		$this->objects->seed('caseType', ['id' => 'ct-melding', 'identifier' => 'melding', 'title' => 'Melding']);
		$this->objects->seed('case', ['id' => 'case-w1', 'caseType' => 'ct-woo']);
		$this->objects->seed('case', ['id' => 'case-w2', 'caseType' => 'ct-woo']);
		$this->objects->seed('case', ['id' => 'case-m1', 'caseType' => 'ct-melding']);
	}//end setUp()

	/**
	 * Figures over the store, with an L10N that renders plurals readably.
	 *
	 * @param bool               $withL10n     Whether a translator is present.
	 * @param array<int, string> $unconfigured Config keys that answer empty.
	 *
	 * @return TermDashboardFigures
	 */
	private function figures(bool $withL10n = true, array $unconfigured = []): TermDashboardFigures {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key) use ($unconfigured): string {
				if (in_array($key, $unconfigured, true) === true) {
					return '';
				}

				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					'termijn_definitie_schema' => 'deadlineDefinition',
					MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA => 'accrual',
					default => '',
				};
			},
		);

		$l10n = null;
		if ($withL10n === true) {
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('n')->willReturnCallback(
				static function (string $singular, string $plural, int $count, array $parameters = []): string {
					$text = str_replace('%n', (string)$count, ($count === 1 ? $singular : $plural));
					if (str_contains($text, '%1$s') === true) {
						return vsprintf($text, $parameters);
					}

					return sprintf($text, ...$parameters);
				}
			);
		}

		return new TermDashboardFigures(
			settingsService: $settings,
			caseTypes: new TermCaseTypeResolver(settingsService: $settings),
			l10n: $l10n,
		);
	}//end figures()

	public function testAnExplicitWindowIsCutToDatesAndOpenEndedWithoutAnUntil(): void {
		$figures = $this->figures();

		self::assertSame(
			['from' => '2026-02-01', 'until' => '2026-02-28'],
			$figures->window(today: null, from: '2026-02-01T00:00:00Z', until: '2026-02-28T23:59:59Z')
		);
		self::assertSame(
			['from' => '2026-02-01', 'until' => '2999-12-31'],
			$figures->window(today: null, from: '2026-02-01', until: null)
		);
	}//end testAnExplicitWindowIsCutToDatesAndOpenEndedWithoutAnUntil()

	public function testWithoutAFromTheWindowIsTheCurrentQuarter(): void {
		$figures = $this->figures();

		self::assertSame(['from' => '2026-01-01', 'until' => '2026-03-31'], $figures->window(today: new DateTimeImmutable('2026-01-15'), from: null, until: null));
		self::assertSame(['from' => '2026-04-01', 'until' => '2026-06-30'], $figures->window(today: new DateTimeImmutable('2026-06-30'), from: '', until: '2026-01-01'));
		self::assertSame(['from' => '2026-10-01', 'until' => '2026-12-31'], $figures->window(today: new DateTimeImmutable('2026-11-12'), from: null, until: null));
	}//end testWithoutAFromTheWindowIsTheCurrentQuarter()

	public function testRunningTermsAreCountedDueThisWeekAndOverdue(): void {
		$rows = [
			['id' => 'ti-1', 'case' => 'case-w1', 'status' => 'lopend', 'endDateCurrent' => '2026-06-09T00:00:00Z'],
			['id' => 'ti-2', 'case' => 'case-w2', 'status' => 'verlengd', 'endDateCurrent' => '2026-06-12'],
			['id' => 'ti-3', 'case' => 'case-w1', 'status' => 'paused', 'endDateCurrent' => '2026-06-17'],
			['id' => 'ti-4', 'case' => 'case-m1', 'status' => 'lopend', 'endDateCurrent' => '2026-06-10'],
			['id' => 'ti-5', 'case' => 'case-m1', 'status' => 'lopend', 'endDateCurrent' => '2026-06-18'],
			['id' => 'ti-6', 'case' => 'case-m1', 'status' => 'lopend'],
			['id' => 'ti-7', 'case' => 'case-w1', 'status' => 'afgerond', 'endDateCurrent' => '2026-06-01'],
		];

		$out = $this->figures(unconfigured: [MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA])
			->forDashboard(rows: $rows, today: new DateTimeImmutable('2026-06-10'), perType: []);

		self::assertSame(6, $out['runningCount']);
		self::assertSame(2, $out['runningCaseTypeCount']);
		// 06-10, 06-12 and 06-17 fall in [today, today + 7]; 06-18 does not.
		self::assertSame(3, $out['dueThisWeekCount']);
		self::assertSame('2 at Woo-verzoek', $out['dueThisWeekNote']);
		self::assertSame(1, $out['overdueCount']);
	}//end testRunningTermsAreCountedDueThisWeekAndOverdue()

	public function testWithoutATranslatorTheNotesStayEmpty(): void {
		$this->objects->seed('accrual', ['id' => 'a-1', 'status' => 'lopend', 'cumulativeAmount' => 1500, 'currentDag' => 3]);
		$rows = [['id' => 'ti-1', 'case' => 'case-w1', 'status' => 'lopend', 'endDateCurrent' => '2026-06-11']];

		$out = $this->figures(withL10n: false)->forDashboard(rows: $rows, today: new DateTimeImmutable('2026-06-10'), perType: []);

		self::assertSame('', $out['dueThisWeekNote']);
		self::assertSame('', $out['penaltyRunningNote']);
		self::assertSame(15.0, $out['penaltyRunningEuros']);
	}//end testWithoutATranslatorTheNotesStayEmpty()

	public function testRunningPenaltiesAreSummedInEurosAndTheLongestIsNamed(): void {
		$this->objects->seed('accrual', ['id' => 'a-1', 'status' => 'lopend', 'cumulativeAmount' => 12345, 'currentDag' => 4, 'rateBands' => [['upTo' => 14], ['upTo' => 42], 'junk']]);
		$this->objects->seed('accrual', ['id' => 'a-2', 'status' => 'lopend', 'cumulativeAmount' => 655, 'currentDag' => 9, 'rateBands' => [['upTo' => 28], ['upTo' => 21]]]);
		$this->objects->seed('accrual', ['id' => 'a-3', 'status' => 'gestopt', 'cumulativeAmount' => 99999, 'currentDag' => 50]);

		$out = $this->figures()->forDashboard(rows: [], today: new DateTimeImmutable('2026-06-10'), perType: []);

		self::assertSame(130.0, $out['penaltyRunningEuros']);
		self::assertSame(2, $out['penaltyRunningCount']);
		// The longest-running is a-2 at day 9, whose last rate band ends at day 28.
		self::assertSame('2 cases, the longest at day 9 of 28', $out['penaltyRunningNote']);
	}//end testRunningPenaltiesAreSummedInEurosAndTheLongestIsNamed()

	public function testAPenaltyWithoutRateBandsHasNoLastDay(): void {
		$this->objects->seed('accrual', ['id' => 'a-1', 'status' => 'lopend', 'cumulativeAmount' => 100, 'currentDag' => 7]);

		$out = $this->figures()->forDashboard(rows: [], today: new DateTimeImmutable('2026-06-10'), perType: []);

		self::assertSame('1 case, day 7', $out['penaltyRunningNote']);
		self::assertSame(1.0, $out['penaltyRunningEuros']);
	}//end testAPenaltyWithoutRateBandsHasNoLastDay()

	public function testUnconfiguredPenaltiesAreZero(): void {
		$this->objects->seed('accrual', ['id' => 'a-1', 'status' => 'lopend', 'cumulativeAmount' => 100]);

		$out = $this->figures(unconfigured: [MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA])
			->forDashboard(rows: [], today: new DateTimeImmutable('2026-06-10'), perType: []);

		self::assertSame(0.0, $out['penaltyRunningEuros']);
		self::assertSame(0, $out['penaltyRunningCount']);
		self::assertSame('', $out['penaltyRunningNote']);
		self::assertSame(0, $out['runningCount']);
	}//end testUnconfiguredPenaltiesAreZero()

	public function testOnTimeSharesSkipUndecidedTypesAndFallBackToTheKey(): void {
		$perType = [
			'woo-verzoek' => ['title' => 'Woo-verzoek', 'binnenTermijnPct' => 87.5],
			'melding' => ['title' => '', 'binnenTermijnPct' => 100],
			'bezwaar' => ['title' => 'Bezwaar', 'binnenTermijnPct' => null],
		];

		$out = $this->figures(unconfigured: [MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA])
			->forDashboard(rows: [], today: new DateTimeImmutable('2026-06-10'), perType: $perType);

		self::assertSame(['labels' => ['Woo-verzoek', 'melding'], 'shares' => [87.5, 100.0]], $out['onTimeByCaseType']);
	}//end testOnTimeSharesSkipUndecidedTypesAndFallBackToTheKey()
}//end class
