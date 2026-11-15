<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A file nobody checked must not read like a file the checker cleared.
 *
 * That is the whole value of the Scan column, and it is exactly the thing an
 * optional dependency makes easy to get wrong: `files_antivirus` is not
 * installed on most instances, its table goes with it, and a reader that
 * treats "no answer" as "fine" would paint every document on such an instance
 * green. Company ADR-102 says an absent scanner reads as not scanned, never as
 * clean, so every uncertainty in here is paired with the one case that does
 * read clean.
 *
 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
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

/**
 * The three states, and the fact that only one of them is a claim of safety.
 */
class ScanVerdictReaderTest extends TestCase {

	/**
	 * A connection whose one SELECT answers the given row.
	 *
	 * `onlyMethods` throughout: a double that may invent a method is a double
	 * that can pass while the real class 500s, which is the failure this suite
	 * exists to make impossible.
	 *
	 * @param array<string, mixed>|false $row The recorded row, or false for none.
	 *
	 * @return IDBConnection&\PHPUnit\Framework\MockObject\MockObject The connection.
	 */
	private function db(array|false $row) {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($row);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('fileid = :id');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':id');
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}//end db()

	/**
	 * A connection whose SELECT throws, as it does when the table is absent.
	 *
	 * @return IDBConnection&\PHPUnit\Framework\MockObject\MockObject The connection.
	 */
	private function brokenDb() {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new \RuntimeException('no such table'));

		return $db;
	}//end brokenDb()

	/**
	 * The reader, with the scanner present or absent.
	 *
	 * @param bool                                 $installed Whether the scanner is on the instance.
	 * @param IDBConnection|null                   $db        The connection, or none.
	 *
	 * @return ScanVerdictReader The reader.
	 */
	private function reader(bool $installed, ?IDBConnection $db = null): ScanVerdictReader {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => ($app === 'files_antivirus' && $installed)
		);

		return new ScanVerdictReader(
			$apps,
			($db ?? $this->db(false)),
			$this->createMock(LoggerInterface::class),
		);
	}//end reader()

	/**
	 * A file the scanner cleared reads clean, with the moment of the check.
	 *
	 * @return void
	 */
	public function testAClearedFileReadsClean(): void {
		$reader = $this->reader(true, $this->db(['status' => 0, 'check_time' => 1757923200]));

		$verdict = $reader->verdictFor(fileId: 42);

		self::assertSame(ScanVerdictReader::STATE_CLEAN, $verdict['state']);
		self::assertSame('2025-09-15T08:00:00+00:00', $verdict['scannedAt']);
		self::assertTrue($verdict['scannerPresent']);
	}//end testAClearedFileReadsClean()

	/**
	 * A file the scanner found something in reads infected.
	 *
	 * @return void
	 */
	public function testAFlaggedFileReadsInfected(): void {
		$reader = $this->reader(true, $this->db(['status' => 1, 'check_time' => 1757923200]));

		$verdict = $reader->verdictFor(fileId: 42);

		self::assertSame(ScanVerdictReader::STATE_INFECTED, $verdict['state']);
		self::assertTrue($verdict['scannerPresent']);
	}//end testAFlaggedFileReadsInfected()

	/**
	 * A scanner that has not reached the file yet reads not scanned.
	 *
	 * @return void
	 */
	public function testAFileTheScannerHasNotReachedReadsNotScanned(): void {
		$reader = $this->reader(true, $this->db(false));

		$verdict = $reader->verdictFor(fileId: 42);

		self::assertSame(ScanVerdictReader::STATE_NOT_SCANNED, $verdict['state']);
		self::assertNull($verdict['scannedAt']);
		self::assertTrue($verdict['scannerPresent'], 'the scanner is here, it just has not got there');
	}//end testAFileTheScannerHasNotReachedReadsNotScanned()

	/**
	 * An instance with no scanner reads not scanned, and says the scanner is absent.
	 *
	 * @return void
	 */
	public function testAnAbsentScannerReadsNotScanned(): void {
		$reader = $this->reader(false, $this->db(['status' => 0, 'check_time' => 1757923200]));

		$verdict = $reader->verdictFor(fileId: 42);

		self::assertSame(ScanVerdictReader::STATE_NOT_SCANNED, $verdict['state']);
		self::assertFalse($verdict['scannerPresent']);
		self::assertFalse($reader->scannerPresent());
	}//end testAnAbsentScannerReadsNotScanned()

	/**
	 * A status this reader does not know is not a clean bill of health.
	 *
	 * @return void
	 */
	public function testAnUnknownStatusReadsNotScanned(): void {
		$reader = $this->reader(true, $this->db(['status' => 7, 'check_time' => 1757923200]));

		$verdict = $reader->verdictFor(fileId: 42);

		self::assertSame(ScanVerdictReader::STATE_NOT_SCANNED, $verdict['state']);
		self::assertNull($verdict['scannedAt']);
	}//end testAnUnknownStatusReadsNotScanned()

	/**
	 * A read that throws answers not scanned rather than failing the row.
	 *
	 * @return void
	 */
	public function testAMissingTableReadsNotScanned(): void {
		$reader = $this->reader(true, $this->brokenDb());

		$verdict = $reader->verdictFor(fileId: 42);

		self::assertSame(ScanVerdictReader::STATE_NOT_SCANNED, $verdict['state']);
	}//end testAMissingTableReadsNotScanned()

	/**
	 * A clean row with no recorded time is still clean, with no time.
	 *
	 * @return void
	 */
	public function testACleanRowWithoutATimeKeepsTheVerdict(): void {
		$reader = $this->reader(true, $this->db(['status' => 0, 'check_time' => 0]));

		$verdict = $reader->verdictFor(fileId: 42);

		self::assertSame(ScanVerdictReader::STATE_CLEAN, $verdict['state']);
		self::assertNull($verdict['scannedAt']);
	}//end testACleanRowWithoutATimeKeepsTheVerdict()

	/**
	 * A file id that is not one is refused before any query runs.
	 *
	 * @return void
	 */
	public function testAnImpossibleFileIdIsNotAsked(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);

		$reader = new ScanVerdictReader($apps, $db, $this->createMock(LoggerInterface::class));

		self::assertSame(
			ScanVerdictReader::STATE_NOT_SCANNED,
			$reader->verdictFor(fileId: 0)['state'],
		);
	}//end testAnImpossibleFileIdIsNotAsked()
}//end class
