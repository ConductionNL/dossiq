<?php

/**
 * Documents copied to another case: the bytes go into the target case's
 * folder as a new document that says where it came from; a document the
 * caller may not read is not copied.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 * @spec openspec/specs/documents-copy-to-case/spec.md
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
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\DocumentCopier
 */
class DocumentCopierTest extends TestCase {

	/**
	 * What the collector was asked to write, per call.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $collected = [];

	/**
	 * The copier over two readable documents and one the caller may not read.
	 *
	 * @return DocumentCopier
	 */
	private function copier(): DocumentCopier {
		$reader = $this->createMock(originalClassName: InformatieobjectReader::class);
		$reader->method('guardReadable')->willReturnCallback(
			callback: static function (IUser $user, string $infoObjectId): ?JSONResponse {
				if ($infoObjectId === 'secret') {
					return new JSONResponse(data: [], statusCode: 403);
				}

				return null;
			}
		);
		$records = [
			'doc-1' => ['id' => 'doc-1', 'fileId' => 11, 'fileName' => 'Advies.pdf', 'format' => 'application/pdf'],
			'doc-2' => ['id' => 'doc-2', 'fileId' => 12, 'fileName' => 'Notitie.docx'],
			'secret' => ['id' => 'secret', 'fileId' => 13, 'fileName' => 'Geheim.pdf'],
		];
		$fileService = $this->createMock(originalClassName: ZaakdossierService::class);
		$fileService->method('getInformatieobject')->willReturnCallback(
			callback: static fn (string $infoObjectId): ?array => $records[$infoObjectId] ?? null
		);

		$folder = $this->createMock(originalClassName: Folder::class);
		$folder->method('getFirstNodeById')->willReturnCallback(
			callback: function (int $fileId): ?File {
				$file = $this->createMock(originalClassName: File::class);
				$file->method('getContent')->willReturn(value: 'bytes-'.$fileId);
				$file->method('getMimeType')->willReturn(value: 'application/pdf');
				return $file;
			}
		);
		$root = $this->createMock(originalClassName: IRootFolder::class);
		$root->method('getUserFolder')->willReturn(value: $folder);

		$collector = $this->createMock(originalClassName: CaseFileCollector::class);
		$collector->method('collect')->willReturnCallback(
			callback: function (string $caseId, string $fileName, string $content, array $provenance, string $mimeType = ''): string {
				$this->collected[] = ['caseId' => $caseId, 'fileName' => $fileName, 'content' => $content, 'provenance' => $provenance];
				return 'copy-'.count($this->collected);
			}
		);

		return new DocumentCopier(reader: $reader, fileService: $fileService, rootFolder: $root, collector: $collector);
	}//end copier()

	/**
	 * The signed-in user.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn(value: 'handler-1');
		return $user;
	}//end user()

	/**
	 * Each readable document lands on the target case with its provenance; the unreadable one does not.
	 *
	 * @return void
	 */
	public function testCopiesEachReadableDocumentWithItsProvenance(): void {
		$results = $this->copier()->copyAll(user: $this->user(), ids: ['doc-1', 'secret', 'doc-2'], targetCaseId: 'case-2');

		self::assertSame(expected: [true, false, true], actual: array_column(array: $results, column_key: 'success'));
		self::assertSame(expected: 'copy-1', actual: $results[0]['copyId']);
		self::assertSame(expected: ['Advies.pdf', 'Notitie.docx'], actual: array_column(array: $this->collected, column_key: 'fileName'));
		self::assertSame(expected: 'bytes-11', actual: $this->collected[0]['content']);
		self::assertSame(expected: 'case-2', actual: $this->collected[0]['caseId']);
		self::assertSame(expected: 'copy', actual: $this->collected[0]['provenance']['kind']);
		self::assertSame(expected: 'doc-1', actual: $this->collected[0]['provenance']['document']);
		self::assertSame(expected: 'handler-1', actual: $this->collected[0]['provenance']['by']);
	}//end testCopiesEachReadableDocumentWithItsProvenance()

	/**
	 * A document that does not exist is reported, not copied.
	 *
	 * @return void
	 */
	public function testAnUnknownDocumentIsReported(): void {
		$results = $this->copier()->copyAll(user: $this->user(), ids: ['gone'], targetCaseId: 'case-2');

		self::assertFalse(condition: $results[0]['success']);
		self::assertSame(expected: [], actual: $this->collected);
	}//end testAnUnknownDocumentIsReported()
}//end class
