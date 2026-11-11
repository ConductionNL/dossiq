<?php

/**
 * Golden test for DocumentCopier::copyAll: every result whole, what each copy
 * reads and writes, and that an unreadable document is refused before it is read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\CaseFileCollector;
use OCA\Dossiq\Service\Zaakdossier\DocumentCopier;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DocumentCopierGoldenTest extends TestCase {

	private array $calls = [];

	private function copier(): DocumentCopier {
		$calls  = &$this->calls;
		$reader = $this->createMock(InformatieobjectReader::class);
		$reader->method('guardReadable')->willReturnCallback(
			static function (IUser $user, string $infoObjectId) use (&$calls): ?JSONResponse {
				$calls[] = ['guard', $user->getUID(), $infoObjectId];
				return in_array($infoObjectId, ['secret', 'gone-secret'], true) ? new JSONResponse([], 403) : null;
			}
		);
		$records = [
			'full' => ['fileId' => 11, 'fileName' => 'Advies.pdf', 'format' => 'application/pdf'],
			'bare' => ['fileId' => '12'],
			'secret' => ['fileId' => 13, 'fileName' => 'Geheim.pdf'],
			'nofile' => ['fileName' => 'Leeg.pdf'],
			'folder' => ['fileId' => 14],
			'throws' => ['fileId' => 15, 'fileName' => 'Kapot.pdf'],
			'empty' => ['fileId' => 16, 'fileName' => 'Niets.pdf'],
		];
		$fileService = $this->createMock(ZaakdossierService::class);
		$fileService->method('getInformatieobject')->willReturnCallback(
			static function (string $infoObjectId) use (&$calls, $records): ?array {
				$calls[] = ['record', $infoObjectId];
				return ($records[$infoObjectId] ?? null);
			}
		);
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->willReturnCallback(
			function (int $fileId) use (&$calls): ?Node {
				$calls[] = ['node', $fileId];
				if ($fileId === 14) {
					return $this->createMock(Folder::class);
				}

				$file = $this->createMock(File::class);
				$file->method('getName')->willReturn('file-' . $fileId . '.bin');
				$file->method('getContent')->willReturn('bytes-' . $fileId);
				$file->method('getMimeType')->willReturn('text/plain');
				return $file;
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			static function (string $userId) use (&$calls, $folder): Folder {
				$calls[] = ['folder', $userId];
				return $folder;
			}
		);
		$collector = $this->createMock(CaseFileCollector::class);
		$collector->method('collect')->willReturnCallback(
			static function (string $caseId, string $fileName, string $content, array $provenance, string $mimeType = '') use (&$calls): string {
				unset($provenance['at']);
				$calls[] = ['collect', $caseId, $fileName, $content, $provenance, $mimeType];
				if ($fileName === 'Kapot.pdf') {
					throw new RuntimeException('disk full');
				}

				return ($fileName === 'Niets.pdf') ? '' : 'copy-' . count($calls);
			}
		);

		return new DocumentCopier(reader: $reader, fileService: $fileService, rootFolder: $root, collector: $collector);
	}//end copier()

	public function testEveryResultWhole(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler-1');

		$results = $this->copier()->copyAll(user: $user, ids: ['full', 'secret', 'bare', 'unknown', 'nofile', 'folder', 'throws', 'empty', 7], targetCaseId: 'case-2');

		$this->assertSame(
			[
				['id' => 'full', 'success' => true, 'copyId' => 'copy-5'],
				['id' => 'secret', 'success' => false, 'error' => 'Insufficient clearance'],
				['id' => 'bare', 'success' => true, 'copyId' => 'copy-11'],
				['id' => 'unknown', 'success' => false, 'error' => 'Document or file not found'],
				['id' => 'nofile', 'success' => false, 'error' => 'Document or file not found'],
				['id' => 'folder', 'success' => false, 'error' => 'Document or file not found'],
				['id' => 'throws', 'success' => false, 'error' => 'disk full'],
				['id' => 'empty', 'success' => false, 'error' => 'The copy could not be written'],
				['id' => '7', 'success' => false, 'error' => 'Document or file not found'],
			],
			$results
		);
		$this->assertSame(
			[
				['guard', 'handler-1', 'full'],
				['record', 'full'],
				['folder', 'handler-1'],
				['node', 11],
				['collect', 'case-2', 'Advies.pdf', 'bytes-11', ['kind' => 'copy', 'document' => 'full', 'by' => 'handler-1'], 'application/pdf'],
				['guard', 'handler-1', 'secret'],
				['guard', 'handler-1', 'bare'],
				['record', 'bare'],
				['folder', 'handler-1'],
				['node', 12],
				['collect', 'case-2', 'file-12.bin', 'bytes-12', ['kind' => 'copy', 'document' => 'bare', 'by' => 'handler-1'], 'text/plain'],
				['guard', 'handler-1', 'unknown'],
				['record', 'unknown'],
				['guard', 'handler-1', 'nofile'],
				['record', 'nofile'],
				['guard', 'handler-1', 'folder'],
				['record', 'folder'],
				['folder', 'handler-1'],
				['node', 14],
				['guard', 'handler-1', 'throws'],
				['record', 'throws'],
				['folder', 'handler-1'],
				['node', 15],
				['collect', 'case-2', 'Kapot.pdf', 'bytes-15', ['kind' => 'copy', 'document' => 'throws', 'by' => 'handler-1'], 'text/plain'],
				['guard', 'handler-1', 'empty'],
				['record', 'empty'],
				['folder', 'handler-1'],
				['node', 16],
				['collect', 'case-2', 'Niets.pdf', 'bytes-16', ['kind' => 'copy', 'document' => 'empty', 'by' => 'handler-1'], 'text/plain'],
				['guard', 'handler-1', '7'],
				['record', '7'],
			],
			$this->calls
		);
	}//end testEveryResultWhole()
}//end class
