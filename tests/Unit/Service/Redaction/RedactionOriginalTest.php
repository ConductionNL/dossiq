<?php

/**
 * RedactionOriginal Unit Tests
 *
 * The original's informatieobject is read or refused as unreadable; its
 * file is the stored id, else the legacy file found by name, else refused
 * with the cause attached.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Redaction
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Redaction;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\WooCaseDocumentsInterface;
use OCA\Dossiq\Service\Redaction\RedactionOriginal;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for RedactionOriginal.
 *
 * @covers \OCA\Dossiq\Service\Redaction\RedactionOriginal
 */
final class RedactionOriginalTest extends TestCase {

	/**
	 * An original over the given informatieobject and legacy file lookup.
	 *
	 * @param array<string, mixed>|null $meta   What the documents answer.
	 * @param ZgwDocumentService|null   $zgw    The legacy file lookup.
	 *
	 * @return RedactionOriginal
	 */
	private function original(?array $meta, ?ZgwDocumentService $zgw=null): RedactionOriginal {
		$documents = $this->createMock(originalClassName: WooCaseDocumentsInterface::class);
		$documents->method('meta')->willReturn($meta);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new RedactionOriginal(
			documents: $documents,
			zgw: ($zgw ?? $this->createMock(originalClassName: ZgwDocumentService::class)),
			l10n: $l10n,
		);
	}//end original()

	/**
	 * The informatieobject is answered, and an unreadable one is refused as indeterminate.
	 *
	 * @return void
	 */
	public function testTheInformatieobjectIsReadOrRefused(): void {
		$this->assertSame(expected: ['fileId' => 7], actual: $this->original(meta: ['fileId' => 7])->read(documentRef: 'doc-1'));
		$this->assertNull(actual: $this->original(meta: null)->meta(documentRef: 'doc-1'));

		try {
			$this->original(meta: null)->read(documentRef: 'doc-1');
			$this->fail(message: 'an unreadable document must be refused');
		} catch (RefusedException $e) {
			$this->assertSame(expected: 'document-unreadable', actual: $e->getRule());
			$this->assertSame(expected: RefusedException::STATUS_INDETERMINATE, actual: $e->getStatus());
		}
	}//end testTheInformatieobjectIsReadOrRefused()

	/**
	 * The stored file id wins; a legacy document's file is found by its name.
	 *
	 * @return void
	 */
	public function testTheFileIsTheStoredIdElseFoundByName(): void {
		$zgw = $this->createMock(originalClassName: ZgwDocumentService::class);
		$zgw->expects($this->once())->method('getFileId')->with('doc-1', 'brief.pdf')->willReturn(42);
		$original = $this->original(meta: [], zgw: $zgw);

		$this->assertSame(expected: 7, actual: $original->fileIdOf(documentRef: 'doc-1', original: ['fileId' => 7]));
		$this->assertSame(expected: 42, actual: $original->fileIdOf(documentRef: 'doc-1', original: ['fileName' => 'brief.pdf']));
	}//end testTheFileIsTheStoredIdElseFoundByName()

	/**
	 * No file, or a name that finds none, is refused with the cause attached.
	 *
	 * @return void
	 */
	public function testNoFileIsRefusedWithTheCause(): void {
		$zgw = $this->createMock(originalClassName: ZgwDocumentService::class);
		$cause = new RuntimeException('not in the folder');
		$zgw->method('getFileId')->willThrowException($cause);
		$original = $this->original(meta: [], zgw: $zgw);

		try {
			$original->fileIdOf(documentRef: 'doc-1', original: ['bestandsnaam' => 'oud.docx']);
			$this->fail(message: 'a missing file must be refused');
		} catch (RefusedException $e) {
			$this->assertSame(expected: 'file-unresolved', actual: $e->getRule());
			$this->assertSame(expected: RefusedException::STATUS_UNPROCESSABLE, actual: $e->getStatus());
			$this->assertSame(expected: $cause, actual: $e->getPrevious());
		}

		try {
			$original->fileIdOf(documentRef: 'doc-2', original: []);
			$this->fail(message: 'a document without a file must be refused');
		} catch (RefusedException $e) {
			$this->assertNull(actual: $e->getPrevious());
		}
	}//end testNoFileIsRefusedWithTheCause()
}//end class
