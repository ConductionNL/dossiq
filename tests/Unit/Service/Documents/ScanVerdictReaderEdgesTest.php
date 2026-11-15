<?php

/**
 * The virus-scan verdict when the scanner cannot be asked or the scan time is unusable.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Documents
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

namespace OCA\Dossiq\Tests\Unit\Service\Documents;

use OCA\Dossiq\Service\Documents\ScanVerdictReader;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An app manager that throws, and a recorded scan without a usable check time.
 *
 * @covers \OCA\Dossiq\Service\Documents\ScanVerdictReader
 */
class ScanVerdictReaderEdgesTest extends TestCase {

	private function db(array $row): IDBConnection {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($row);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('fileid = :id');

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':id');
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}

	private function installedScanner(): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('files_antivirus')->willReturn(true);

		return $apps;
	}

	public function testAnAppManagerThatThrowsMeansNoScannerAndIsLogged(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willThrowException(new RuntimeException('boom'));
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('debug')->with('dossiq could not ask whether the virus scanner is installed');

		$reader = new ScanVerdictReader($apps, $db, $logger);

		$this->assertFalse($reader->scannerPresent());
		$this->assertSame(['state' => 'not-scanned', 'scannedAt' => null, 'scannerPresent' => false], $reader->verdictFor(12));
	}

	public function testAScanWithoutAUsableCheckTimeHasNoScanMoment(): void {
		foreach ([null, 'yesterday', 0, -5] as $checkTime) {
			$reader = new ScanVerdictReader($this->installedScanner(), $this->db(['status' => 0, 'check_time' => $checkTime]), $this->createMock(LoggerInterface::class));

			$this->assertSame(['state' => 'clean', 'scannedAt' => null, 'scannerPresent' => true], $reader->verdictFor(12), var_export($checkTime, true));
		}
	}

	public function testANumericCheckTimeIsGivenInUtc(): void {
		$reader = new ScanVerdictReader($this->installedScanner(), $this->db(['status' => 1, 'check_time' => '1700000000']), $this->createMock(LoggerInterface::class));

		$this->assertSame(['state' => 'infected', 'scannedAt' => '2023-11-14T22:13:20+00:00', 'scannerPresent' => true], $reader->verdictFor(12));
	}
}
