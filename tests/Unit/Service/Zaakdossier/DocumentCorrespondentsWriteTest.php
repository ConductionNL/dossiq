<?php

/**
 * What ZaakdossierService stores for a document's correspondents.
 *
 * The rules and the dispatch rows have their own tests. This one is about the
 * seam: an upload and a metadata edit reaching the writer with the right case,
 * the right direction and the right submitted value.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\InformatieobjectAccessGuard;
use OCA\Dossiq\Service\People\PersonLinkReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\CorrespondentWriter;
use OCA\Dossiq\Service\Zaakdossier\DocumentCorrespondents;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectMetadataNormaliser;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectStatusLifecycle;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\ZaakdossierService
 * @uses \OCA\Dossiq\Service\InformatieobjectAccessGuard
 * @uses \OCA\Dossiq\Service\Zaakdossier\CorrespondentWriter
 * @uses \OCA\Dossiq\Service\Zaakdossier\DocumentCorrespondents
 * @uses \OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore
 * @uses \OCA\Dossiq\Service\Zaakdossier\InformatieobjectMetadataNormaliser
 * @uses \OCA\Dossiq\Service\Zaakdossier\InformatieobjectStatusLifecycle
 */
class DocumentCorrespondentsWriteTest extends TestCase {
	private const CONFIG = [
		'register' => 'dossiq',
		'dossier_informatieobject_schema' => 'informatieobject',
		'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
		'dossier_informatieobjecttype_schema' => 'informatieobjecttype',
		'dispatch_schema' => 'dispatch',
	];

	private const PARTIES = [
		['partyUuid' => 'party-jan', 'displayName' => 'Jan Jansen', 'email' => 'jan@example.org'],
		['partyUuid' => 'party-council', 'displayName' => 'Gemeente Utrecht', 'email' => ''],
	];

	/** @var object The stub object service, answering every schema. */
	private object $objects;

	/** @var ZaakdossierService The service under test. */
	private ZaakdossierService $service;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<string, array<string, mixed>> uuid => row. */
			public array $rows = [];

			/** @var array<string, array<int, array<string, mixed>>> schema => rows a search may answer. */
			public array $answers = [];

			/** @var array<int, array{schema: string|int|null, object: array<string, mixed>}> Every save. */
			public array $saves = [];

			/**
			 * @param string $id The uuid.
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema The schema.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function find(string $id, string|int|null $register = null, string|int|null $schema = null): array {
				if (isset($this->rows[$id]) === false) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('not found: ' . $id);
				}

				return $this->rows[$id] + ['id' => $id];
			}

			/**
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The matching rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$fields = array_filter(
					$filters,
					static fn (string $key): bool => str_starts_with($key, '_') === false,
					ARRAY_FILTER_USE_KEY
				);

				return array_values(array_filter(
					($this->answers[$schema] ?? []),
					static function (array $row) use ($fields): bool {
						foreach ($fields as $key => $value) {
							if (($row[$key] ?? null) !== $value) {
								return false;
							}
						}

						return true;
					}
				));
			}

			/**
			 * @param array<string, mixed> $object The object.
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema The schema.
			 * @param string|null $uuid The uuid on an update.
			 *
			 * @return array<string, mixed> The saved row.
			 */
			public function saveObject(array $object, string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): array {
				$this->saves[] = ['schema' => $schema, 'object' => $object];
				$uuid = ($uuid ?? ('new-' . count($this->saves)));
				unset($object['@self'], $object['id']);
				$this->rows[$uuid] = $object;
				$this->answers[(string)$schema][] = $object;
				return ['id' => $uuid] + $object;
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (self::CONFIG[$key] ?? $default)
		);
		// The upload stores the file on the case before it writes the record,
		// so the file service has to answer with a file id.
		$settings->method('getFileService')->willReturn(new class {
			/**
			 * @param string $objectEntity The object the file lands on.
			 * @param string $fileName The name.
			 * @param string $content The bytes.
			 * @param bool $share Whether to share it.
			 * @param array<int, string> $tags The tags.
			 * @param string $registerId The register.
			 *
			 * @return object A file exposing getFileId().
			 */
			public function addFile(
				string $objectEntity,
				string $fileName,
				string $content,
				bool $share = false,
				array $tags = [],
				string $registerId = '',
			): object {
				return new class {
					/**
					 * @return int The file id.
					 */
					public function getFileId(): int {
						return 4711;
					}
				};
			}
		});

