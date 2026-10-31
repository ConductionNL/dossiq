<?php

/**
 * A partly public document gets its redacted copy, through filinq or by upload.
 *
 * Every class on the path is the real one: the redaction service, the filinq
 * client, the assessment service, the case documents, the document text, the
 * Hermiq assist and the copy recorder, over an in-memory register. Filinq is
 * tests/Support/FakeFilinq.php, which runs filinq's own review gate. Only the
 * Nextcloud file system, OpenRegister's file service and Hermiq's HTTP call
 * are doubles.
 *
 * The controls: no check, no copy; a check of another list, no copy; no live
 * detector, no copy. The cases differ from the controls only in the step the
 * officer takes.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Redaction
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/document-redaction/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Redaction;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Review\DocumentText;
use OCA\Dossiq\Service\Ai\AiAuditService;
use OCA\Dossiq\Service\AiService;
use OCA\Dossiq\Service\Assistant\HermiqAnonymisationClient;
use OCA\Dossiq\Service\FilinqRedactionClient;
use OCA\Dossiq\Service\Redaction\DocumentRedactionService;
use OCA\Dossiq\Service\Redaction\RedactedCopyRecorder;
use OCA\Dossiq\Service\Redaction\RedactionEntities;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOOAnonymisationAssistService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCA\Dossiq\Tests\Support\FakeFilinq;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Unit\Service\Transitions\SourceL10n;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Redaction\DocumentRedactionService
 * @covers \OCA\Dossiq\Service\Redaction\RedactedCopyRecorder
 * @covers \OCA\Dossiq\Service\Redaction\RedactionEntities
 */
class DocumentRedactionServiceTest extends TestCase {

	/**
	 * The text OpenRegister extracted from the document.
	 *
	 * @var string
	 */
	private const TEXT = 'Geachte Sanne de Vries, u kunt ons bereiken op 06 1234 5678. Contactpersoon is P. Jansen.';

	/**
	 * The register.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * Filinq.
	 *
	 * @var FakeFilinq
	 */
	private FakeFilinq $filinq;

