<?php

/**
 * DocumentDefaults::integrityOf: the sha256 block of a file, and when it is left out.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/document-projection/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Zaakdossier\DocumentDefaults;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCP\Files\File;
use OCP\Files\NotPermittedException;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\DocumentDefaults
 */
class DocumentDefaultsIntegrityTest extends TestCase {

	/**
	 * The defaults service; integrityOf uses none of its collaborators.
	 *
	 * @return DocumentDefaults The service.
	 */
	private function defaults(): DocumentDefaults {
		return new DocumentDefaults(
			store: $this->createMock(DocumentRecordStore::class),
			caseTypes: $this->createMock(CaseTypeResolver::class),
			userSession: $this->createMock(IUserSession::class),
		);
	}//end defaults()

	/**
	 * A file of the given size.
	 *
	 * @param int $size The size in bytes.
	 *
	 * @return File&MockObject The file.
	 */
	private function file(int $size): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn($size);
		return $file;
	}//end file()

	/**
	 * A readable file gets the sha256 of its bytes, with a date.
	 *
	 * @return void
	 */
	public function testAReadableFileIsHashed(): void {
		$file = $this->file(size: 11);
		$file->expects(self::once())->method('fopen')->with('r')->willReturnCallback(
			static function () {
				$stream = fopen('php://memory', 'r+');
				fwrite($stream, 'hello world');
				rewind($stream);
				return $stream;
			}
		);

		$block = $this->defaults()->integrityOf(node: $file);

		self::assertSame(['algorithm', 'value', 'date'], array_keys($block));
		self::assertSame('sha256', $block['algorithm']);
		self::assertSame(hash('sha256', 'hello world'), $block['value']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $block['date']);
	}//end testAReadableFileIsHashed()

	/**
	 * A file above 256 MiB is not opened and gets no block; at the limit it is still hashed.
	 *
	 * @return void
	 */
	public function testAFileAboveTheLimitIsNotHashed(): void {
		$big = $this->file(size: 268435457);
		$big->expects(self::never())->method('fopen');
		self::assertSame([], $this->defaults()->integrityOf(node: $big));

		$atLimit = $this->file(size: 268435456);
		$atLimit->expects(self::once())->method('fopen')->willReturn(false);
		self::assertSame([], $this->defaults()->integrityOf(node: $atLimit));
	}//end testAFileAboveTheLimitIsNotHashed()

	/**
	 * A file that cannot be opened gets no block instead of an error.
	 *
	 * @return void
	 */
	public function testAFileThatCannotBeOpenedGetsNoBlock(): void {
		$file = $this->file(size: 10);
		$file->method('fopen')->willThrowException(new NotPermittedException('locked'));

		self::assertSame([], $this->defaults()->integrityOf(node: $file));
	}//end testAFileThatCannotBeOpenedGetsNoBlock()
}//end class
