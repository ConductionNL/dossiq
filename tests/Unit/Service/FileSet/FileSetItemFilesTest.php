<?php

/**
 * Both files of a file set item are read through the document loader, with a name and a type.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\FileSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-the-delivered-rendition-is-compared-with-its-original-req-wds-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\FileSet;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Service\FileSet\FileSetItemFiles;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-WDS-004: what the compare dialog reads.
 *
 * @covers \OCA\Dossiq\Woo\FileSetItemFiles
 *
 * @uses \OCA\Dossiq\Woo\WooCaseDocuments
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class FileSetItemFilesTest extends TestCase {

	/**
	 * A set with one redacted item and one item whose original is gone.
	 *
	 * @var array<string, mixed>
	 */
	private array $set = [
		'case'  => 'case-1',
		'items' => [
			['deliveredRef' => 'doc-red', 'originalRef' => 'doc-orig', 'fileName' => 'besluit-gelakt.pdf', 'classification' => 'deels_openbaar'],
			['deliveredRef' => 'doc-gone', 'originalRef' => 'doc-gone', 'fileName' => 'nota.odt', 'classification' => 'openbaar'],
		],
	];

	/**
	 * The service over an in-memory register with two documents.
	 *
	 * @return FileSetItemFiles
	 */
	private function files(): FileSetItemFiles {
		$store = new InMemoryRegister();
		$store->seed(schema: 'document', uuid: 'doc-orig', row: ['fileName' => 'besluit.docx', 'content' => base64_encode('origineel')]);
		$store->seed(schema: 'document', uuid: 'doc-red', row: ['title' => 'besluit-gelakt.pdf', 'format' => 'application/pdf', 'content' => base64_encode('gelakt')]);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'document_schema' => 'document'][$key] ?? $default)
		);

		return new FileSetItemFiles(
			documents: new WooCaseDocuments(settingsService: $settings, rootFolder: $this->createMock(IRootFolder::class), logger: new NullLogger()),
		);
	}//end files()

	/**
	 * Both sides are named and typed; a missing format comes from the extension.
	 *
	 * @return void
	 */
	public function testDescribeNamesAndTypesBothFiles(): void {
		$sides = $this->files()->describe(set: $this->set, index: 0);

		$this->assertSame(
			[
				'original'  => ['fileName' => 'besluit.docx', 'mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'readable' => true],
				'delivered' => ['fileName' => 'besluit-gelakt.pdf', 'mimeType' => 'application/pdf', 'readable' => true],
			],
			$sides
		);
	}//end testDescribeNamesAndTypesBothFiles()

	/**
	 * Each side answers its own bytes.
	 *
	 * @return void
	 */
	public function testReadAnswersTheBytesOfEachSide(): void {
		$files = $this->files();

		$this->assertSame('origineel', $files->read(set: $this->set, index: 0, side: 'original')['bytes']);
		$this->assertSame('gelakt', $files->read(set: $this->set, index: 0, side: 'delivered')['bytes']);
	}//end testReadAnswersTheBytesOfEachSide()

	/**
	 * A document that cannot be read is unreadable, never an empty file.
	 *
	 * @return void
	 */
	public function testAnUnreadableDocumentIsNotAnEmptyFile(): void {
		$files = $this->files();

		$this->assertNull($files->read(set: $this->set, index: 1, side: 'delivered'));
		$sides = $files->describe(set: $this->set, index: 1);
		$this->assertFalse($sides['delivered']['readable']);
		$this->assertSame('nota.odt', $sides['delivered']['fileName']);
		$this->assertSame('application/vnd.oasis.opendocument.text', $sides['delivered']['mimeType']);
	}//end testAnUnreadableDocumentIsNotAnEmptyFile()

	/**
	 * An unknown item or side answers nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownItemOrSideAnswersNothing(): void {
		$files = $this->files();

		$this->assertNull($files->describe(set: $this->set, index: 7));
		$this->assertNull($files->describe(set: $this->set, index: -1));
		$this->assertNull($files->describe(set: ['items' => 'not a list'], index: 0));
		$this->assertNull($files->read(set: $this->set, index: 0, side: 'bijlage'));
	}//end testAnUnknownItemOrSideAnswersNothing()
}//end class
