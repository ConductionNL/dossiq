<?php

/**
 * Unit tests for the Woo report read log.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\EntityAnsweringRegister;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RecordingAuditTrailMapper;
use OCA\Dossiq\Woo\WooReportReadLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A throughput read is written on the audit trail of each case it counted, or refused.
 *
 * @covers \OCA\Dossiq\Woo\WooReportReadLog
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
 */
class WooReportReadLogTest extends TestCase {

	/**
	 * The read log over a store holding the given cases.
	 *
	 * @param RecordingAuditTrailMapper|null $trail The audit trail, or null when OpenRegister has none.
	 * @param list<string> $cases The case uuids that exist.
	 *
	 * @return WooReportReadLog The read log.
	 */
	private function readLog(?RecordingAuditTrailMapper $trail, array $cases = ['case-x', 'case-y']): WooReportReadLog {
		$store = new InMemoryRegister();
		foreach ($cases as $case) {
			$store->seed(schema: 'case', uuid: $case, row: ['title' => 'Woo verzoek']);
		}

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(new EntityAnsweringRegister(register: $store));
		$settings->method('getOpenRegisterClass')->willReturn($trail);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? $default)
		);

		return new WooReportReadLog(settingsService: $settings, logger: $this->createMock(LoggerInterface::class));
	}//end readLog()

	/**
	 * Each case gets one entry with the reader, the time and the scope.
	 *
	 * @return void
	 */
	public function testEachCaseGetsAnEntryWithReaderTimeAndScope(): void {
		$trail = new RecordingAuditTrailMapper();

		$written = $this->readLog(trail: $trail)->recordThroughputRead(
			readerId: 'lead',
			scope: ['from' => '2026-11-01', 'to' => '2026-11-30'],
			caseIds: ['case-x', 'case-y', 'case-x', '']
		);

		$this->assertSame(2, $written);
		$this->assertSame(['case-x', 'case-y'], array_column($trail->rows, 'object'));
		foreach ($trail->rows as $row) {
			$this->assertSame(WooReportReadLog::ACTION_THROUGHPUT, $row['action']);
			$this->assertSame('lead', $row['actor']);
			$this->assertSame('lead', $row['context']['reader']);
			$this->assertSame(['from' => '2026-11-01', 'to' => '2026-11-30'], $row['context']['scope']);
			$this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $row['context']['at']));
		}
	}//end testEachCaseGetsAnEntryWithReaderTimeAndScope()

	/**
	 * A read that counted no case writes nothing and refuses nothing.
	 *
	 * @return void
	 */
	public function testAReadOfNoCaseWritesNothing(): void {
		$this->assertSame(0, $this->readLog(trail: null)->recordThroughputRead(readerId: 'lead', scope: ['case' => 'x'], caseIds: []));
	}//end testAReadOfNoCaseWritesNothing()

	/**
	 * No audit trail, a refusing trail, or a case that cannot be found: the read is refused.
	 *
	 * @return void
	 */
	public function testAReadThatCannotBeRecordedIsRefused(): void {
		$refusing = new RecordingAuditTrailMapper();
		$refusing->failsAll = true;
		$subjects = [
			$this->readLog(trail: null),
			$this->readLog(trail: $refusing),
			$this->readLog(trail: new RecordingAuditTrailMapper(), cases: []),
		];
		foreach ($subjects as $index => $readLog) {
			try {
				$readLog->recordThroughputRead(readerId: 'lead', scope: ['case' => 'case-x'], caseIds: ['case-x']);
				$this->fail('Subject '.$index.' answered an unrecorded read');
			} catch (RefusedException $e) {
				$this->assertSame('woo-throughput-not-recorded', $e->getRule());
				$this->assertSame(503, $e->getStatus());
			}
		}
	}//end testAReadThatCannotBeRecordedIsRefused()
}//end class
