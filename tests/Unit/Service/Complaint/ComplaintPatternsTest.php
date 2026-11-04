<?php

/**
 * Tests for the complaint patterns: the quarter, the counts, the notes, the
 * answer and its PDF. None of them carries an employee.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Complaint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Complaint;

use DateTimeImmutable;
use OCA\Dossiq\Service\Complaint\ComplaintPatternFigures;
use OCA\Dossiq\Service\Complaint\ComplaintPatterns;
use OCA\Dossiq\Service\Complaint\ComplaintPatternsPdf;
use OCA\Dossiq\Service\Complaint\QuarterPeriod;
use OCA\Dossiq\Service\Export\PlainTextPdf;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for QuarterPeriod, ComplaintPatternFigures, ComplaintPatterns and ComplaintPatternsPdf.
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
 */
class ComplaintPatternsTest extends TestCase {

	/**
	 * An empty parameter is the last full quarter; a named one is read as named.
	 *
	 * @return void
	 */
	public function testQuarterFromParam(): void {
		$last = QuarterPeriod::fromParam(param: '', now: new DateTimeImmutable('2026-01-15'));
		$this->assertSame(expected: [2025, 4], actual: [$last->year, $last->quarter]);
		$this->assertSame(expected: ['2025-10-01', '2025-12-31'], actual: [$last->firstDay(), $last->lastDay()]);

		$named = QuarterPeriod::fromParam(param: '2026-Q3', now: new DateTimeImmutable());
		$this->assertSame(expected: '2026-04-01', actual: $named->previous()->firstDay());
		$this->assertSame(expected: ['2025-10', '2026-09'], actual: [$named->trailingMonths()[0], $named->trailingMonths()[11]]);

		$this->assertNull(actual: QuarterPeriod::fromParam(param: '2026-Q5'));
	}//end testQuarterFromParam()

	/**
	 * The counts per category flag a rise above half; the handling figures
	 * count only final dispositions.
	 *
	 * @return void
	 */
	public function testFiguresCountTheQuarterAgainstTheOneBefore(): void {
		$figures = (new ComplaintPatternFigures())->compute(
			period: new QuarterPeriod(year: 2026, quarter: 3),
			complaints: $this->complaints(),
			dispositions: [
				'a1' => ['closureDate' => '2026-07-20', 'opinion' => 'upheld'],
				'a2' => ['closureDate' => '2026-09-30', 'opinion' => 'dismissed'],
			],
		);

		$this->assertSame(expected: ['Wachttijd balie', 'Bejegening'], actual: $figures['byCategory']['labels']);
		$this->assertSame(expected: [3, 1], actual: $figures['byCategory']['counts']);
		$this->assertSame(expected: [200, null], actual: $figures['byCategory']['risePercent']);
		$this->assertSame(expected: ['labels' => ['email'], 'counts' => [4]], actual: $figures['byChannel']);
		$this->assertSame(expected: 4, actual: $figures['count']);
		$this->assertSame(expected: [2, 1, 1], actual: array_slice($figures['byMonth']['counts'], 9));
		$this->assertSame(expected: 2, actual: $figures['handling']['handled']);
		$this->assertSame(expected: 50, actual: $figures['handling']['withinAwbPercent']);
		$this->assertSame(expected: 50, actual: $figures['handling']['upheldPercent']);
		$this->assertSame(
			expected: ['category' => 'Wachttijd balie', 'count' => 3, 'averageDays' => 54, 'upheldPercent' => 50],
			actual: $figures['handling']['resolutionByCategory'][0]
		);
	}//end testFiguresCountTheQuarterAgainstTheOneBefore()

	/**
	 * The answer carries the board's keys, the notes against the targets, the
	 * system report, and no employee anywhere.
	 *
	 * @return void
	 */
	public function testTheAnswerCarriesNoEmployee(): void {
		$store = new class($this->complaints()) {
			/**
			 * @param list<array<string, mixed>> $rows The complaints.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @return list<array<string, mixed>>
			 */
			public function searchObjects(array $query): array {
				return $this->rows;
			}
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => [
			'register' => '1',
			'complaint_schema' => '2',
			'complaint_target_per_month' => '0',
		][$key] ?? $default);

		$answer = (new ComplaintPatterns(
			settingsService: $settings,
			l10n: $this->l10n(),
			figures: new ComplaintPatternFigures()
		))->forQuarter(period: new QuarterPeriod(year: 2026, quarter: 3));

		$this->assertTrue(condition: $answer['available']);
		$this->assertSame(expected: 'Third quarter 2026', actual: $answer['quarterLabel']);
		$this->assertSame(expected: 'Target: at most 0. Above target', actual: $answer['kpis']['perMonthNote']);
		$this->assertSame(expected: 'No complaint was handled this quarter', actual: $answer['kpis']['upheldNote']);
		$this->assertSame(expected: array_fill(0, 12, 0), actual: $answer['byMonth']['target']);
		$this->assertStringContainsString(needle: 'Wachttijd balie (3 complaints, from 1 to 3)', haystack: $answer['findings'][0]['text']);
		$this->assertStringNotContainsString(needle: 'j.jansen', haystack: json_encode($answer));
		$this->assertArrayNotHasKey(key: 'employeeAlerts', array: $answer);

		$pdf = (new ComplaintPatternsPdf(l10n: $this->l10n(), writer: new PlainTextPdf()))->render(patterns: $answer);
		$this->assertStringStartsWith(prefix: '%PDF-1.4', string: $pdf);
		$this->assertStringEndsWith(suffix: "%%EOF\n", string: $pdf);
		$this->assertStringContainsString(needle: '(Third quarter 2026) Tj', haystack: $pdf);
	}//end testTheAnswerCarriesNoEmployee()

	/**
	 * Without the complaint schema the answer says it is not available.
	 *
	 * @return void
	 */
	public function testUnavailableWithoutTheComplaintSchema(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$answer = (new ComplaintPatterns(
			settingsService: $settings,
			l10n: $this->l10n(),
			figures: new ComplaintPatternFigures()
		))->forQuarter(period: new QuarterPeriod(year: 2026, quarter: 3));

		$this->assertSame(expected: ['available' => false], actual: $answer);
	}//end testUnavailableWithoutTheComplaintSchema()

	/**
	 * Complaints over the third quarter of 2026 and the quarter before.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function complaints(): array {
		$row = static fn (string $id, string $date, string $category): array => [
			'id' => $id,
			'receiptDate' => $date,
			'category' => $category,
			'receiptChannel' => 'email',
			'involvedDepartment' => 'Publiekszaken',
			'involvedEmployee' => 'j.jansen',
		];

		return [
			$row('a1', '2026-07-01', 'Wachttijd balie'),
			$row('a2', '2026-07-03', 'Wachttijd balie'),
			$row('a3', '2026-08-10', 'Wachttijd balie'),
			$row('a4', '2026-09-10', 'Bejegening'),
			$row('p1', '2026-05-02', 'Wachttijd balie'),
			$row('p2', '2026-05-03', 'Bejegening'),
		];
	}//end complaints()

	/**
	 * A translator that formats the source text.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l10n->method('n')->willReturnCallback(
			static function (string $one, string $many, int $count, array $parameters = []): string {
				$text = $many;
				if ($count === 1) {
					$text = $one;
				}

				return vsprintf(str_replace('%n', (string)$count, $text), $parameters);
			}
		);

		return $l10n;
	}//end l10n()
}//end class
