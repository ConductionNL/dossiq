<?php

/**
 * The process-mining report's query: what is refused, and the filters it yields.
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
 * Each case was recorded on the controller methods this class replaced.
 *
 * @covers \OCA\Dossiq\Service\ProcessMining\ReportRequest
 * @uses \OCA\Dossiq\Service\Reporting\ReportPeriod
 */
class ReportRequestTest extends TestCase {

	/**
	 * Queries and what they yield: the refusal, else the filters.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null, 2: array<string, string>|null}>
	 */
	public static function queries(): array {
		$query = static fn (array $set): array => [...['from' => null, 'to' => null, 'caseType' => null, 'period' => null, 'view' => null, 'format' => 'json', 'statistic' => 'mean'], ...$set];

		return [
			'all filters' => [$query(['from' => '2026-01-01', 'to' => '2026-02-01', 'caseType' => 'ct']), null, ['from' => '2026-01-01', 'to' => '2026-02-01', 'caseType' => 'ct']],
			'a date the calendar lacks' => [$query(['from' => '2026-02-30']), 'from must be a Y-m-d date', null],
			'an unpadded date' => [$query(['to' => '2026-2-1']), 'to must be a Y-m-d date', null],
			'an empty to' => [$query(['to' => '', 'period' => 'all']), 'to must be a Y-m-d date', null],
			'a case type that is no string' => [$query(['caseType' => 5]), 'caseType must be a string', null],
			'an unknown format' => [$query(['format' => 'xml']), 'format must be json or csv', null],
			'an unknown statistic' => [$query(['format' => 'csv', 'statistic' => 'mode']), 'statistic must be mean or median', null],
			'the filter is refused first' => [$query(['from' => 'x', 'period' => 'decade', 'format' => 'xml']), 'from must be a Y-m-d date', null],
			'all reads from the earliest date' => [$query(['caseType' => '', 'period' => 'all']), null, ['from' => '1970-01-01']],
			'a preset sets from' => [$query(['to' => '2026-06-01', 'period' => '3m']), null, ['from' => '2026-03-01', 'to' => '2026-06-01']],
			'from wins over a preset' => [$query(['from' => '2026-05-05', 'period' => '3m']), null, ['from' => '2026-05-05']],
		];
	}//end queries()

	/**
	 * The refusal and the filters of one query.
	 *
	 * @param array<string, mixed>       $query   The query.
	 * @param string|null                $refusal The expected refusal.
	 * @param array<string, string>|null $filters The expected filters, when accepted.
	 *
	 * @dataProvider queries
	 *
	 * @return void
	 */
	public function testRefusesOrFilters(array $query, ?string $refusal, ?array $filters): void {
		$dates = $this->createMock(CaseDateNormaliser::class);
		$dates->method('today')->willReturn(new DateTimeImmutable('2026-06-15'));
		$requests = new ReportRequest(periods: new ReportPeriod(), dates: $dates);

		$this->assertSame(expected: $refusal, actual: $requests->refusal(query: $query));
		if ($filters !== null) {
			$this->assertSame(expected: $filters, actual: $requests->filters(query: $query));
		}
	}//end testRefusesOrFilters()
}//end class
