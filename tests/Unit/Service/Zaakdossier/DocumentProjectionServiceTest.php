<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rules that keep a document's ZGW record in step with its file.
 *
 * The store is doubled here so every test reads as the rule it pins: what a
 * new file gets, what a second write refreshes, what a rename and a delete
 * do, and where an API-first file goes on its first join. The store's own
 * OpenRegister calls are pinned in DocumentRecordStoreTest.
 *
 * @spec openspec/specs/document-projection/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentDefaults;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class DocumentProjectionServiceTest extends TestCase {
	private const CASE_ID = '11111111-1111-4111-8111-111111111111';
	private const OTHER_CASE_ID = '22222222-2222-4222-8222-222222222222';
	private const RECORD_ID = '33333333-3333-4333-8333-333333333333';
	private const TYPE_ID = '44444444-4444-4444-8444-444444444444';
	private const CASE_DIR = '/admin/files/Open Registers/Dossiq Case Management Register/' . self::CASE_ID;

	/** @var DocumentRecordStore&MockObject The store double. */
	private DocumentRecordStore&MockObject $store;

	/** @var CaseTypeResolver&MockObject The case type resolver double. */
	private CaseTypeResolver&MockObject $caseTypes;

	/** @var DocumentProjectionService The service under test. */
	private DocumentProjectionService $service;

	/** @var array<string, Folder|null> The folder the file-service stub answers per object uuid. */
	private array $folders = [];

	/**
	 * A service over a store that knows two cases and one document type.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = $this->createMock(originalClassName: DocumentRecordStore::class);
		$this->caseTypes = $this->createMock(originalClassName: CaseTypeResolver::class);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('jan');
		$user->method('getDisplayName')->willReturn('Jan de Vries');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->store->method('findCase')->willReturnCallback(
			static function (string $caseId): ?array {
				if ($caseId === self::CASE_ID) {
					return ['id' => self::CASE_ID, 'caseType' => 'ct-1', 'assignee' => 'admin'];
				}

				if ($caseId === self::OTHER_CASE_ID) {
					return ['id' => self::OTHER_CASE_ID, 'caseType' => 'ct-1', 'assignee' => 'jan'];
				}

				return null;
			}
		);
		$this->store->method('findDocumentType')->willReturn(
			['id' => self::TYPE_ID, 'title' => 'Aanvraag', 'vertrouwelijkheidaanduiding' => 'openbaar']
		);
		$this->caseTypes->method('effectiveCaseType')->willReturn(['id' => 'ct-1', 'defaultInformatieobjecttype' => self::TYPE_ID]);

		$this->service = $this->serviceOver(store: $this->store, session: $session);
	}//end setUp()

	/**
	 * A service over a store double, with the real defaults on top of it.
	 *
	 * @param DocumentRecordStore&MockObject $store The store double.
	 * @param IUserSession $session The session, for author and direction.
	 *
	 * @return DocumentProjectionService The service.
	 */
	private function serviceOver(DocumentRecordStore&MockObject $store, IUserSession $session): DocumentProjectionService {
		$folders = &$this->folders;
		$fileService = new class ($folders) {
			/**
			 * @param array<string, Folder|null> $folders The folders, by object uuid.
			 */
			public function __construct(private array &$folders) {
			}

			/**
			 * @param mixed $objectEntity The object uuid.
			 * @param mixed $registerId The register.
			 *
			 * @return Folder|null The folder.
			 */
			public function getObjectFolder(mixed $objectEntity, mixed $registerId = null): ?Folder {
				return ($this->folders[(string)$objectEntity] ?? null);
			}
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getFileService')->willReturn($fileService);
		$settings->method('getConfigValue')->willReturn('dossiq');

		return new DocumentProjectionService(
			store: $store,
			defaults: new DocumentDefaults(store: $store, caseTypes: $this->caseTypes, userSession: $session),
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end serviceOver()

	/**
	 * A file double with a path, an id and content to hash.
	 *
	 * @param string $path The node path.
	 * @param int $fileId The file id.
	 * @param string $content The bytes.
	 *
	 * @return File&MockObject The file.
	 */
	private function file(string $path, int $fileId = 501, string $content = 'hello'): File&MockObject {
		$node = $this->createMock(originalClassName: File::class);
		$node->method('getPath')->willReturn($path);
		$node->method('getId')->willReturn($fileId);
		$node->method('getName')->willReturn(basename($path));
		$node->method('getSize')->willReturn(strlen($content));
		$node->method('getMimeType')->willReturn('application/pdf');
		$node->method('fopen')->willReturnCallback(
			static function () use ($content) {
				$stream = fopen('php://memory', 'r+');
				fwrite($stream, $content);
				rewind($stream);
				return $stream;
			}
		);

		return $node;
	}//end file()

	/**
	 * @return void
	 */
	public function testADropInTheCaseFolderCreatesTheRecordWithDerivedDefaults(): void {
		$this->store->method('findRecord')->willReturn(null);
		$saved = null;
		$this->store->expects($this->once())->method('saveRecord')->willReturnCallback(
			static function (array $record) use (&$saved): string {
				$saved = $record;
				return self::RECORD_ID;
			}
		);
		$this->store->expects($this->once())->method('ensureJoin')->with(self::CASE_ID, self::RECORD_ID);

		$record = $this->service->projectNode(node: $this->file(path: self::CASE_DIR . '/aanvraagformulier.pdf'));

		$this->assertSame(expected: self::RECORD_ID, actual: $record['id']);
		$this->assertSame(expected: 'aanvraagformulier', actual: $saved['title']);
		$this->assertSame(expected: 'aanvraagformulier.pdf', actual: $saved['fileName']);
		$this->assertSame(expected: 'application/pdf', actual: $saved['format']);
		$this->assertSame(expected: 5, actual: $saved['bestandsomvang']);
		$this->assertSame(expected: 'Jan de Vries', actual: $saved['auteur']);
		$this->assertSame(expected: 'draft', actual: $saved['status']);
		$this->assertSame(expected: self::TYPE_ID, actual: $saved['informatieobjecttype']);
		$this->assertSame(expected: 'openbaar', actual: $saved['vertrouwelijkheidaanduiding']);
		$this->assertSame(expected: 'incoming', actual: $saved['direction'], message: 'jan is not the assignee, so the file came in');
		$this->assertSame(expected: 501, actual: $saved['fileId']);
		$this->assertSame(expected: 'sha256', actual: $saved['integrity']['algorithm']);
		$this->assertSame(expected: hash('sha256', 'hello'), actual: $saved['integrity']['value']);
		$this->assertSame(expected: 'nld', actual: $saved['taal']);
	}//end testADropInTheCaseFolderCreatesTheRecordWithDerivedDefaults()

	/**
	 * @return void
	 */
	public function testTheAssigneesOwnDropIsInternal(): void {
		$this->store->method('findRecord')->willReturn(null);
		$saved = null;
		$this->store->method('saveRecord')->willReturnCallback(
			static function (array $record) use (&$saved): string {
				$saved = $record;
				return self::RECORD_ID;
			}
		);

		$dir = '/admin/files/Open Registers/Dossiq Case Management Register/' . self::OTHER_CASE_ID;
		$this->service->projectNode(node: $this->file(path: $dir . '/memo.pdf'));

		$this->assertSame(expected: 'internal', actual: $saved['direction']);
	}//end testTheAssigneesOwnDropIsInternal()

	/**
	 * @return void
	 */
	public function testTheClassificationFallsBackToTheCasesOwnLevel(): void {
		$store = $this->createMock(originalClassName: DocumentRecordStore::class);
		$store->method('findCase')->willReturn(['id' => self::CASE_ID, 'caseType' => '']);
		$store->method('findRecord')->willReturn(null);
		$store->method('findDocumentType')->willReturn([]);
		$saved = null;
		$store->method('saveRecord')->willReturnCallback(
			static function (array $record) use (&$saved): string {
				$saved = $record;
				return self::RECORD_ID;
			}
		);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$service = $this->serviceOver(store: $store, session: $session);

		$service->projectNode(node: $this->file(path: self::CASE_DIR . '/x.pdf'));

		$this->assertSame(expected: DocumentDefaults::FALLBACK_CLASSIFICATION, actual: $saved['vertrouwelijkheidaanduiding']);
		$this->assertSame(expected: '', actual: $saved['informatieobjecttype']);
		$this->assertSame(expected: '', actual: $saved['auteur']);
	}//end testTheClassificationFallsBackToTheCasesOwnLevel()

	/**
	 * @return void
	 */
	public function testASecondWriteRefreshesTheRecordAndCreatesNothing(): void {
		$existing = [
			'id' => self::RECORD_ID,
			'title' => 'Bouwtekening',
			'fileName' => 'aanvraagformulier.pdf',
			'bestandsomvang' => 5,
			'status' => 'draft',
			'fileId' => 501,
			'@self' => ['id' => self::RECORD_ID],
		];
		$this->store->method('findRecord')->with('', 501)->willReturn($existing);
		$saved = null;
		$this->store->expects($this->once())->method('saveRecord')->willReturnCallback(
			static function (array $record) use (&$saved): string {
				$saved = $record;
				return self::RECORD_ID;
			}
		);
		$this->store->expects($this->once())->method('ensureJoin');

		$this->service->projectNode(node: $this->file(path: self::CASE_DIR . '/aanvraagformulier.pdf', content: 'a longer body'));

		$this->assertSame(expected: self::RECORD_ID, actual: $saved['id'], message: 'the same record, not a second one');
		$this->assertSame(expected: 13, actual: $saved['bestandsomvang']);
		$this->assertSame(expected: hash('sha256', 'a longer body'), actual: $saved['integrity']['value']);
		$this->assertSame(expected: 'Bouwtekening', actual: $saved['title'], message: 'a refresh does not touch the title');
	}//end testASecondWriteRefreshesTheRecordAndCreatesNothing()

	/**
	 * @return void
	 */
	public function testAFileOutsideAnyCaseFolderIsLeftAlone(): void {
		$this->store->expects($this->never())->method('saveRecord');
		$this->store->expects($this->never())->method('ensureJoin');

		$registerDir = '/admin/files/Open Registers/Dossiq Case Management Register';
		// A document's own folder: the uuid is not a case.
		$ownFolder = $this->file(path: $registerDir . '/' . self::RECORD_ID . '/x.pdf');
		$this->assertNull(actual: $this->service->projectNode(node: $ownFolder));
		// Straight under the register folder.
		$underRegister = $this->file(path: $registerDir . '/x.pdf');
		$this->assertNull(actual: $this->service->projectNode(node: $underRegister));
		// Not under the register tree at all.
		$elsewhere = $this->file(path: '/admin/files/Documents/x.pdf');
		$this->assertNull(actual: $this->service->projectNode(node: $elsewhere));
		$this->assertFalse(condition: $this->service->isUnderRegisterTree(path: '/admin/files/Documents/x.pdf'));
	}//end testAFileOutsideAnyCaseFolderIsLeftAlone()

	/**
	 * @return void
	 */
	public function testAFileInASubFolderIsStillTheCasesDocument(): void {
		$this->store->method('findRecord')->willReturn(null);
		$this->store->expects($this->once())->method('saveRecord')->willReturn(self::RECORD_ID);
		$this->store->expects($this->once())->method('ensureJoin')->with(self::CASE_ID, self::RECORD_ID);

		$record = $this->service->projectNode(node: $this->file(path: self::CASE_DIR . '/scans/2026/plattegrond.pdf'));

		$this->assertSame(expected: 'plattegrond', actual: $record['title']);
	}//end testAFileInASubFolderIsStillTheCasesDocument()

	/**
	 * @return void
	 */
	public function testARenameFollowsAndAHandSetTitleSurvives(): void {
		$derived = ['id' => self::RECORD_ID, 'title' => 'scan', 'fileName' => 'scan.pdf', 'status' => 'draft', 'fileId' => 501];
		$this->store->method('findRecord')->willReturn($derived);
		$saved = [];
		$this->store->method('saveRecord')->willReturnCallback(
			static function (array $record) use (&$saved): string {
				$saved[] = $record;
				return self::RECORD_ID;
			}
		);

		$this->service->rehomeNode(node: $this->file(path: self::CASE_DIR . '/bouwtekening.pdf'), sourcePath: self::CASE_DIR . '/scan.pdf');
		$this->assertSame(expected: 'bouwtekening.pdf', actual: $saved[0]['fileName']);
		$this->assertSame(expected: 'bouwtekening', actual: $saved[0]['title'], message: 'a derived title follows the name');

		$handSet = ['id' => self::RECORD_ID, 'title' => 'Bouwtekening begane grond', 'fileName' => 'scan.pdf', 'status' => 'draft', 'fileId' => 501];
		$store = $this->createMock(originalClassName: DocumentRecordStore::class);
		$store->method('findRecord')->willReturn($handSet);
		$store->expects($this->once())->method('saveRecord')->with(
			$this->callback(
				callback: static fn (array $record): bool => $record['title'] === 'Bouwtekening begane grond'
					&& $record['fileName'] === 'bouwtekening.pdf'
			)
		)->willReturn(self::RECORD_ID);
		$service = $this->serviceOver(store: $store, session: $this->createMock(originalClassName: IUserSession::class));
		$service->rehomeNode(node: $this->file(path: self::CASE_DIR . '/bouwtekening.pdf'), sourcePath: self::CASE_DIR . '/scan.pdf');
	}//end testARenameFollowsAndAHandSetTitleSurvives()

	/**
	 * @return void
	 */
	public function testDeletingADraftDocumentDeletesTheRecordAndItsJoins(): void {
		$this->store->method('findRecord')->willReturn(['id' => self::RECORD_ID, 'status' => 'draft']);
		$this->store->expects($this->once())->method('deleteJoins')->with(self::RECORD_ID);
		$this->store->expects($this->once())->method('deleteRecord')->with(self::RECORD_ID);
		$this->store->expects($this->never())->method('saveRecord');

		$this->service->retireNode(node: $this->file(path: self::CASE_DIR . '/x.pdf'));
	}//end testDeletingADraftDocumentDeletesTheRecordAndItsJoins()

	/**
	 * @return void
	 */
	public function testDeletingAFinalDocumentArchivesTheRecord(): void {
		$this->store->method('findRecord')->willReturn(['id' => self::RECORD_ID, 'status' => 'final']);
		$this->store->expects($this->once())->method('deleteJoins')->with(self::RECORD_ID);
		$this->store->expects($this->never())->method('deleteRecord');
		$this->store->expects($this->once())->method('saveRecord')->with(
			$this->callback(callback: static fn (array $record): bool => $record['status'] === 'archived' && $record['id'] === self::RECORD_ID)
		)->willReturn(self::RECORD_ID);

		$this->service->retireNode(node: $this->file(path: self::CASE_DIR . '/x.pdf'));
	}//end testDeletingAFinalDocumentArchivesTheRecord()

	/**
	 * @return void
	 */
	public function testDeletingAFileWithoutARecordDoesNothing(): void {
		$this->store->method('findRecord')->willReturn(null);
		$this->store->expects($this->never())->method('deleteJoins');
		$this->store->expects($this->never())->method('deleteRecord');

		$this->service->retireNode(node: $this->file(path: self::CASE_DIR . '/x.pdf'));
	}//end testDeletingAFileWithoutARecordDoesNothing()

	/**
	 * @return void
	 */
	public function testAMoveBetweenCasesReHomesTheDocument(): void {
		$record = ['id' => self::RECORD_ID, 'title' => 'x', 'fileName' => 'x.pdf', 'status' => 'draft', 'fileId' => 501];
		$this->store->method('findRecord')->willReturn($record);
		$this->store->expects($this->once())->method('deleteJoins')->with(self::RECORD_ID, self::CASE_ID);
		$this->store->expects($this->once())->method('saveRecord')->willReturn(self::RECORD_ID);
		$this->store->expects($this->once())->method('ensureJoin')->with(self::OTHER_CASE_ID, self::RECORD_ID);
		$this->store->expects($this->never())->method('deleteRecord');

		$target = '/admin/files/Open Registers/Dossiq Case Management Register/' . self::OTHER_CASE_ID . '/x.pdf';
		$this->service->rehomeNode(node: $this->file(path: $target), sourcePath: self::CASE_DIR . '/x.pdf');
	}//end testAMoveBetweenCasesReHomesTheDocument()

	/**
	 * @return void
	 */
	public function testAMoveOutOfEveryCaseFolderRetiresADraftWithNoCaseLeft(): void {
		$record = ['id' => self::RECORD_ID, 'status' => 'draft', 'fileId' => 501];
		$this->store->method('findRecord')->willReturn($record);
		$this->store->method('joinsFor')->willReturn([]);
		$this->store->expects($this->exactly(count: 2))->method('deleteJoins');
		$this->store->expects($this->once())->method('deleteRecord')->with(self::RECORD_ID);

		$this->service->rehomeNode(node: $this->file(path: '/admin/files/Documents/x.pdf'), sourcePath: self::CASE_DIR . '/x.pdf');
	}//end testAMoveOutOfEveryCaseFolderRetiresADraftWithNoCaseLeft()

	/**
	 * @return void
	 */
	public function testACaseHasAFolderOnlyWhenTheFileServiceAnswersOne(): void {
		$this->folders = [self::CASE_ID => $this->createMock(originalClassName: Folder::class)];
		$this->assertTrue(condition: $this->service->caseHasFolder(caseId: self::CASE_ID));
		$this->assertFalse(condition: $this->service->caseHasFolder(caseId: self::OTHER_CASE_ID));

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getFileService')->willReturn(null);
		$withoutFiles = new DocumentProjectionService(
			store: $this->store,
			defaults: new DocumentDefaults(
				store: $this->store,
				caseTypes: $this->caseTypes,
				userSession: $this->createMock(originalClassName: IUserSession::class),
			),
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
		$this->assertNull(actual: $withoutFiles->folderOf(objectId: self::CASE_ID), message: 'no file service, no folder');
	}//end testACaseHasAFolderOnlyWhenTheFileServiceAnswersOne()

	/**
	 * @return void
	 */
	public function testTheFirstJoinMovesAnApiFirstFileIntoTheCase(): void {
		$this->store->method('findRecord')->with(self::RECORD_ID, 0)->willReturn(['id' => self::RECORD_ID, 'fileName' => 'x.pdf', 'fileId' => 900]);
		$moved = $this->createMock(originalClassName: File::class);
		$moved->method('getId')->willReturn(901);
		$node = $this->createMock(originalClassName: File::class);
		$node->method('getId')->willReturn(900);
		$node->expects($this->once())->method('move')->with('/openregister/files/Open Registers/R/' . self::CASE_ID . '/x.pdf')->willReturn($moved);
		$own = $this->createMock(originalClassName: Folder::class);
		$own->method('nodeExists')->with('x.pdf')->willReturn(true);
		$own->method('get')->with('x.pdf')->willReturn($node);
		$caseFolder = $this->createMock(originalClassName: Folder::class);
		$caseFolder->method('getPath')->willReturn('/openregister/files/Open Registers/R/' . self::CASE_ID);
		$this->folders = [self::RECORD_ID => $own, self::CASE_ID => $caseFolder];
		$this->store->expects($this->once())->method('saveRecord')->with(
			$this->callback(callback: static fn (array $record): bool => $record['fileId'] === 901)
		)->willReturn(self::RECORD_ID);

		$this->assertTrue(condition: $this->service->homeDocument(recordId: self::RECORD_ID, caseId: self::CASE_ID));
	}//end testTheFirstJoinMovesAnApiFirstFileIntoTheCase()

	/**
	 * @return void
	 */
	public function testASecondJoinLeavesTheFileWhereItIs(): void {
		$this->store->method('findRecord')->willReturn(['id' => self::RECORD_ID, 'fileName' => 'x.pdf', 'fileId' => 900]);
		$own = $this->createMock(originalClassName: Folder::class);
		$own->method('nodeExists')->willReturn(false);
		$this->folders = [self::RECORD_ID => $own, self::OTHER_CASE_ID => $own];
		$this->store->expects($this->never())->method('saveRecord');

		$this->assertFalse(condition: $this->service->homeDocument(recordId: self::RECORD_ID, caseId: self::OTHER_CASE_ID));
	}//end testASecondJoinLeavesTheFileWhereItIs()

	/**
	 * @return void
	 */
	public function testAJoinToACaseWithoutAFolderIsRefusedBeforeAnythingMoves(): void {
		$this->store->method('findRecord')->willReturn(['id' => self::RECORD_ID, 'fileName' => 'x.pdf']);
		$own = $this->createMock(originalClassName: Folder::class);
		$own->method('nodeExists')->willReturn(true);
		$own->expects($this->never())->method('get');
		$this->folders = [self::RECORD_ID => $own];

		$this->expectException(exception: RuntimeException::class);
		$this->service->homeDocument(recordId: self::RECORD_ID, caseId: self::CASE_ID);
	}//end testAJoinToACaseWithoutAFolderIsRefusedBeforeAnythingMoves()
}//end class
