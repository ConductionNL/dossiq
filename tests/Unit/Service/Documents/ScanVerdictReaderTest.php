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
	 * A connection whose one SELECT answers these rows in turn, and counts
	 * how many query builders were asked for.
	 *
	 * @param array<int, array<string, mixed>> $rows The recorded rows.
	 * @param int $builders Incremented once per query builder handed out.
	 *
	 * @return IDBConnection&\PHPUnit\Framework\MockObject\MockObject The connection.
	 */
	private function dbRows(array $rows, int &$builders) {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturn('fileid IN (:ids)');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':ids');
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			static function () use ($qb, &$builders) {
				$builders++;
				return $qb;
			}
		);

		return $db;
	}//end dbRows()

	/**
	 * A listing reads every verdict in ONE query, and a file the scanner never
	 * recorded reads not scanned beside the ones it did.
	 *
	 * @return void
	 */
	public function testAListingReadsEveryVerdictInOneQuery(): void {
		$builders = 0;
		$reader = $this->reader(
			true,
			$this->dbRows(
				[
					['fileid' => 11, 'status' => 0, 'check_time' => 1757923200],
					['fileid' => 12, 'status' => 1, 'check_time' => 1757923200],
				],
				$builders
			)
		);

		$verdicts = $reader->verdictsFor(fileIds: [11, 12, 13]);

		self::assertSame(1, $builders, 'one read for the whole listing');
		self::assertSame([11, 12, 13], array_keys($verdicts));
		self::assertSame(ScanVerdictReader::STATE_CLEAN, $verdicts[11]['state']);
		self::assertSame(ScanVerdictReader::STATE_INFECTED, $verdicts[12]['state']);
		self::assertSame(ScanVerdictReader::STATE_NOT_SCANNED, $verdicts[13]['state']);
		self::assertTrue($verdicts[13]['scannerPresent']);
	}//end testAListingReadsEveryVerdictInOneQuery()

	/**
	 * Without a scanner nothing is read, and every file says the scanner is absent.
	 *
	 * @return void
	 */
	public function testAListingWithoutAScannerReadsNothing(): void {
		$builders = 0;
		$reader = $this->reader(false, $this->dbRows([], $builders));

		$verdicts = $reader->verdictsFor(fileIds: [11, 0]);

		self::assertSame(0, $builders);
		self::assertFalse($verdicts[11]['scannerPresent']);
		self::assertSame(ScanVerdictReader::STATE_NOT_SCANNED, $verdicts[0]['state']);
	}//end testAListingWithoutAScannerReadsNothing()

	/**
	 * A listing whose read throws reads not scanned on every row.
	 *
	 * @return void
	 */
	public function testAListingWhoseReadThrowsReadsNotScanned(): void {
		$reader = $this->reader(true, $this->brokenDb());

		$verdicts = $reader->verdictsFor(fileIds: [11]);

		self::assertSame(ScanVerdictReader::STATE_NOT_SCANNED, $verdicts[11]['state']);
		self::assertTrue($verdicts[11]['scannerPresent']);
	}//end testAListingWhoseReadThrowsReadsNotScanned()

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
