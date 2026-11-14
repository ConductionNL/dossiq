<?php

/**
 * The report query beyond the date filters: the period and view refusals, and the presets that set from.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\ProcessMining
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\ProcessMining\ReportRequest;
use OCA\Dossiq\Service\Reporting\ReportPeriod;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\ProcessMining\ReportRequest
 * @uses \OCA\Dossiq\Service\Reporting\ReportPeriod
 */
class ReportRequestPresetsTest extends TestCase {

	/**
	 * The request object over a fixed today.
	 *
	 * @return ReportRequest
	 */
	private function requests(): ReportRequest {
		$dates = $this->createMock(CaseDateNormaliser::class);
		$dates->method('today')->willReturn(new DateTimeImmutable('2026-06-15'));

		return new ReportRequest(periods: new ReportPeriod(), dates: $dates);
	}//end requests()

	/**
	 * Queries the display and download rules refuse, with the message each one gets.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function refusals(): array {
		$ok = ['format' => 'csv', 'statistic' => 'median'];

		return [
			'an unknown period' => [[...$ok, 'period' => 'decade'], 'period must be one of 1m, 3m, 6m, 12m, year, all'],
			'a period that is no string' => [[...$ok, 'period' => 3], 'period must be one of 1m, 3m, 6m, 12m, year, all'],
			'an unknown view' => [[...$ok, 'view' => 'sankey'], 'view must be one of summary, dwell, throughput, bottlenecks, byAssignee'],
			'the period is refused before the view' => [[...$ok, 'period' => 'decade', 'view' => 'sankey'], 'period must be one of 1m, 3m, 6m, 12m, year, all'],
			'the display is refused before the download' => [['view' => 'sankey', 'format' => 'xml'], 'view must be one of summary, dwell, throughput, bottlenecks, byAssignee'],
			'no format at all' => [['statistic' => 'mean'], 'format must be json or csv'],
			'no statistic at all' => [['format' => 'json'], 'statistic must be mean or median'],
			'an empty period and view are accepted' => [[...$ok, 'period' => '', 'view' => ''], null],
			'every known view is accepted' => [[...$ok, 'view' => 'byAssignee'], null],
		];
	}//end refusals()

	/**
	 * The refusal of one query.
	 *
	 * @param array<string, mixed> $query   The query.
	 * @param string|null          $refusal The expected refusal, or null when accepted.
	 *
	 * @dataProvider refusals
	 *
	 * @return void
	 */
	public function testRefusal(array $query, ?string $refusal): void {
		$this->assertSame(expected: $refusal, actual: $this->requests()->refusal(query: $query));
	}//end testRefusal()

	/**
	 * The preset sets from relative to today; without a preset or with an empty one there is no from.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, string>}>
	 */
	public static function presets(): array {
		return [
			'1m' => [['period' => '1m'], ['from' => '2026-06-01']],
			'6m' => [['period' => '6m'], ['from' => '2025-12-01']],
			'12m' => [['period' => '12m'], ['from' => '2025-06-01']],
			'year' => [['period' => 'year'], ['from' => '2026-01-01']],
			'no period' => [[], []],
			'an empty period' => [['period' => ''], []],
			'a period that is no string' => [['period' => 12], []],
			'empty and non-string filters are dropped' => [['from' => '', 'to' => 20260101, 'caseType' => ''], []],
			'only the filters come back' => [['caseType' => 'permit', 'view' => 'dwell', 'format' => 'csv'], ['caseType' => 'permit']],
		];
	}//end presets()

	/**
	 * The filters of one accepted query.
	 *
	 * @param array<string, mixed>  $query   The query.
	 * @param array<string, string> $filters The expected filters.
	 *
	 * @dataProvider presets
	 *
	 * @return void
	 */
	public function testFilters(array $query, array $filters): void {
		$this->assertSame(expected: $filters, actual: $this->requests()->filters(query: $query));
	}//end testFilters()
}//end class
