<?php

/**
 * Unit tests for DeadlineReportingService.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\DeadlineReportingService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\DeadlineReportingService
 * @uses \OCA\Dossiq\Service\Termijn\TermCaseTypeResolver
 * @uses \OCA\Dossiq\Service\Termijn\TermOutcome
 */
class DeadlineReportingServiceTest extends TestCase {
	private FakeTermijnStore $objects;
	private DeadlineReportingService $service;

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'termijn_instance_schema' => 'deadlineInstance',
					'termijn_definitie_schema' => 'deadlineDefinition',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					'dwangsom_uitbetaling_schema' => 'dwangsomUitbetaling',
					default => '',
				};
			},
		);

		$this->service = new DeadlineReportingService($settings);

		$this->objects->seed('caseType', ['id' => 'ct-omv', 'identifier' => 'omgevingsvergunning-regulier', 'title' => 'Omgevingsvergunning (regulier)']);
		$this->objects->seed('caseType', ['id' => 'ct-woo', 'identifier' => 'woo-verzoek', 'title' => 'Woo-verzoek']);

		// Seed 5 instances spread across Q2-2026 for one zaaktype. A term
		// instance has no caseType of its own: it is read through its case.
		for ($i = 1; $i <= 5; $i++) {
			$this->objects->seed('case', ['id' => 'case-q2-' . $i, 'caseType' => 'ct-omv']);
			$this->objects->seed('deadlineInstance', [
				'id' => 'ti-q2-' . $i,
				'case' => 'case-q2-' . $i,
				'startDate' => '2026-05-0' . $i . 'T10:00:00+00:00',
				'endDateCurrent' => '2026-07-0' . $i,
				'status' => ($i <= 3 ? 'completed' : ($i === 4 ? 'exceeded' : 'lopend')),
				'countExtensions' => ($i === 5 ? 1 : 0),
			]);
		}
	}

	/**
	 * @return void
	 */
	public function testQuarterlyReportAggregatesPerType(): void {
		$report = $this->service->generateQuarterlyReport('2026-Q2');
		self::assertSame('2026-04-01', $report['from']);
		self::assertSame('2026-06-30', $report['until']);

		$row = $report['perType']['omgevingsvergunning-regulier'];
		self::assertSame(5, $row['totaal']);
		self::assertSame(60.0, $row['binnenTermijnPct']);
		self::assertSame(1, $row['overschrijdingen']);
		self::assertSame(1, $row['verlengingen']);
	}

	/**
	 * Seed a term in 2026 Q4 on a new case of the given case type.
	 *
	 * @param string               $id         The instance id.
	 * @param string|null          $caseType   The case's case type id, or null for no case.
	 * @param array<string, mixed> $overrides  Instance fields.
	 *
	 * @return void
	 */
	private function q4Term(string $id, ?string $caseType, array $overrides = []): void {
		if ($caseType !== null) {
			$this->objects->seed('case', ['id' => 'case-' . $id, 'caseType' => $caseType]);
		}

		$this->objects->seed('deadlineInstance', array_merge([
			'id' => $id,
			'case' => 'case-' . $id,
			'startDate' => '2026-10-05T09:00:00+02:00',
			'endDateCurrent' => '2026-11-02',
			'status' => 'lopend',
		], $overrides));
	}//end q4Term()

	/**
	 * REQ-WTR-004: Woo terms are reported under the Woo case type.
	 *
	 * @return void
	 */
	public function testWooTermsAreGroupedUnderTheWooCaseType(): void {
		foreach (['w1', 'w2', 'w3'] as $id) {
			$this->q4Term($id, 'ct-woo');
		}

		foreach (['o1', 'o2'] as $id) {
			$this->q4Term($id, 'ct-omv');
		}

		$report = $this->service->generateQuarterlyReport('2026-Q4');

		self::assertSame(3, $report['perType']['woo-verzoek']['totaal']);
		self::assertSame('Woo-verzoek', $report['perType']['woo-verzoek']['title']);
		self::assertSame(2, $report['perType']['omgevingsvergunning-regulier']['totaal']);
	}//end testWooTermsAreGroupedUnderTheWooCaseType()

	/**
	 * REQ-WTR-004: nothing lands under `unknown`; a term with neither a case
	 * nor a definition case type is `unresolved` and named; a deleted case
	 * falls back to the definition.
	 *
	 * @return void
	 */
	public function testNoTermIsReportedUnderUnknown(): void {
		$this->objects->seed('deadlineDefinition', ['id' => 'td-woo', 'caseType' => 'woo-verzoek']);
		$this->q4Term('deleted-case-with-definition', null, ['deadlineDefinition' => 'td-woo']);
		$this->q4Term('orphan', null);

		$report = $this->service->generateQuarterlyReport('2026-Q4');

		self::assertArrayNotHasKey('unknown', $report['perType']);
		self::assertSame(1, $report['perType']['woo-verzoek']['totaal']);
		self::assertSame(1, $report['perType']['unresolved']['totaal']);
		self::assertSame(['orphan'], $report['metadata']['unresolvedInstances']);
	}//end testNoTermIsReportedUnderUnknown()

	/**
	 * REQ-WTR-005: one met, one two days late, one running, one suspended.
	 *
	 * @return void
	 */
	public function testMetMissedRunningAndSuspendedAreCounted(): void {
		$this->q4Term('on-time', 'ct-woo', ['status' => 'completed', 'voltooiDatum' => '2026-10-30']);
		$this->q4Term('late', 'ct-woo', ['status' => 'completed', 'voltooiDatum' => '2026-11-04']);
		$this->q4Term('running', 'ct-woo');
		$this->q4Term('suspended', 'ct-woo', ['status' => 'paused']);

		$woo = $this->service->generateQuarterlyReport('2026-Q4')['perType']['woo-verzoek'];

		self::assertSame(4, $woo['received']);
		self::assertSame(1, $woo['met']);
		self::assertSame(1, $woo['missed']);
		self::assertSame(1, $woo['running']);
		self::assertSame(1, $woo['suspended']);
		self::assertSame(50.0, $woo['metShare']);
		self::assertSame(4, $woo['totaal'], 'the existing keys keep their meaning');
	}//end testMetMissedRunningAndSuspendedAreCounted()

	/**
	 * @return void
	 */
	public function testCsvExportShape(): void {
		$report = $this->service->generateQuarterlyReport('2026-Q2');
		$csv = $this->service->quarterlyReportAsCsv($report);
		$lines = explode("\n", $csv);
		self::assertStringStartsWith('caseType,totaal,binnenTermijnPct', $lines[0]);
		self::assertStringStartsWith('omgevingsvergunning-regulier,5,60', $lines[1]);
	}

	/**
	 * @return void
	 */
	public function testKpiSummary(): void {
		$k = $this->service->getTermijnKpi();
		self::assertSame(5, $k['totalZaken']);
		self::assertSame(60.0, $k['withinTermijnPercent']);
		self::assertSame(1, $k['overrunCount']);
	}

	/**
	 * @return void
	 */
	public function testInvalidPeriodeRaises(): void {
		$this->expectException(\RuntimeException::class);
		$this->service->generateQuarterlyReport('not-a-quarter');
	}

	/**
	 * @return void
	 */
	public function testDwangsomAuditReportFiltersByYear(): void {
		// Seed two uitbetalingen, one in 2026 one in 2025.
		$this->objects->seed('dwangsomUitbetaling', [
			'id' => 'u-1',
			'reference' => 'REF-1',
			'amount' => 35700,
			'actualPaymentDate' => '2026-04-20',
			'betalingsreferentie' => 'ERP-1',
			'status' => 'paid',
			'wettelijkeGrondslag' => 'AWB 4:17',
			'iban' => 'NL91ABNA0417164300',
		]);
		$this->objects->seed('dwangsomUitbetaling', [
			'id' => 'u-2',
			'reference' => 'REF-2',
			'amount' => 50000,
			'actualPaymentDate' => '2025-12-31',
			'betalingsreferentie' => 'ERP-2',
			'status' => 'paid',
		]);

		$report = $this->service->generateDwangsomAuditReport(2026);
		self::assertSame(1, $report['summary']['count']);
		self::assertSame(35700, $report['summary']['totalCents']);
	}
}
