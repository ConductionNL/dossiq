<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Tests
 * @package   OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Controller\LinkedDocumentsController;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentDefaults;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\Zaakdossier\LinkedDocumentsReader;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A case folder that a parallel request is creating.
 *
 * Opening the Documents tab of a case with no folder yet sends OpenRegister's
 * folder read and dossiq's linked documents at once. Both create the folder,
 * and the loser met a LockedException that answered 500 on
 * `GET /api/cases/{id}/dossier/linked` (13 October, a Woo request).
 *
 * @spec openspec/specs/document-projection/spec.md
 */
class CaseFolderLockTest extends TestCase {

	private const CASE_ID = '9addbef9-a4a1-4626-8789-d05990dd4113';

	/**
	 * A file service whose folder is locked for the first $locked asks.
	 *
	 * @param int         $locked The number of asks that meet a lock.
	 * @param Folder|null $folder What it answers once the lock is gone.
	 *
	 * @return object The file service double, counting its asks in `$asks`.
	 */
	private function fileServiceLockedFor(int $locked, ?Folder $folder): object {
		return new class ($locked, $folder) {
			/** @var int How often the folder was asked for. */
			public int $asks = 0;

			/**
			 * @param int         $locked The asks that meet a lock.
			 * @param Folder|null $folder The folder after the lock.
			 */
			public function __construct(private int $locked, private ?Folder $folder) {
			}

			/**
			 * @param mixed $objectEntity The object uuid.
			 * @param mixed $registerId   The register.
			 *
			 * @return Folder|null The folder.
			 *
			 * @throws LockedException While the lock lasts.
			 */
			public function getObjectFolder(mixed $objectEntity, mixed $registerId = null): ?Folder {
				$this->asks++;
				if ($this->asks <= $this->locked) {
					throw new LockedException(path: '/openregister/files/Open Registers/Dossiq Register/' . (string)$objectEntity);
				}

				return $this->folder;
			}
		};
	}//end fileServiceLockedFor()

	/**
	 * Settings that hand out the given file service and an object service.
	 *
	 * @param object $fileService The file service.
	 *
	 * @return SettingsService The settings double.
	 */
	private function settingsWith(object $fileService): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getFileService')->willReturn($fileService);
		$settings->method('getObjectService')->willReturn(
			new class {
				/**
				 * @param array<string, mixed> $query The query.
				 *
				 * @return array<int, mixed> No joins.
				 */
				public function searchObjects(array $query = []): array {
					return [];
				}

				/**
				 * @param string               $register The register slug.
				 * @param string               $schema   The schema slug.
				 * @param array<string, mixed> $filters  The filters.
				 *
				 * @return array<int, mixed> No joins.
				 */
				public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
					return [];
				}
			}
		);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ([
				'register' => 'dossiq',
				'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
			][$key] ?? $default)
		);

		return $settings;
	}//end settingsWith()

	/**
	 * The real projection service over the given settings.
	 *
	 * @param SettingsService $settings The settings.
	 *
	 * @return DocumentProjectionService The service.
	 */
	private function projectionOver(SettingsService $settings): DocumentProjectionService {
		$store = $this->createMock(originalClassName: DocumentRecordStore::class);

		return new DocumentProjectionService(
			store: $store,
			defaults: new DocumentDefaults(
				store: $store,
				caseTypes: $this->createMock(originalClassName: CaseTypeResolver::class),
				userSession: $this->createMock(originalClassName: IUserSession::class),
			),
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end projectionOver()

	/**
	 * A lock that lifts while the lookup waits finds the folder the other request made.
	 *
	 * @return void
	 */
	public function testALockThatLiftsFindsTheFolder(): void {
		$folder = $this->createMock(originalClassName: Folder::class);
		$files = $this->fileServiceLockedFor(locked: 1, folder: $folder);

		$found = $this->projectionOver(settings: $this->settingsWith(fileService: $files))->folderOf(objectId: self::CASE_ID);

		$this->assertSame(expected: $folder, actual: $found);
		$this->assertSame(expected: 2, actual: $files->asks, message: 'asked once more after the lock');
	}//end testALockThatLiftsFindsTheFolder()

	/**
	 * A lock that stays is a RuntimeException, after a bounded number of asks.
	 *
	 * @return void
	 */
	public function testALockThatStaysIsReportedNotRetriedForever(): void {
		$files = $this->fileServiceLockedFor(locked: 99, folder: null);

		try {
			$this->projectionOver(settings: $this->settingsWith(fileService: $files))->folderOf(objectId: self::CASE_ID);
			$this->fail(message: 'a folder that stays locked must not read as a case without a folder');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'object_folder_busy', actual: $e->getMessage());
			$this->assertInstanceOf(expected: LockedException::class, actual: $e->getPrevious());
		}

		$this->assertSame(expected: 3, actual: $files->asks);
	}//end testALockThatStaysIsReportedNotRetriedForever()

	/**
	 * Through the endpoint the Documents tab calls: a lock answers 503, never 500.
	 *
	 * @return void
	 */
	public function testTheLinkedDocumentsEndpointAnswers503OnALockThatStays(): void {
		$settings = $this->settingsWith(fileService: $this->fileServiceLockedFor(locked: 99, folder: null));
		$reader = new LinkedDocumentsReader(
			settingsService: $settings,
			store: $this->createMock(originalClassName: DocumentRecordStore::class),
			projection: $this->projectionOver(settings: $settings),
			urlGenerator: $this->createMock(originalClassName: IURLGenerator::class),
		);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(originalClassName: IUser::class));

		$controller = new LinkedDocumentsController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			linkedDocuments: $reader,
			reader: $this->createMock(originalClassName: InformatieobjectReader::class),
			userSession: $session,
		);

		$response = $controller->index(caseId: self::CASE_ID);

		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
	}//end testTheLinkedDocumentsEndpointAnswers503OnALockThatStays()

	/**
	 * Through the endpoint: a lock that lifts answers 200 with the rows.
	 *
	 * @return void
	 */
	public function testTheLinkedDocumentsEndpointAnswers200WhenTheLockLifts(): void {
		$settings = $this->settingsWith(
			fileService: $this->fileServiceLockedFor(locked: 1, folder: $this->createMock(originalClassName: Folder::class))
		);
		$reader = new LinkedDocumentsReader(
			settingsService: $settings,
			store: $this->createMock(originalClassName: DocumentRecordStore::class),
			projection: $this->projectionOver(settings: $settings),
			urlGenerator: $this->createMock(originalClassName: IURLGenerator::class),
		);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(originalClassName: IUser::class));

		$controller = new LinkedDocumentsController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			linkedDocuments: $reader,
			reader: $this->createMock(originalClassName: InformatieobjectReader::class),
			userSession: $session,
		);

		$response = $controller->index(caseId: self::CASE_ID);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $response->getData());
	}//end testTheLinkedDocumentsEndpointAnswers200WhenTheLockLifts()
}//end class
