<?php

/**
 * FindsFilesById unit tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\FindsFilesById;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

/**
 * A host exposing the trait's lookup.
 */
class FileLookupHost {

	use FindsFilesById;

	/**
	 * @param object|null $fileService The managed file service, if any.
	 * @param IRootFolder $rootFolder  The root folder.
	 * @param int         $fileId      The file id.
	 * @return File|null The file.
	 */
	public function find(?object $fileService, IRootFolder $rootFolder, int $fileId): ?File {
		return $this->fileById(fileService: $fileService, rootFolder: $rootFolder, fileId: $fileId);
	}//end find()
}//end class

/**
 * @covers \OCA\Dossiq\Service\Support\FindsFilesById
 */
class FindsFilesByIdTest extends TestCase {

	/**
	 * No id is no file, and nothing is asked.
	 *
	 * @return void
	 */
	public function testANonPositiveIdIsNoFile(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects(self::never())->method('getById');

		self::assertNull((new FileLookupHost())->find(fileService: null, rootFolder: $root, fileId: 0));
		self::assertNull((new FileLookupHost())->find(fileService: null, rootFolder: $root, fileId: -3));
	}//end testANonPositiveIdIsNoFile()

	/**
	 * The managed file service answers first, and the root is not searched.
	 *
	 * @return void
	 */
	public function testTheFileServiceAnswersFirst(): void {
		$file    = $this->createMock(File::class);
		$service = new class ($file) {
			/** @var array<int, int> */
			public array $asked = [];

			/**
			 * @param File $file The file it holds.
			 */
			public function __construct(private File $file) {
			}//end __construct()

			/**
			 * @param int $id The id.
			 * @return File The file.
			 */
			public function getFileById(int $id): File {
				$this->asked[] = $id;
				return $this->file;
			}//end getFileById()
		};
		$root = $this->createMock(IRootFolder::class);
		$root->expects(self::never())->method('getById');

		self::assertSame($file, (new FileLookupHost())->find(fileService: $service, rootFolder: $root, fileId: 42));
		self::assertSame([42], $service->asked);
	}//end testTheFileServiceAnswersFirst()

	/**
	 * When the service has no file, the first File node under the root answers.
	 *
	 * @return void
	 */
	public function testTheRootFolderIsTheFallback(): void {
		$file    = $this->createMock(File::class);
		$service = new class {
			/**
			 * @return null Nothing managed.
			 */
			public function getFileById(int $id): ?File {
				return null;
			}//end getFileById()
		};
		$root = $this->createMock(IRootFolder::class);
		$root->expects(self::exactly(2))
			->method('getById')
			->with(7)
			->willReturn([$this->createMock(Folder::class), $file]);

		self::assertSame($file, (new FileLookupHost())->find(fileService: $service, rootFolder: $root, fileId: 7));
		self::assertSame($file, (new FileLookupHost())->find(fileService: new \stdClass(), rootFolder: $root, fileId: 7));
	}//end testTheRootFolderIsTheFallback()

	/**
	 * Only folders, or nothing, under the root is no file.
	 *
	 * @return void
	 */
	public function testNoFileNodeIsNoFile(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->willReturnOnConsecutiveCalls([$this->createMock(Folder::class)], []);

		self::assertNull((new FileLookupHost())->find(fileService: null, rootFolder: $root, fileId: 9));
		self::assertNull((new FileLookupHost())->find(fileService: null, rootFolder: $root, fileId: 9));
	}//end testNoFileNodeIsNoFile()
}//end class
