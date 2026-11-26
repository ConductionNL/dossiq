<?php

/**
 * SavedMailFiles Unit Tests
 *
 * The file a caller reaches by id (none for a non-positive id, a folder, a
 * missing node or a folder that throws) and its bytes (none, with a warning
 * naming the case and the file, when storage will not give them up).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\SavedMailFiles;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for SavedMailFiles.
 *
 * @covers \OCA\Dossiq\Controller\SavedMailFiles
 */
final class SavedMailFilesTest extends TestCase {

	/**
	 * A root folder whose user folder answers the given node for any id.
	 *
	 * @param mixed  $node   What the user folder answers.
	 * @param string $expect The uid the root folder must be asked for.
	 *
	 * @return IRootFolder
	 */
	private function root(mixed $node, string $expect = 'behandelaar'): IRootFolder {
		$folder = $this->createMock(originalClassName: Folder::class);
		$folder->method('getFirstNodeById')->with(42)->willReturn($node);
		$root = $this->createMock(originalClassName: IRootFolder::class);
		$root->method('getUserFolder')->with($expect)->willReturn($folder);
		return $root;
	}//end root()

	/**
	 * A file is answered as the caller reaches it; a folder or nothing is not.
	 *
	 * @return void
	 */
	public function testAFileIsResolvedAsTheCaller(): void {
		$file = $this->createMock(originalClassName: File::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$found = new SavedMailFiles(rootFolder: $this->root(node: $file), logger: $logger);
		$this->assertSame(expected: $file, actual: $found->nodeFor(uid: 'behandelaar', fileId: 42));

		$folder = $this->createMock(originalClassName: Folder::class);
		$this->assertNull(actual: (new SavedMailFiles(rootFolder: $this->root(node: $folder), logger: $logger))->nodeFor(uid: 'behandelaar', fileId: 42));
		$this->assertNull(actual: (new SavedMailFiles(rootFolder: $this->root(node: null), logger: $logger))->nodeFor(uid: 'behandelaar', fileId: 42));
	}//end testAFileIsResolvedAsTheCaller()

	/**
	 * A non-positive id is not looked up; a folder that throws answers nothing.
	 *
	 * @return void
	 */
	public function testABadIdOrAFailingFolderAnswersNothing(): void {
		$root = $this->createMock(originalClassName: IRootFolder::class);
		$root->expects($this->once())->method('getUserFolder')->willThrowException(new RuntimeException('no such user'));
		$files = new SavedMailFiles(rootFolder: $root, logger: $this->createMock(originalClassName: LoggerInterface::class));

		$this->assertNull(actual: $files->nodeFor(uid: 'behandelaar', fileId: 0));
		$this->assertNull(actual: $files->nodeFor(uid: 'behandelaar', fileId: -3));
		$this->assertNull(actual: $files->nodeFor(uid: 'behandelaar', fileId: 42));
	}//end testABadIdOrAFailingFolderAnswersNothing()

	/**
	 * The bytes are answered as a string; storage that throws is logged with the
	 * case and the file, and answers nothing.
	 *
	 * @return void
	 */
	public function testTheContentIsReadAndAStorageFailureIsLogged(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'SavedMailController: a saved mail file could not be read from storage',
			['case' => 'case-114', 'file' => 42, 'error' => 'storage is gone']
		);
		$files = new SavedMailFiles(rootFolder: $this->createMock(originalClassName: IRootFolder::class), logger: $logger);

		$readable = $this->createMock(originalClassName: File::class);
		$readable->method('getContent')->willReturn("From: a@b.nl\r\n\r\ntekst");
		$this->assertSame(expected: "From: a@b.nl\r\n\r\ntekst", actual: $files->contentOf(node: $readable, caseId: 'case-114', fileId: 42));

		$broken = $this->createMock(originalClassName: File::class);
		$broken->method('getContent')->willThrowException(new RuntimeException('storage is gone'));
		$this->assertNull(actual: $files->contentOf(node: $broken, caseId: 'case-114', fileId: 42));
	}//end testTheContentIsReadAndAStorageFailureIsLogged()
}//end class
