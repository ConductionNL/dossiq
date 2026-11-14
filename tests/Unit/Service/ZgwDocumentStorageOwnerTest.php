<?php

/**
 * Where a case document is stored, and who owns that folder.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * OpenRegister FileService stub.
 *
 * The signature mirrors OpenRegister's own
 * (`FileService::getObjectFolder(ObjectEntity|string, int|string|null): ?Folder`),
 * NOT this caller's convenience. `ObjectEntity` cannot be type-hinted here
 * because OpenRegister is an optional runtime dependency and its classes are
 * absent from a unit run, so the union is widened to `object` and the string
 * arm — the only one dossiq uses — keeps its real type.
 */
interface ZgwDocumentFileServiceStub {

	/**
	 * Get or create the Nextcloud folder bound to an OpenRegister object.
	 *
	 * @param object|string $objectEntity The object entity or its UUID.
	 * @param int|string|null $registerId The register, required for the string arm.
	 *
	 * @return Folder|null The folder, or null when it cannot be resolved.
	 */
	public function getObjectFolder(object|string $objectEntity, int|string|null $registerId = null): ?Folder;
}//end interface

/**
 * A case document belongs to the case, not to whoever uploaded it.
 *
 * Until this suite existed `ZgwDocumentService::getUserFolder()` returned
 * `$rootFolder->getUserFolder('admin')` and every document in the instance was
 * created, read and deleted in one person's home. Nothing caught it, and
 * nothing could have: the e2e suite signs in as `admin`, so the writer and the
 * reader were the same account and the bug is INVISIBLE from there. That is
 * why the coverage for it is here rather than in Playwright.
 *
 * @covers \OCA\Dossiq\Service\ZgwDocumentService
 */
class ZgwDocumentStorageOwnerTest extends TestCase {

