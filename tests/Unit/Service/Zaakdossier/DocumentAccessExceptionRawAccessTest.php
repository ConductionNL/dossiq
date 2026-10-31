<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-openregisters-own-api-applies-the-same-rule-to-the-record
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Listener\RestrictedDocumentHandlerListener;
use OCA\Dossiq\Service\Beschikking\LibresignResultAssembler;
use OCA\Dossiq\Service\Redaction\RedactedCopyRecorder;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentCaseIds;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Service\Zaakdossier\FileRestrictionMirror;
use OCA\Dossiq\Service\Zaakdossier\RestrictedDocumentReaders;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * OpenRegister's own API applies the exception to the record; the derived readers follow the handler; copies carry
 * the exception.
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-openregisters-own-api-applies-the-same-rule-to-the-record
 */
class DocumentAccessExceptionRawAccessTest extends TestCase {

	private const EXCEPTION = ['users' => ['pieter'], 'groups' => ['woo-team'], 'reason' => 'Prijzen'];

	private DocumentRecordStore&MockObject $store;

	private DocumentCaseIds&MockObject $caseIds;

	private FileRestrictionMirror&MockObject $files;

	/**
	 * What the store saved, and as whom.
	 *
	 * @var list<array{0: array<string, mixed>, 1: bool}>
	 */
	private array $saved = [];

	private bool $asServiceAccount = false;

