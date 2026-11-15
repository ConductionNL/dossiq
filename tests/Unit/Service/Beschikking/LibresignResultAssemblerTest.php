<?php

/**
 * Unit tests for LibresignResultAssembler: a signed LibreSign request stores
 * the signed PDF in the case's document storage and reports its new file;
 * validation reports carry the mapped status; and a signed file that cannot
 * be read is refused.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use DateTimeImmutable;
use OCA\Dossiq\Service\Beschikking\LibresignResultAssembler;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\LibresignResultAssembler
 *
 * @uses \OCA\Dossiq\Service\Beschikking\LibresignStatusMapper
 */
class LibresignResultAssemblerTest extends TestCase {

	private IRootFolder&MockObject $root;

	private ZgwDocumentService&MockObject $documents;

	protected function setUp(): void {
		parent::setUp();
		$this->root = $this->createMock(IRootFolder::class);
		$this->documents = $this->createMock(ZgwDocumentService::class);
	}//end setUp()

	/**
	 * Let alice's folder answer the given nodes for file 77.
	 *
	 * @param list<mixed> $nodes The nodes getById answers.
	 */
	private function folderWith(array $nodes): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(77)->willReturn($nodes);
		$this->root->method('getUserFolder')->with('alice')->willReturn($folder);
	}//end folderWith()

	private function signedFile(): File {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('%PDF-signed');
		return $file;
	}//end signedFile()

	public function testASignedRequestIsStoredAndReportsItsNewFile(): void {
		$this->folderWith([$this->signedFile()]);
		$this->documents->expects($this->once())->method('storeRaw')->with('doc-1', 'beschikking-doc-1-signed.pdf', '%PDF-signed')->willReturn(11);
		$this->documents->expects($this->once())->method('getFileId')->with('doc-1', 'beschikking-doc-1-signed.pdf')->willReturn(901);
		$seen = [];
		$assembler = new LibresignResultAssembler(
			$this->root,
			$this->documents,
			signedVersionOf: static function (string $original, int $signed, string $signatory) use (&$seen): void {
				$seen[] = [$original, $signed, $signatory];
			}
		);

		$before = new DateTimeImmutable('-1 second');
		$result = $assembler->assembleSignedResult('doc-1', 'alice', 'req-9', ['file' => ['signedFileId' => '77'], 'certificateSerialNumber' => 'SN-1']);

		$this->assertSame('901', $result['signedBestandId']);
		$this->assertSame('req-9', $result['validationRapportId']);
		$this->assertSame('SN-1', $result['certificateSerialNumber']);
		$this->assertSame('LibreSign', $result['tspProviderEidasId']);
		$this->assertGreaterThanOrEqual($before, new DateTimeImmutable($result['signingMoment']));
		$this->assertSame([['doc-1', 901, 'alice']], $seen);
	}//end testASignedRequestIsStoredAndReportsItsNewFile()

	public function testWithoutASerialTheRequestIdStandsIn(): void {
		$this->folderWith([$this->signedFile()]);
		$this->documents->method('getFileId')->willReturn(901);

		$result = (new LibresignResultAssembler($this->root, $this->documents))->assembleSignedResult('doc-1', 'alice', 'req-9', ['file' => ['signedFileId' => 77]]);

		$this->assertSame('libresign-req-9', $result['certificateSerialNumber']);
	}//end testWithoutASerialTheRequestIdStandsIn()

	public function testASignedFileThatCannotBeReadIsRefused(): void {
		$assembler = new LibresignResultAssembler($this->root, $this->documents);
		$this->documents->expects($this->never())->method('storeRaw');

		foreach ([[], ['file' => ['signedFileId' => 0]]] as $status) {
			try {
				$assembler->assembleSignedResult('doc-1', 'alice', 'req-9', $status);
				$this->fail('Expected a refusal for a status without a signed file.');
			} catch (RuntimeException $e) {
				$this->assertSame('libresign_signed_file_missing', $e->getMessage());
			}
		}
	}//end testASignedFileThatCannotBeReadIsRefused()

	public function testAMissingFolderNodeOrNonFileIsRefused(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnOnConsecutiveCalls([], [$this->createMock(Folder::class)]);
		$this->root->method('getUserFolder')->willReturnOnConsecutiveCalls($folder, $folder, $this->throwException(new NotPermittedException('no folder')));
		$assembler = new LibresignResultAssembler($this->root, $this->documents);
		$this->documents->expects($this->never())->method('storeRaw');

		for ($attempt = 0; $attempt < 3; $attempt++) {
			try {
				$assembler->assembleSignedResult('doc-1', 'alice', 'req-9', ['file' => ['signedFileId' => 77]]);
				$this->fail('Expected a refusal on attempt ' . $attempt . '.');
			} catch (RuntimeException $e) {
				$this->assertSame('libresign_signed_file_missing', $e->getMessage());
			}
		}
	}//end testAMissingFolderNodeOrNonFileIsRefused()

	public function testTheStatusIsReadFromStatusTextThenStatus(): void {
		$assembler = new LibresignResultAssembler($this->root, $this->documents);

		$this->assertSame('signed', $assembler->mapStatus(['statusText' => 'Signed', 'status' => '1']));
		$this->assertSame('declined', $assembler->mapStatus(['status' => '4']));
		$this->assertSame('unknown', $assembler->mapStatus([]));
	}//end testTheStatusIsReadFromStatusTextThenStatus()

	public function testAValidationReportIsValidOnlyWhenSigned(): void {
		$assembler = new LibresignResultAssembler($this->root, $this->documents);

		$signed = $assembler->assembleValidationReport('rap-1', ['status' => 'signed', 'signers' => [['uid' => 'alice']]]);
		$pending = $assembler->assembleValidationReport('rap-2', ['status' => 'able_to_sign']);

		$this->assertSame(
			['rap-1', 'libresign-handtekening-rapport', 'eIDAS / ETSI EN 319 102-1 (LibreSign)', true, 'signed', [['uid' => 'alice']]],
			[$signed['validationRapportId'], $signed['kind'], $signed['norm'], $signed['valid'], $signed['status'], $signed['signers']]
		);
		$this->assertSame([false, 'pending', []], [$pending['valid'], $pending['status'], $pending['signers']]);
		$this->assertInstanceOf(DateTimeImmutable::class, DateTimeImmutable::createFromFormat(DATE_ATOM, $signed['gegenereerdOp']));
	}//end testAValidationReportIsValidOnlyWhenSigned()

	public function testAFailedValidationReportNamesTheApiError(): void {
		$report = (new LibresignResultAssembler($this->root, $this->documents))->assembleFailedValidationReport('rap-3');

		$this->assertSame(
			['rap-3', 'libresign-handtekening-rapport', 'eIDAS / ETSI EN 319 102-1 (LibreSign)', false, 'libresign_api_error'],
			[$report['validationRapportId'], $report['kind'], $report['norm'], $report['valid'], $report['foutmelding']]
		);
		$this->assertArrayHasKey('gegenereerdOp', $report);
	}//end testAFailedValidationReportNamesTheApiError()
}//end class