	/**
	 * A root folder that fails loudly for every account.
	 *
	 * The write path must not need ANY user's home, so the test asserts that
	 * by making every home unavailable rather than by asserting the absence of
	 * one particular uid. A service that reached for the current user instead
	 * of `admin` — the obvious wrong substitution — fails here too.
	 *
	 * @return IRootFolder&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function rootFolderWithNoHomes(): IRootFolder {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willThrowException(
			new \OCP\Files\NotPermittedException('no home is available to this test')
		);
		return $rootFolder;
	}//end rootFolderWithNoHomes()

	/**
	 * A SettingsService whose FileService hands back the given folder.
	 *
	 * @param Folder|null $folder The folder OpenRegister resolves for the object.
	 *
	 * @return SettingsService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function settingsResolving(?Folder $folder): SettingsService {
		$fileService = $this->createMock(ZgwDocumentFileServiceStub::class);
		$fileService->method('getObjectFolder')->willReturn($folder);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getFileService')->willReturn($fileService);
		$settings->method('getConfigValue')->willReturn('dossiq');

		return $settings;
	}//end settingsResolving()

	/**
	 * An upload is written to the OpenRegister object folder, not to a home.
	 *
	 * @return void
	 */
	public function testStoreRawWritesToTheOpenRegisterObjectFolder(): void {
		$file = $this->createMock(File::class);
		$file->expects($this->once())->method('putContent')->with('the bytes');

		$objectFolder = $this->createMock(Folder::class);
		$objectFolder->expects($this->once())
			->method('newFile')
			->with('report.pdf')
			->willReturn($file);

		$service = new ZgwDocumentService(
			rootFolder: $this->rootFolderWithNoHomes(),
			settingsService: $this->settingsResolving($objectFolder),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame(9, $service->storeRaw(
			uuid: 'info-uuid',
			fileName: 'report.pdf',
			content: 'the bytes',
		));
	}//end testStoreRawWritesToTheOpenRegisterObjectFolder()

	/**
	 * The folder is asked for by the informatieobject UUID and the register.
	 *
	 * A folder resolved for the wrong key is still A folder, so the write above
	 * would pass on one. The ARGUMENTS are what makes the file findable again
	 * by a reader that has only the document UUID.
	 *
	 * @return void
	 */
	public function testTheFolderIsResolvedByObjectUuidAndRegister(): void {
		$objectFolder = $this->createMock(Folder::class);
		$objectFolder->method('newFile')->willReturn($this->createMock(File::class));

		$fileService = $this->createMock(ZgwDocumentFileServiceStub::class);
		$fileService->expects($this->once())
			->method('getObjectFolder')
			->with('info-uuid', 'dossiq-register')
			->willReturn($objectFolder);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getFileService')->willReturn($fileService);
		$settings->method('getConfigValue')->with('register')->willReturn('dossiq-register');

		$service = new ZgwDocumentService(
			rootFolder: $this->rootFolderWithNoHomes(),
			settingsService: $settings,
			logger: $this->createMock(LoggerInterface::class),
		);

		$service->storeRaw(uuid: 'info-uuid', fileName: 'report.pdf', content: 'x');
	}//end testTheFolderIsResolvedByObjectUuidAndRegister()

	/**
	 * A document already in the object folder is read from there.
	 *
	 * @return void
	 */
	public function testGetContentReadsTheObjectFolder(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('stored bytes');

		$objectFolder = $this->createMock(Folder::class);
		$objectFolder->method('get')->with('report.pdf')->willReturn($file);

		$service = new ZgwDocumentService(
			rootFolder: $this->rootFolderWithNoHomes(),
			settingsService: $this->settingsResolving($objectFolder),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame('stored bytes', $service->getContent(
			uuid: 'info-uuid',
			fileName: 'report.pdf',
		));
	}//end testGetContentReadsTheObjectFolder()

	/**
	 * A document written BEFORE the move stays readable after it.
	 *
	 * Every already-uploaded document in every install sits in
	 * `admin/files/dossiq/documenten/<uuid>/`. Resolving only the new location
	 * would make all of them undownloadable on upgrade — a data-loss-shaped
	 * regression that no other test in this app would have reported, because
	 * every other test uploads its own fixture first.
	 *
	 * @return void
	 */
	public function testAPreMigrationDocumentIsStillReadable(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('legacy bytes');

		$legacyFolder = $this->createMock(Folder::class);
		$legacyFolder->method('get')->with('report.pdf')->willReturn($file);

		// The object folder resolves, and simply does not hold this file yet.
		$objectFolder = $this->createMock(Folder::class);
		$objectFolder->method('get')->willThrowException(new NotFoundException('not here'));

		$adminHome = $this->createMock(Folder::class);
		$adminHome->method('nodeExists')->with('dossiq/documenten/info-uuid')->willReturn(true);
		$adminHome->method('get')->with('dossiq/documenten/info-uuid')->willReturn($legacyFolder);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->atLeastOnce())
			->method('getUserFolder')
			->with('admin')
			->willReturn($adminHome);

		$service = new ZgwDocumentService(
			rootFolder: $rootFolder,
			settingsService: $this->settingsResolving($objectFolder),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame('legacy bytes', $service->getContent(
			uuid: 'info-uuid',
			fileName: 'report.pdf',
		));
	}//end testAPreMigrationDocumentIsStillReadable()

	/**
	 * With OpenRegister absent the write is REFUSED, not redirected to a home.
	 *
	 * The failure mode this guards is the one the original code shipped:
	 * quietly picking an owner nobody asked for. Refusing is louder and is
	 * recoverable; a document filed in a stranger's home is neither.
	 *
	 * @return void
	 */
	public function testAWriteIsRefusedWhenOpenRegisterIsUnavailable(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getFileService')->willReturn(null);

		$service = new ZgwDocumentService(
			rootFolder: $this->rootFolderWithNoHomes(),
			settingsService: $settings,
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->expectException(RuntimeException::class);
		$service->storeRaw(uuid: 'info-uuid', fileName: 'report.pdf', content: 'x');
	}//end testAWriteIsRefusedWhenOpenRegisterIsUnavailable()
}//end class