	/**
	 * Files OpenRegister stored on an object, by name.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * Seed a case with one partly public and one public document.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->register->seed(schema: 'informatieobject', uuid: 'doc-1', row: [
			'title' => 'Offerte verlichting',
			'fileName' => 'Offerte.pdf',
			'fileId' => 42,
			'informatieobjecttype' => 'type-1',
			'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk',
		]);
		$this->register->seed(schema: 'informatieobject', uuid: 'doc-2', row: ['title' => 'Besluit', 'fileName' => 'Besluit.pdf', 'fileId' => 43]);
		$this->register->seed(schema: 'zaakinformatieobject', uuid: 'join-1', row: ['case' => 'case-1', 'informatieobject' => 'doc-1']);
		$this->register->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: [
			'caseRef' => 'case-1',
			'documentRef' => 'doc-1',
			'classification' => 'deels_openbaar',
			'weigeringsgronden' => ['5.1.2e'],
		]);
		$this->register->seed(schema: 'wooDocumentAssessment', uuid: 'a-2', row: [
			'caseRef' => 'case-1',
			'documentRef' => 'doc-2',
			'classification' => 'openbaar',
		]);

		$this->filinq = new FakeFilinq();
		$this->filinq->entities = [
			['type' => 'PERSON', 'value' => 'Sanne de Vries', 'confidence' => 0.9, 'relationId' => 7],
			['type' => 'PHONE', 'value' => '06 1234 5678', 'confidence' => 0.9, 'relationId' => 8],
		];
	}//end setUp()

	/**
	 * The redaction service over the real classes.
	 *
	 * @return DocumentRedactionService
	 */
	private function service(): DocumentRedactionService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getFileService')->willReturn($this->fileService());
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'woo_assessment_schema' => 'wooDocumentAssessment',
				'dossier_informatieobject_schema' => 'informatieobject',
				'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
			][$key] ?? $default
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pjansen');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$hermiq = $this->createMock(HermiqAnonymisationClient::class);
		$hermiq->method('isAvailable')->willReturn(true);
		$hermiq->method('detectPii')->willReturnCallback(
			static fn (string $text): array => [
				'spans' => [['start' => strpos($text, 'P. Jansen'), 'end' => strpos($text, 'P. Jansen') + 9, 'category' => 'name']],
				'usage' => [],
			]
		);
		$ai = $this->createMock(AiService::class);
		$ai->method('detectDeterministicPiiSpans')->willReturn([]);

		$rootFolder = $this->rootFolder();
		$assessments = new WOODocumentAssessmentService(settingsService: $settings, userSession: $session, logger: new NullLogger());

		return new DocumentRedactionService(
			assessments: $assessments,
			filinq: new FilinqRedactionClient(container: $this->filinq->container(), logger: new NullLogger()),
			assist: new WOOAnonymisationAssistService(
				aiService: $ai,
				auditService: $this->createMock(AiAuditService::class),
				hermiqClient: $hermiq,
				assessmentService: $assessments,
				logger: new NullLogger()
			),
			text: new DocumentText(container: $this->extraction(), logger: new NullLogger()),
			documents: new WooCaseDocuments(settingsService: $settings, rootFolder: $rootFolder, logger: new NullLogger()),
			zgw: $this->createMock(ZgwDocumentService::class),
			copies: new RedactedCopyRecorder(store: new DocumentRecordStore(settingsService: $settings), rootFolder: $rootFolder),
			entities: new RedactionEntities(),
			l10n: new SourceL10n(),
		);
	}//end service()

	/**
	 * OpenRegister's text extraction, answering the document's text.
	 *
	 * @return ContainerInterface
	 */
	private function extraction(): ContainerInterface {
		$extraction = new class(self::TEXT) {

			/**
			 * Constructor.
			 *
			 * @param string $text The extracted text.
			 */
			public function __construct(private string $text) {
			}

			/**
			 * The text of a file.
			 *
			 * @param int $fileId The file id.
			 *
			 * @return string|null
			 */
			public function getExtractedText(int $fileId): ?string {
				return ($fileId === 42 ? $this->text : null);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($extraction);

		return $container;
	}//end extraction()

	/**
	 * OpenRegister's file service, keeping what is stored on an object.
	 *
	 * @return object
	 */
	private function fileService(): object {
		$stored = &$this->stored;

		return new class($stored) {

			/**
			 * Constructor.
			 *
			 * @param array<string, string> $stored Where the files go.
			 */
			public function __construct(private array &$stored) {
			}

			/**
			 * Store a file on an object.
			 *
			 * @param string      $objectEntity The object.
			 * @param string      $fileName     The file name.
			 * @param string      $content      The bytes.
			 * @param bool        $share        Ignored.
			 * @param array<mixed> $tags        Ignored.
			 * @param string      $registerId   Ignored.
			 *
			 * @return object
			 */
			public function addFile(string $objectEntity, string $fileName, string $content, bool $share = false, array $tags = [], string $registerId = ''): object {
				$this->stored[$fileName] = $objectEntity . ':' . $content;

				return new class {

					/**
					 * The stored file's id.
					 *
					 * @return int
					 */
					public function getFileId(): int {
						return 7001;
					}
				};
			}
		};
	}//end fileService()

	/**
	 * The file system: filinq's copy (9001) and an uploaded version (7001).
	 *
	 * @return IRootFolder
	 */
	private function rootFolder(): IRootFolder {
		$files = [
			9001 => 'Offerte_anonymized.pdf',
			7001 => 'Offerte_gelakt.pdf',
		];

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->willReturnCallback(
			function (int $id) use ($files): array {
				if (isset($files[$id]) === false) {
					return [];
				}

				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn($id);
				$file->method('getName')->willReturn($files[$id]);
				$file->method('getSize')->willReturn(1234);
				$file->method('getMimeType')->willReturn('application/pdf');

				return [$file];
			}
		);

		return $rootFolder;
	}//end rootFolder()

	/**
	 * The stored assessment of the partly public document.
	 *
	 * @return array<string, mixed>
	 */
	private function assessment(): array {
		return $this->register->row(schema: 'wooDocumentAssessment', uuid: 'a-1');
	}//end assessment()

	/**
	 * Run a call that must be refused, and answer the refusal.
	 *
	 * @param callable $call The call.
	 *
	 * @return RefusedException
	 */
	private function refusalOf(callable $call): RefusedException {
		try {
			$call();
		} catch (RefusedException $e) {
			return $e;
		}

		self::fail('The call was not refused.');
	}//end refusalOf()

	/**
	 * Detection merges filinq's entities with Hermiq's names and stores the list unchecked.
	 *
	 * @return void
	 */
	public function testDetectMergesFilinqAndHermiqAndStoresAnUncheckedList(): void {
		$detected = $this->service()->detect(caseId: 'case-1', documentRef: 'doc-1', userId: 'pjansen');

		$entities = $detected['redaction']['entities'];
		self::assertSame(['Sanne de Vries', '06 1234 5678', 'P. Jansen'], array_column($entities, 'value'));
		self::assertSame(['filinq', 'filinq', 'hermiq'], array_column($entities, 'source'));
		self::assertSame('PERSON', $entities[2]['type']);
		self::assertSame(self::TEXT, $detected['text']);
		self::assertSame($entities, $this->assessment()['redaction']['entities']);
		self::assertArrayNotHasKey('checkedBy', $this->assessment()['redaction']);
	}//end testDetectMergesFilinqAndHermiqAndStoresAnUncheckedList()

	/**
	 * A public document is not redacted, and nothing is written.
	 *
	 * @return void
	 */
	public function testAPublicDocumentIsNotRedacted(): void {
		$writes = $this->register->writes;

		$refusal = $this->refusalOf(fn () => $this->service()->detect(caseId: 'case-1', documentRef: 'doc-2', userId: 'pjansen'));

		self::assertSame('not-partly-public', $refusal->getRule());
		self::assertSame($writes, $this->register->writes);
	}//end testAPublicDocumentIsNotRedacted()

	/**
	 * CONTROL: without the officer's check no copy is asked for and none is recorded.
	 *
	 * @return void
	 */
	public function testWithoutACheckNoCopyIsMade(): void {
		$service = $this->service();
		$service->detect(caseId: 'case-1', documentRef: 'doc-1', userId: 'pjansen');

		$refusal = $this->refusalOf(fn () => $service->redact(caseId: 'case-1', documentRef: 'doc-1', userId: 'pjansen'));

		self::assertSame('not-checked', $refusal->getRule());
		self::assertSame([], $this->filinq->anonymised);
		self::assertSame([], $this->filinq->marks->marks);
		self::assertArrayNotHasKey('redactedDocumentRef', $this->assessment());
	}//end testWithoutACheckNoCopyIsMade()

	/**
	 * Checked, then redacted: filinq's mark names the officer, and the copy is recorded and referenced.
	 *
	 * @return void
	 */
	public function testACheckedListIsRedactedAndTheCopyRecorded(): void {
		$service = $this->service();
		$detected = $service->detect(caseId: 'case-1', documentRef: 'doc-1', userId: 'pjansen');
		$toRedact = array_slice($detected['redaction']['entities'], 0, 2);

		$service->check(caseId: 'case-1', documentRef: 'doc-1', entities: $toRedact, userId: 'pjansen');
		$result = $service->redact(caseId: 'case-1', documentRef: 'doc-1', userId: 'pjansen');

		self::assertSame('pjansen', $this->filinq->marks->marks[0]['checkedBy']);
		self::assertSame(2, $this->filinq->marks->marks[0]['entityCount']);
		self::assertSame(['Sanne de Vries', '06 1234 5678'], array_column($this->filinq->anonymised[0]['entities'], 'value'));

		$copyId = $result['redactedDocumentRef'];
		$copy = $this->register->row(schema: 'informatieobject', uuid: $copyId);
		self::assertSame(9001, $copy['fileId']);
		self::assertSame('Offerte_anonymized.pdf', $copy['fileName']);
		self::assertSame('type-1', $copy['informatieobjecttype']);
		self::assertSame('zaakvertrouwelijk', $copy['vertrouwelijkheidaanduiding']);
		self::assertContains(
			['case' => 'case-1', 'informatieobject' => $copyId],
			array_map(
				static fn (array $join): array => ['case' => $join['case'], 'informatieobject' => $join['informatieobject']],
				$this->register->all(schema: 'zaakinformatieobject')
			)
		);

		$assessment = $this->assessment();
		self::assertSame($copyId, $assessment['redactedDocumentRef']);
		self::assertSame('filinq', $assessment['redaction']['method']);
		self::assertSame('pjansen', $assessment['redaction']['redactedBy']);
		self::assertSame('regex', $assessment['redaction']['detectionBackend']);
	}//end testACheckedListIsRedactedAndTheCopyRecorded()

	/**
	 * A copy the case folder's listener already recorded is used, not recorded twice.
	 *
	 * @return void
	 */
	public function testACopyTheListenerRecordedIsUsed(): void {
		$this->register->seed(schema: 'informatieobject', uuid: 'copy-1', row: ['fileId' => 9001, 'fileName' => 'Offerte_anonymized.pdf']);
		$service = $this->service();
		$detected = $service->detect(caseId: 'case-1', documentRef: 'doc-1', userId: 'pjansen');
		$service->check(caseId: 'case-1', documentRef: 'doc-1', entities: $detected['redaction']['entities'], userId: 'pjansen');

		$result = $service->redact(caseId: 'case-1', documentRef: 'doc-1', userId: 'pjansen');

		self::assertSame('copy-1', $result['redactedDocumentRef']);
		self::assertCount(3, $this->register->all(schema: 'informatieobject'));
	}//end testACopyTheListenerRecordedIsUsed()

	/**
	 * CONTROL: filinq's mark covers another list, so filinq refuses and nothing is recorded.
	 *
	 * @return void
	 */
	public function testFilinqRefusesWhenItsMarkCoversAnotherList(): void {
		$service = $this->service();
		$detected = $service->detect(caseId: 'case-1', documentRef: 'doc-1', userId: 'pjansen');
		$service->check(caseId: 'case-1', documentRef: 'doc-1', entities: $detected['redaction']['entities'], userId: 'pjansen');
		$this->filinq->guard->markChecked(fileId: 42, entities: [['type' => 'PERSON', 'value' => 'Iemand anders']], checkedBy: 'someone');

		$refusal = $this->refusalOf(fn () => $service->redact(caseId: 'case-1', documentRef: 'doc-1', userId: 'pjansen'));

		self::assertSame('not-checked', $refusal->getRule());
		self::assertSame([], $this->filinq->anonymised);
		self::assertArrayNotHasKey('redactedDocumentRef', $this->assessment());
	}//end testFilinqRefusesWhenItsMarkCoversAnotherList()

	/**
	 * Without a live detector the refusal says to switch it on or upload.
	 *
	 * @return void
	 */
	public function testNoLiveDetectorIsRefusedWithTheRepair(): void {
		$this->filinq->detectorLive = false;
		$service = $this->service();
		$detected = $service->detect(caseId: 'case-1', documentRef: 'doc-1', userId: 'pjansen');
		$service->check(caseId: 'case-1', documentRef: 'doc-1', entities: $detected['redaction']['entities'], userId: 'pjansen');

		$refusal = $this->refusalOf(fn () => $service->redact(caseId: 'case-1', documentRef: 'doc-1', userId: 'pjansen'));

		self::assertSame('detection-unavailable', $refusal->getRule());
		self::assertSame(RefusedException::STATUS_INDETERMINATE, $refusal->getStatus());
		self::assertStringContainsString('upload a redacted version', $refusal->getSentence());
		self::assertArrayNotHasKey('redactedDocumentRef', $this->assessment());
	}//end testNoLiveDetectorIsRefusedWithTheRepair()

	/**
	 * An empty check is refused before filinq is asked.
	 *
	 * @return void
	 */
	public function testAnEmptyCheckIsRefused(): void {
		$refusal = $this->refusalOf(fn () => $this->service()->check(caseId: 'case-1', documentRef: 'doc-1', entities: [['type' => 'PERSON', 'value' => ' ']], userId: 'pjansen'));

		self::assertSame('nothing-to-redact', $refusal->getRule());
		self::assertSame([], $this->filinq->marks->marks);
	}//end testAnEmptyCheckIsRefused()

	/**
	 * A file that changed after the check is not redacted on the old check.
	 *
	 * @return void
	 */
	public function testAFileChangedAfterTheCheckIsRefused(): void {
		$service = $this->service();
		$detected = $service->detect(caseId: 'case-1', documentRef: 'doc-1', userId: 'pjansen');
		$service->check(caseId: 'case-1', documentRef: 'doc-1', entities: $detected['redaction']['entities'], userId: 'pjansen');
		$document = $this->register->row(schema: 'informatieobject', uuid: 'doc-1');
		$this->register->seed(schema: 'informatieobject', uuid: 'doc-1', row: array_merge($document, ['fileId' => 44]));

		$refusal = $this->refusalOf(fn () => $service->redact(caseId: 'case-1', documentRef: 'doc-1', userId: 'pjansen'));

		self::assertSame('checked-another-file', $refusal->getRule());
		self::assertSame([], $this->filinq->anonymised);
	}//end testAFileChangedAfterTheCheckIsRefused()

	/**
	 * An uploaded version is stored on the case, recorded and referenced.
	 *
	 * @return void
	 */
	public function testAnUploadedVersionIsRecordedAndReferenced(): void {
		$result = $this->service()->upload(caseId: 'case-1', documentRef: 'doc-1', fileName: 'mijn-versie.pdf', content: '%PDF-gelakt', userId: 'pjansen');

		self::assertCount(1, $this->stored);
		$name = (string)array_key_first($this->stored);
		self::assertMatchesRegularExpression('/^Offerte_gelakt_\d{8}T\d{6}\.pdf$/', $name);
		self::assertSame('case-1:%PDF-gelakt', $this->stored[$name]);

		$copy = $this->register->row(schema: 'informatieobject', uuid: $result['redactedDocumentRef']);
		self::assertSame(7001, $copy['fileId']);
		self::assertSame($result['redactedDocumentRef'], $this->assessment()['redactedDocumentRef']);
		self::assertSame('upload', $this->assessment()['redaction']['method']);
		self::assertSame([], $this->filinq->anonymised);
	}//end testAnUploadedVersionIsRecordedAndReferenced()

	/**
	 * An empty upload is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testAnEmptyUploadIsRefused(): void {
		$refusal = $this->refusalOf(fn () => $this->service()->upload(caseId: 'case-1', documentRef: 'doc-1', fileName: 'leeg.pdf', content: '', userId: 'pjansen'));

		self::assertSame('empty-upload', $refusal->getRule());
		self::assertSame([], $this->stored);
	}//end testAnEmptyUploadIsRefused()

	/**
	 * The case lists its partly public documents and where each one's redaction stands.
	 *
	 * @return void
	 */
	public function testTheCaseListsItsPartlyPublicDocuments(): void {
		$rows = $this->service()->forCase(caseId: 'case-1');

		self::assertCount(1, $rows);
		self::assertSame('doc-1', $rows[0]['documentRef']);
		self::assertSame('Offerte verlichting', $rows[0]['title']);
		self::assertSame('', $rows[0]['redactedDocumentRef']);
	}//end testTheCaseListsItsPartlyPublicDocuments()
}//end class
