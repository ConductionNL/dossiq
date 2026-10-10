<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/documents-on-the-case/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Documents\ScanVerdictReader;
use OCA\Dossiq\Service\Zaakdossier\DocumentApprovalClearance;
use OCA\Dossiq\Service\Zaakdossier\DossierRowAnnotator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The listing carries the Scan and Approval column values, read once per listing.
 *
 * @covers \OCA\Dossiq\Service\Zaakdossier\DossierRowAnnotator
 * @uses \OCA\Dossiq\Service\Zaakdossier\DocumentApprovalClearance
 */
class DossierRowAnnotatorTest extends TestCase {

	/**
	 * The clearance reader with its decidiq reads doubled and its marker real.
	 *
	 * @param array<string, array<string, mixed>> $answers The clearance per document id.
	 * @param int $calls Incremented once per forDocuments() call.
	 *
	 * @return DocumentApprovalClearance The reader.
	 */
	private function approvals(array $answers, int &$calls): DocumentApprovalClearance {
		$approvals = $this->getMockBuilder(DocumentApprovalClearance::class)
			->setConstructorArgs([
				$this->createMock(ContainerInterface::class),
				$this->createMock(LoggerInterface::class),
			])
			->onlyMethods(['forDocuments'])
			->getMock();
		$approvals->method('forDocuments')->willReturnCallback(
			static function (array $documentIds) use ($answers, &$calls): array {
				$calls++;
				return array_intersect_key($answers, array_flip($documentIds));
			}
		);

		return $approvals;
	}//end approvals()

	/**
	 * Every row gets its verdict and marker, with ONE scanner read and ONE
	 * decidiq read for the whole listing; an unrouted document gets null.
	 *
	 * @return void
	 */
	public function testEveryRowCarriesItsVerdictAndMarkerFromOneReadEach(): void {
		$verdicts = $this->createMock(ScanVerdictReader::class);
		$verdicts->expects(self::once())->method('verdictsFor')
			->with([11, 12])
			->willReturn([
				11 => ['state' => 'clean', 'scannedAt' => null, 'scannerPresent' => true],
				12 => ['state' => 'infected', 'scannedAt' => null, 'scannerPresent' => true],
			]);

		$calls = 0;
		$annotator = new DossierRowAnnotator(
			$verdicts,
			$this->approvals(
				['doc-a' => ['routed' => true, 'cleared' => false, 'waitingOn' => [['routeName' => 'Teamleider', 'stageName' => 'Akkoord']]]],
				$calls
			),
		);

		$rows = $annotator->annotate([
			['id' => 'doc-a', 'fileId' => 11, 'title' => 'Brief'],
			['@self' => ['id' => 'doc-b'], 'fileId' => '12'],
		]);

		self::assertSame(1, $calls);
		self::assertSame('Brief', $rows[0]['title'], 'the row keeps what it had');
		self::assertSame('clean', $rows[0]['scanVerdict']['state']);
		self::assertSame('infected', $rows[1]['scanVerdict']['state']);
		self::assertFalse($rows[0]['approvalMarker']['cleared']);
		self::assertSame('Teamleider, step "Akkoord"', $rows[0]['approvalMarker']['reason']);
		self::assertNull($rows[1]['approvalMarker'], 'no marker is not the same row as approved');
	}//end testEveryRowCarriesItsVerdictAndMarkerFromOneReadEach()

	/**
	 * A row without a file still gets a Not scanned verdict rather than a gap.
	 *
	 * @return void
	 */
	public function testARowWithoutAFileReadsNotScanned(): void {
		$notScanned = ['state' => 'not-scanned', 'scannedAt' => null, 'scannerPresent' => true];
		$verdicts = $this->createMock(ScanVerdictReader::class);
		$verdicts->method('verdictsFor')->willReturn([]);
		$verdicts->method('verdictFor')->with(0)->willReturn($notScanned);

		$calls = 0;
		$rows = (new DossierRowAnnotator($verdicts, $this->approvals([], $calls)))
			->annotate([['id' => 'doc-a']]);

		self::assertSame($notScanned, $rows[0]['scanVerdict']);
		self::assertNull($rows[0]['approvalMarker']);
	}//end testARowWithoutAFileReadsNotScanned()

	/**
	 * One document's marker, for the approval-markers endpoint.
	 *
	 * @return void
	 */
	public function testOneDocumentsMarker(): void {
		$approvals = $this->getMockBuilder(DocumentApprovalClearance::class)
			->setConstructorArgs([
				$this->createMock(ContainerInterface::class),
				$this->createMock(LoggerInterface::class),
			])
			->onlyMethods(['forDocument'])
			->getMock();
		$approvals->method('forDocument')->willReturnCallback(
			static fn (string $documentId): array => (
				$documentId === 'doc-a'
					? ['routed' => true, 'cleared' => true, 'waitingOn' => []]
					: DocumentApprovalClearance::NOT_ROUTED
			)
		);

		$annotator = new DossierRowAnnotator($this->createMock(ScanVerdictReader::class), $approvals);

		self::assertSame(
			['routed' => true, 'cleared' => true, 'waitingOn' => [], 'reason' => ''],
			$annotator->markerForDocument('doc-a')
		);
		self::assertNull($annotator->markerForDocument('doc-b'));
	}//end testOneDocumentsMarker()

	/**
	 * An empty listing reads nothing at all.
	 *
	 * @return void
	 */
	public function testAnEmptyListingReadsNothing(): void {
		$verdicts = $this->createMock(ScanVerdictReader::class);
		$verdicts->expects(self::never())->method('verdictsFor');

		$calls = 0;
		$rows = (new DossierRowAnnotator($verdicts, $this->approvals([], $calls)))->annotate([]);

		self::assertSame([], $rows);
		self::assertSame(0, $calls);
	}//end testAnEmptyListingReadsNothing()
}//end class