		$people = $this->createMock(originalClassName: PersonLinkReader::class);
		$people->method('peopleOn')->willReturnCallback(
			static function (string $caseId): array {
				if ($caseId === 'case-1') {
					return self::PARTIES;
				}

				return [];
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->service = new ZaakdossierService(
			settingsService: $settings,
			accessGuard: new InformatieobjectAccessGuard(
				settingsService: $settings,
				groupManager: $this->createMock(originalClassName: IGroupManager::class),
				logger: $logger,
			),
			statusLifecycle: new InformatieobjectStatusLifecycle(settingsService: $settings, logger: $logger),
			normaliser: new InformatieobjectMetadataNormaliser(),
			logger: $logger,
			recordStore: new DocumentRecordStore(settingsService: $settings),
			correspondents: new CorrespondentWriter(
				rules: new DocumentCorrespondents(),
				people: $people,
				settingsService: $settings,
			),
		);
	}//end setUp()

	/**
	 * 🔴 The seam this change exists for. A letter filed on the case names its
	 * addressee as a PARTY, and the same words typed instead name nobody.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-013-the-writers-set-the-correspondent-not-the-person
	 */
	public function testAnUploadStoresTheAddresseeAsAPartyAndDropsATypedName(): void {
		$stored = $this->service->uploadDocument(
			'case-1',
			'beschikking.md',
			'# Beschikking',
			[
				'informatieobjecttype' => 'iot-1',
				'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk',
				'direction' => 'outgoing',
				'recipients' => ['party-jan', 'Gemeente Utrecht'],
			]
		);

		$this->assertSame(expected: 'outgoing', actual: $stored['direction']);
		$this->assertSame(expected: ['party-jan'], actual: $stored['recipients']);
		$this->assertSame(expected: '', actual: $stored['sender']);

		$document = $this->savedFor(schema: 'informatieobject');
		$this->assertSame(expected: ['party-jan'], actual: $document['recipients']);
	}//end testAnUploadStoresTheAddresseeAsAPartyAndDropsATypedName()

	/**
	 * The upload records the send as well as the addressee.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-014-a-dispatch-record-is-written-per-correspondent
	 */
	public function testAnUploadRecordsTheDispatchBesideTheDocument(): void {
		$this->service->uploadDocument(
			'case-1',
			'brief.md',
			'# Brief',
			[
				'informatieobjecttype' => 'iot-1',
				'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk',
				'direction' => 'outgoing',
				'recipients' => ['party-council'],
			]
		);

		$dispatch = $this->savedFor(schema: 'dispatch');
		$this->assertSame(expected: 'party-council', actual: $dispatch['involvedParty']);
		$this->assertSame(expected: 'geadresseerde', actual: $dispatch['relationshipType']);
		$this->assertSame(expected: 'case-1', actual: $dispatch['case']);
		$this->assertArrayNotHasKey(key: 'contactPersonName', array: $dispatch);
	}//end testAnUploadRecordsTheDispatchBesideTheDocument()

	/**
	 * A party of another case is not a correspondent of this one, so an
	 * upload on a case the reader cannot answer for stores nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 */
	public function testAnUploadOnACaseWithoutThatPartyStoresNoCorrespondent(): void {
		$stored = $this->service->uploadDocument(
			'case-other',
			'brief.md',
			'# Brief',
			[
				'informatieobjecttype' => 'iot-1',
				'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk',
				'direction' => 'outgoing',
				'recipients' => ['party-jan'],
			]
		);

		$this->assertSame(expected: [], actual: $stored['recipients']);
	}//end testAnUploadOnACaseWithoutThatPartyStoresNoCorrespondent()

	/**
	 * An edit resolves against the case the document is JOINED to, which is
	 * the only place the case id can come from on a PATCH that names one
	 * document and no case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 */
	public function testAnEditResolvesAgainstTheCaseTheDocumentIsJoinedTo(): void {
		$this->givenADocumentJoinedToCaseOne();

		$result = $this->service->updateMetadata('inf-1', ['sender' => 'jan@example.org']);

		$this->assertSame(expected: 'party-jan', actual: $result['sender']);
		$this->assertSame(expected: 'party-jan', actual: $this->objects->rows['inf-1']['sender']);
	}//end testAnEditResolvesAgainstTheCaseTheDocumentIsJoinedTo()

	/**
	 * 🔴 An edit that names neither field leaves the correspondent alone.
	 * This is the one a blanket "write every allowed field" gets wrong: it
	 * would blank the sender somebody set yesterday on every title change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 */
	public function testATitleEditLeavesAnExistingSenderAlone(): void {
		$this->givenADocumentJoinedToCaseOne(sender: 'party-jan');

		$result = $this->service->updateMetadata('inf-1', ['title' => 'Herzien']);

		$this->assertArrayNotHasKey(key: 'sender', array: $result);
		$this->assertSame(expected: 'party-jan', actual: $this->objects->rows['inf-1']['sender']);
	}//end testATitleEditLeavesAnExistingSenderAlone()

	/**
	 * Switching a document to outgoing drops the sender it carried: a letter
	 * that went out did not also come in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-012-direction-decides-which-correspondent-a-document-may-carry
	 */
	public function testSwitchingToOutgoingDropsTheSenderItCarried(): void {
		$this->givenADocumentJoinedToCaseOne(sender: 'party-jan');

		$result = $this->service->updateMetadata(
			'inf-1',
			['direction' => 'outgoing', 'recipients' => ['party-council']]
		);

		$this->assertSame(expected: '', actual: $result['sender']);
		$this->assertSame(expected: ['party-council'], actual: $result['recipients']);
	}//end testSwitchingToOutgoingDropsTheSenderItCarried()

	/**
	 * A document on case-1, joined to it, optionally already naming a sender.
	 *
	 * @param string $sender The sender the document already carries.
	 *
	 * @return void
	 */
	private function givenADocumentJoinedToCaseOne(string $sender = ''): void {
		$this->objects->rows['inf-1'] = [
			'title' => 'Aanvraag',
			'fileName' => 'aanvraag.pdf',
			'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk',
			'informatieobjecttype' => 'iot-1',
			'status' => 'draft',
			'direction' => 'incoming',
			'sender' => $sender,
			'recipients' => [],
		];
		$this->objects->answers['zaakinformatieobject'] = [
			['id' => 'join-1', 'case' => 'case-1', 'informatieobject' => 'inf-1'],
		];
	}//end givenADocumentJoinedToCaseOne()

	/**
	 * The last object saved into one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed> The object.
	 */
	private function savedFor(string $schema): array {
		$found = array_values(array_filter(
			$this->objects->saves,
			static fn (array $save): bool => (string)$save['schema'] === $schema
		));
		$this->assertNotSame(expected: [], actual: $found, message: 'nothing was saved into ' . $schema);

		return $found[array_key_last($found)]['object'];
	}//end savedFor()
}//end class
