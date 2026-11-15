<?php

/**
 * Dossier uploads: who may upload, how a request's files are read and which
 * files are refused as executable.
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
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler
 */
class DossierUploadHandlerTest extends TestCase {

	/**
	 * The dossier orchestrator.
	 *
	 * @var ZaakdossierService&MockObject
	 */
	private ZaakdossierService&MockObject $files;

	/**
	 * The case access guard.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard&MockObject $guard;

	/**
	 * Temp files written by a test.
	 *
	 * @var list<string>
	 */
	private array $tmp = [];

	/**
	 * Fresh collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->files = $this->createMock(ZaakdossierService::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
	}//end setUp()

	/**
	 * Remove the temp files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->tmp as $path) {
			if (is_file($path) === true) {
				unlink($path);
			}
		}
	}//end tearDown()

	/**
	 * The handler under test.
	 *
	 * @return DossierUploadHandler
	 */
	private function handler(): DossierUploadHandler {
		return new DossierUploadHandler($this->files, $this->guard);
	}//end handler()

	/**
	 * A temp file holding $content.
	 *
	 * @param string $content The bytes.
	 *
	 * @return string The path.
	 */
	private function tmpFile(string $content): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'dossiq-upload-test-');
		file_put_contents($path, $content);
		$this->tmp[] = $path;

		return $path;
	}//end tmpFile()

	/**
	 * Upload access is the guard's mutation answer for that user and case.
	 *
	 * @return void
	 */
	public function testUploadAccessIsTheCaseMutationAccess(): void {
		$user = $this->createMock(IUser::class);
		$this->guard->expects($this->exactly(2))->method('hasCaseMutationAccess')
			->with('case-1', $user)
			->willReturnOnConsecutiveCalls(true, false);

		self::assertTrue($this->handler()->hasCaseUploadAccess($user, 'case-1'));
		self::assertFalse($this->handler()->hasCaseUploadAccess($user, 'case-1'));
	}//end testUploadAccessIsTheCaseMutationAccess()

	/**
	 * A file is stored with its bytes, and its MIME type overrides the shared format.
	 *
	 * @return void
	 */
	public function testAFileIsStoredWithItsContentAndType(): void {
		$path = $this->tmpFile('%PDF-1.7 body');
		$this->files->expects($this->once())->method('uploadDocument')
			->with('case-1', 'brief.pdf', '%PDF-1.7 body', ['titel' => 'Brief', 'format' => 'application/pdf'])
			->willReturn(['id' => 'doc-1']);

		self::assertSame(
			['name' => 'brief.pdf', 'success' => true, 'informatieobject' => ['id' => 'doc-1']],
			$this->handler()->uploadOne(
				'case-1',
				['name' => 'brief.pdf', 'tmp_name' => $path, 'type' => 'application/pdf'],
				['titel' => 'Brief', 'format' => 'text/plain']
			)
		);
	}//end testAFileIsStoredWithItsContentAndType()

	/**
	 * Without a temp path or a type the content is empty and the metadata unchanged.
	 *
	 * @return void
	 */
	public function testAFileWithoutContentOrTypeKeepsTheSharedMetadata(): void {
		$this->files->expects($this->once())->method('uploadDocument')
			->with('case-1', 'leeg.txt', '', ['format' => 'text/plain'])
			->willReturn(['id' => 'doc-2']);

		$result = $this->handler()->uploadOne('case-1', ['name' => 'leeg.txt', 'type' => ''], ['format' => 'text/plain']);

		self::assertTrue($result['success']);
	}//end testAFileWithoutContentOrTypeKeepsTheSharedMetadata()

	/**
	 * An executable is refused per file, and never stored.
	 *
	 * @return void
	 */
	public function testAnExecutableIsRefusedWithoutStoringIt(): void {
		$this->files->expects($this->never())->method('uploadDocument');

		self::assertSame(
			['name' => 'setup.exe', 'success' => false, 'error' => 'Executable files are not permitted: setup.exe'],
			$this->handler()->uploadOne('case-1', ['name' => 'setup.exe', 'tmp_name' => ''], [])
		);
	}//end testAnExecutableIsRefusedWithoutStoringIt()

	/**
	 * A failing store becomes that file's error, not an exception.
	 *
	 * @return void
	 */
	public function testAFailingStoreIsThatFilesError(): void {
		$this->files->method('uploadDocument')->willThrowException(new RuntimeException('dossier locked'));

		self::assertSame(
			['name' => 'brief.pdf', 'success' => false, 'error' => 'dossier locked'],
			$this->handler()->uploadOne('case-1', ['name' => 'brief.pdf'], [])
		);
	}//end testAFailingStoreIsThatFilesError()

	/**
	 * Metadata is an array as given, decoded JSON, or empty.
	 *
	 * @return void
	 */
	public function testMetadataIsDecoded(): void {
		$handler = $this->handler();

		self::assertSame(['a' => 1], $handler->decodeMetadata(['a' => 1]));
		self::assertSame(['titel' => 'Brief'], $handler->decodeMetadata('{"titel":"Brief"}'));
		self::assertSame([], $handler->decodeMetadata('"just a string"'));
		self::assertSame([], $handler->decodeMetadata('not json'));
		self::assertSame([], $handler->decodeMetadata(''));
		self::assertSame([], $handler->decodeMetadata(42));
	}//end testMetadataIsDecoded()

	/**
	 * A single-file upload is a list of one; nothing usable is an empty list.
	 *
	 * @return void
	 */
	public function testASingleUploadIsAListOfOne(): void {
		$handler = $this->handler();
		$single  = ['name' => 'a.pdf', 'tmp_name' => '/tmp/a', 'type' => 'application/pdf', 'size' => 3];

		self::assertSame([$single], $handler->normaliseUploadedFiles($single));
		self::assertSame([], $handler->normaliseUploadedFiles(null));
		self::assertSame([], $handler->normaliseUploadedFiles(['tmp_name' => '/tmp/a']));
	}//end testASingleUploadIsAListOfOne()

	/**
	 * A multi-file upload is turned from columns into one entry per file, with defaults.
	 *
	 * @return void
	 */
	public function testAMultiUploadIsOneEntryPerFile(): void {
		self::assertSame(
			[
				['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/a', 'size' => 3],
				['name' => 'b.txt', 'type' => '', 'tmp_name' => '', 'size' => 0],
			],
			$this->handler()->normaliseUploadedFiles(
				[
					'name' => ['a.pdf', 'b.txt'],
					'type' => ['application/pdf'],
					'tmp_name' => ['/tmp/a'],
					'size' => [3],
				]
			)
		);
	}//end testAMultiUploadIsOneEntryPerFile()

	/**
	 * Blocked extensions are refused whatever their case or content.
	 *
	 * @return void
	 */
	public function testBlockedExtensionsAreExecutable(): void {
		$handler = $this->handler();
		foreach (['a.exe', 'B.BAT', 'c.cmd', 'd.com', 'e.msi', 'f.scr', 'g.sh', 'h.php', 'i.phar', 'j.dll'] as $name) {
			self::assertTrue($handler->isExecutable($name, ''), $name);
		}

		self::assertFalse($handler->isExecutable('brief.pdf', ''));
		self::assertFalse($handler->isExecutable('brief.pdf', '/no/such/file'));
	}//end testBlockedExtensionsAreExecutable()

	/**
	 * PE, ELF and shebang headers are executable under any name; other bytes are not.
	 *
	 * @return void
	 */
	public function testExecutableMagicBytesAreDetected(): void {
		$handler = $this->handler();

		self::assertTrue($handler->isExecutable('scan.pdf', $this->tmpFile("MZ\x90\x00")));
		self::assertTrue($handler->isExecutable('scan.pdf', $this->tmpFile("\x7fELF")));
		self::assertTrue($handler->isExecutable('notes.txt', $this->tmpFile("#!/bin/sh\n")));
		self::assertFalse($handler->isExecutable('scan.pdf', $this->tmpFile('%PDF')));
		self::assertFalse($handler->isExecutable('leeg.txt', $this->tmpFile('')));
	}//end testExecutableMagicBytesAreDetected()
}//end class