	/**
	 * The handler of each case.
	 *
	 * @var array<string, string>
	 */
	private array $handlers = ['case-1' => 'hanna'];

	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->createMock(DocumentRecordStore::class);
		$this->store->method('saveRecord')->willReturnCallback(
			function (array $record): string {
				$this->saved[] = [$record, $this->asServiceAccount];
				return (string)($record['id'] ?? 'new');
			}
		);
		$this->store->method('findCase')->willReturnCallback(fn (string $id): array => ['id' => $id, 'assignee' => ($this->handlers[$id] ?? '')]);
		$this->caseIds = $this->createMock(DocumentCaseIds::class);
		$this->caseIds->method('caseIdsOf')->willReturn(['case-1']);
		$this->caseIds->method('documentIdsOf')->willReturn(['doc-r', 'doc-open']);
		$this->files = $this->createMock(FileRestrictionMirror::class);
	}//end setUp()

	/**
	 * The readers service, its service account recording that it ran.
	 *
	 * @return RestrictedDocumentReaders
	 */
	private function readers(): RestrictedDocumentReaders {
		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('runAs')->willReturnCallback(
			function (callable $operation): mixed {
				$this->asServiceAccount = true;
				try {
					return $operation();
				} finally {
					$this->asServiceAccount = false;
				}
			}
		);

		return new RestrictedDocumentReaders(caseIds: $this->caseIds, store: $this->store, serviceAccount: $account, files: $this->files);
	}//end readers()

	public function testTheRegisterLetsStaffReadOnlyDocumentsWithoutAnExceptionAndListedReadersTheRest(): void {
		$fragment = json_decode((string)file_get_contents(dirname(__DIR__, 4) . '/lib/Settings/register.d/99-document-access-exception.json'), true);
		$schema = $fragment['components']['schemas']['informatieobject'];
		$expected = [
			['group' => 'dossiq-medewerkers', 'match' => ['restricted' => false]],
			['group' => 'dossiq-medewerkers', 'match' => ['restricted' => ['$exists' => false]]],
			['group' => 'authenticated', 'match' => ['accessUsers' => ['$contains' => '$userId']]],
			['group' => 'authenticated', 'match' => ['accessGroups' => ['$contains' => '$user.groups']]],
			'dossiq-background-service',
		];

		$this->assertSame($expected, $schema['authorization']['read']);

		// A listed outsider reads the document; changing or deleting the record is for staff on an unrestricted
		// document and for the handlers of its cases.
		$change = [
			['group' => 'dossiq-medewerkers', 'match' => ['restricted' => false]],
			['group' => 'dossiq-medewerkers', 'match' => ['restricted' => ['$exists' => false]]],
			['group' => 'dossiq-medewerkers', 'match' => ['accessHandlers' => ['$contains' => '$userId']]],
			'dossiq-background-service',
		];
		foreach (['update', 'delete'] as $action) {
			$this->assertSame($change, $schema['authorization'][$action], $action);
		}

		foreach (['accessException', 'accessExceptionHistory', 'restricted', 'accessUsers', 'accessGroups', 'accessHandlers'] as $property) {
			$this->assertSame(
				['create' => [['group' => 'dossiq-background-service']], 'update' => [['group' => 'dossiq-background-service']]],
				$schema['properties'][$property]['authorization'],
				$property . ' must not be writable by any staff group'
			);
		}
	}//end testTheRegisterLetsStaffReadOnlyDocumentsWithoutAnExceptionAndListedReadersTheRest()

	public function testAReassignedCasesRestrictedDocumentsNameTheNewHandler(): void {
		$this->store->method('findRecord')->willReturnCallback(
			static fn (string $recordId = '', int $fileId = 0): ?array => [
				'doc-r' => ['id' => 'doc-r', 'accessException' => self::EXCEPTION, 'restricted' => true, 'accessUsers' => ['pieter', 'hanna'], 'accessGroups' => ['woo-team']],
				'doc-open' => ['id' => 'doc-open'],
			][$recordId] ?? null
		);
		$this->handlers['case-1'] = 'kees';

		$changed = $this->readers()->refreshCase(caseId: 'case-1');

		$this->assertSame(1, $changed);
		$this->assertSame(['pieter', 'kees'], $this->saved[0][0]['accessUsers']);
		$this->assertSame(['kees'], $this->saved[0][0]['accessHandlers']);
		$this->assertTrue($this->saved[0][1], 'saved as the background service account');
	}//end testAReassignedCasesRestrictedDocumentsNameTheNewHandler()

	public function testTheListenerRefreshesOnlyWhenTheHandlerChanged(): void {
		$readers = $this->createMock(RestrictedDocumentReaders::class);
		$readers->expects($this->once())->method('refreshCase')->with('case-1');
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('case');
		$listener = new RestrictedDocumentHandlerListener(settingsService: $settings, readers: $readers, logger: new NullLogger());

		$listener->handle(new ObjectUpdatedEvent($this->caseRow(assignee: 'kees'), $this->caseRow(assignee: 'hanna')));
		$listener->handle(new ObjectUpdatedEvent($this->caseRow(assignee: 'kees'), $this->caseRow(assignee: 'kees')));
	}//end testTheListenerRefreshesOnlyWhenTheHandlerChanged()

	/**
	 * A case row.
	 *
	 * @param string $assignee The handler.
	 *
	 * @return ObjectEntity
	 */
	private function caseRow(string $assignee): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('case-1');
		$entity->setSchema('case');
		$entity->setObject(['assignee' => $assignee]);

		return $entity;
	}//end caseRow()

	public function testARedactedCopyCarriesTheOriginalsException(): void {
		$this->store->method('findRecord')->willReturnCallback(
			static fn (string $recordId = '', int $fileId = 0): ?array => $fileId === 77 ? ['id' => 'doc-copy', 'fileId' => 77] : null
		);
		$this->files->expects($this->once())->method('restrict')->with(77, ['pieter'], ['woo-team'], 'Prijzen');
		$recorder = new RedactedCopyRecorder(
			store: $this->store,
			rootFolder: $this->createMock(IRootFolder::class),
			readers: $this->readers(),
		);

		$recorder->recordFile(caseId: 'case-1', original: ['id' => 'doc-r', 'title' => 'Offerte', 'accessException' => self::EXCEPTION], fileId: 77);

		$copy = $this->saved[0][0];
		$this->assertSame('doc-copy', $copy['id']);
		$this->assertSame(['pieter'], $copy['accessException']['users']);
		$this->assertTrue($copy['restricted']);
		$this->assertSame('set', $copy['accessExceptionHistory'][0]['action']);
		$this->assertTrue($this->saved[0][1]);
	}//end testARedactedCopyCarriesTheOriginalsException()

	public function testACopyOfADocumentWithoutAnExceptionIsLeftAlone(): void {
		$this->files->expects($this->never())->method('restrict');

		$this->assertFalse($this->readers()->inherit(original: ['id' => 'doc-open'], fileId: 77));
		$this->assertSame([], $this->saved);
	}//end testACopyOfADocumentWithoutAnExceptionIsLeftAlone()

	public function testASignedVersionCarriesTheOriginalsException(): void {
		$this->store->method('findRecord')->willReturnCallback(
			static fn (string $recordId = '', int $fileId = 0): ?array => $recordId === 'doc-r'
				? ['id' => 'doc-r', 'accessException' => self::EXCEPTION]
				: null
		);
		$this->files->expects($this->once())->method('restrict')->with(88, ['pieter'], ['woo-team'], 'Prijzen');
		$signed = $this->createMock(File::class);
		$signed->method('getContent')->willReturn('%PDF signed');
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->willReturn($signed);
		$folder->method('getById')->willReturn([$signed]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);
		$documents = $this->createMock(ZgwDocumentService::class);
		$documents->method('getFileId')->willReturn(88);
		$readers = $this->readers();
		$assembler = new LibresignResultAssembler(
			rootFolder: $root,
			documentService: $documents,
			signedVersionOf: static fn (string $originalId, int $fileId, string $signatory): bool => $readers->inheritFromId(
				originalId: $originalId,
				fileId: $fileId,
				madeBy: $signatory
			),
		);

		$assembler->assembleSignedResult(fileId: 'doc-r', signatory: 'pieter', uuid: 'ls-1', status: ['file' => ['signedFileId' => 5]]);
	}//end testASignedVersionCarriesTheOriginalsException()

	public function testAHandlerThatCannotBeReadIsDroppedNotKept(): void {
		$caseIds = $this->createMock(DocumentCaseIds::class);
		$caseIds->method('caseIdsOf')->willThrowException(new \RuntimeException('OpenRegister down'));
		$this->caseIds = $caseIds;

		$record = $this->readers()->withReaders(record: ['id' => 'doc-r', 'accessException' => self::EXCEPTION, 'accessUsers' => ['pieter', 'hanna']]);

		$this->assertSame(['pieter'], $record['accessUsers']);
		$this->assertSame([], $record['accessHandlers']);
	}//end testAHandlerThatCannotBeReadIsDroppedNotKept()
}//end class
