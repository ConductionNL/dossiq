<?php

/**
 * Woo Gather Add Test
 *
 * Picked results from Gather documents become documents on a Woo case. The
 * test runs the real record store, the real document projection and the real
 * outstanding count over one in-memory register, so a file added here is
 * checked where the assessment reads it, and every written row is validated
 * against the real merged register schema.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\Zaakdossier\DocumentDefaults;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Woo\WooGatherAdd;
use OCA\Dossiq\Woo\WooSources;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Woo\WooGatherAdd
 * @covers \OCA\Dossiq\Woo\WooPickRefused
 */
class WooGatherAddTest extends TestCase {

	private const CASE_ID = '11111111-1111-4111-8111-111111111111';
	private const OTHER_CASE_ID = '22222222-2222-4222-8222-222222222222';
	private const LINKED_DOC = '33333333-3333-4333-8333-333333333333';
	private const TYPE_ID = '44444444-4444-4444-8444-444444444444';
	public const CASE_DIR = '/admin/files/Open Registers/Dossiq/' . self::CASE_ID;

	/**
	 * The schema slugs per configuration key.
	 */
	private const CONFIG = [
		'register' => 'dossiq',
		'case_schema' => 'case',
		'dossier_informatieobject_schema' => 'informatieobject',
		'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
		'dossier_informatieobjecttype_schema' => 'informatieobjecttype',
		'woo_assessment_schema' => 'wooDocumentAssessment',
	];

	private InMemoryRegister $register;

	private SettingsService&MockObject $settings;

	/**
	 * The files the handler can read, by file id.
	 *
	 * @var array<int, File>
	 */
	private array $userFiles = [];

	/**
	 * The files stored in the case folder, by file id.
	 *
	 * @var array<int, File>
	 */
	private array $caseFiles = [];

	/**
	 * Whether the caller may read the other case.
	 */
	private bool $readsOtherCase = true;

	/**
	 * What integriq hands over per handle.
	 *
	 * @var array<string, array{fileName: string, mimeType: string, content: string}>
	 */
	private array $fetchable = [];

	private IUser&MockObject $user;

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->register->seed('case', self::CASE_ID, ['title' => 'Woo-verzoek Stationsweg', 'caseType' => '']);
		$this->register->seed('case', self::OTHER_CASE_ID, ['title' => 'Omgevingsvergunning Stationsweg', 'caseType' => '']);
		$this->register->seed('informatieobjecttype', self::TYPE_ID, ['title' => 'Overig', 'vertrouwelijkheidaanduiding' => 'openbaar']);
		$this->register->seed('informatieobject', self::LINKED_DOC, ['title' => 'Advies welstand', 'fileName' => 'advies.pdf']);
		$this->register->seed('zaakinformatieobject', 'join-other', ['case' => self::OTHER_CASE_ID, 'informatieobject' => self::LINKED_DOC]);

		$caseFiles = &$this->caseFiles;
		$test = $this;
		$fileService = new class ($caseFiles, $test) {
			/**
			 * @param array<int, File> $caseFiles The case folder's files.
			 * @param WooGatherAddTest $test      Builds the file doubles.
			 */
			public function __construct(private array &$caseFiles, private WooGatherAddTest $test) {
			}

			public function addFile(mixed $objectEntity, string $fileName, string $content, bool $share = false, array $tags = [], mixed $registerId = null): object {
				$fileId = 900 + count($this->caseFiles);
				$this->caseFiles[$fileId] = $this->test->fileDouble(path: WooGatherAddTest::CASE_DIR . '/' . $fileName, fileId: $fileId, content: $content);
				return new class ($fileId) {
					public function __construct(private int $fileId) {
					}

					public function getFileId(): int {
						return $this->fileId;
					}
				};
			}

			public function getObjectFolder(mixed $objectEntity, mixed $registerId = null): ?Folder {
				return $this->test->folderOver(files: $this->caseFiles);
			}
		};

		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getObjectService')->willReturn($this->register);
		$this->settings->method('getFileService')->willReturn($fileService);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (self::CONFIG[$key] ?? $default)
		);

		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('pjansen');
		$this->user->method('getDisplayName')->willReturn('P. Jansen');
	}//end setUp()

	/**
	 * A file double with a path, an id and content.
	 *
	 * @param string $path     The node path.
	 * @param int    $fileId   The file id.
	 * @param string $content  The bytes.
	 * @param bool   $readable Whether the caller may read it.
	 *
	 * @return File&MockObject The file.
	 */
	public function fileDouble(string $path, int $fileId, string $content, bool $readable = true): File&MockObject {
		$node = $this->createMock(File::class);
		$node->method('getPath')->willReturn($path);
		$node->method('getId')->willReturn($fileId);
		$node->method('getName')->willReturn(basename($path));
		$node->method('getSize')->willReturn(strlen($content));
		$node->method('getMimeType')->willReturn('application/pdf');
		$node->method('getContent')->willReturn($content);
		$node->method('isReadable')->willReturn($readable);
		$node->method('fopen')->willReturnCallback(
			static function () use ($content) {
				$stream = fopen('php://memory', 'r+');
				fwrite($stream, $content);
				rewind($stream);
				return $stream;
			}
		);

		return $node;
	}//end fileDouble()

	/**
	 * A folder double that finds the given files by id.
	 *
	 * @param array<int, File> $files The files.
	 *
	 * @return Folder&MockObject The folder.
	 */
	public function folderOver(array $files): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(
			static fn (int $id): array => (isset($files[$id]) === true) ? [$files[$id]] : []
		);

		return $folder;
	}//end folderOver()

	/**
	 * The service under test over the real store, projection and defaults.
	 *
	 * @return WooGatherAdd The service.
	 */
	private function service(): WooGatherAdd {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);

		$store = new DocumentRecordStore(settingsService: $this->settings);
		$caseTypes = $this->createMock(CaseTypeResolver::class);
		$caseTypes->method('effectiveCaseType')->willReturn([]);
		$projection = new DocumentProjectionService(
			store: $store,
			defaults: new DocumentDefaults(store: $store, caseTypes: $caseTypes, userSession: $session),
			settingsService: $this->settings,
			logger: $this->createMock(LoggerInterface::class),
		);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturnCallback(
			fn (int $id): array => (isset($this->userFiles[$id]) === true) ? [$this->userFiles[$id]] : []
		);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('pjansen')->willReturn($userFolder);

		$sources = $this->createMock(WooSources::class);
		$sources->method('fetchMicrosoft365')->willReturnCallback(
			fn (string $handle, string $userId): ?array => ($this->fetchable[$handle] ?? null)
		);

		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturnCallback(
			fn (string $caseId, IUser $user): bool => ($caseId === self::OTHER_CASE_ID && $this->readsOtherCase)
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new WooGatherAdd(
			rootFolder: $rootFolder,
			store: $store,
			projection: $projection,
			sources: $sources,
			accessGuard: $guard,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * The outstanding count the assessment reads, over the same register.
	 *
	 * @return array<string, mixed> `{count, documents}`.
	 */
	private function outstanding(): array {
		$session = $this->createMock(IUserSession::class);
		$assessment = new WOODocumentAssessmentService(
			settingsService: $this->settings,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
			caseDocuments: new WooCaseDocuments(
				settingsService: $this->settings,
				rootFolder: $this->createMock(IRootFolder::class),
				logger: $this->createMock(LoggerInterface::class),
			),
		);

		return $assessment->getOutstanding(caseId: self::CASE_ID);
	}//end outstanding()

	/**
	 * REQ-WOO-013 "Two files added, one refused".
	 *
	 * @return void
	 */
	public function testTwoFilesAreAddedAndAFileTheCallerCannotReadIsRefused(): void {
		$this->userFiles[11] = $this->fileDouble(path: '/pjansen/files/Team Ruimte/notulen.pdf', fileId: 11, content: 'notulen');
		$this->userFiles[12] = $this->fileDouble(path: '/pjansen/files/Team Verkeer/memo.pdf', fileId: 12, content: 'memo');
		$this->userFiles[13] = $this->fileDouble(path: '/pjansen/files/Directie/geheim.pdf', fileId: 13, content: 'geheim', readable: false);

		$results = $this->service()->add(
			caseId: self::CASE_ID,
			picks: [
				['source' => 'files', 'key' => '11', 'location' => 'Team Ruimte'],
				['source' => 'files', 'key' => '12', 'location' => 'Team Verkeer'],
				['source' => 'files', 'key' => '13', 'location' => 'Directie'],
			],
			terms: 'Stationsweg',
			user: $this->user,
		);

		self::assertSame(['added', 'added', 'refused'], array_column($results, 'status'));
		self::assertSame('not-readable', $results[2]['reason']);
		self::assertSame('You cannot read this document, so it cannot be added.', $results[2]['message']);

		$outstanding = $this->outstanding();
		self::assertSame(2, $outstanding['count']);
		self::assertEqualsCanonicalizing([$results[0]['documentId'], $results[1]['documentId']], $outstanding['documents']);
	}//end testTwoFilesAreAddedAndAFileTheCallerCannotReadIsRefused()

	/**
	 * A file id the caller's own folder does not hold is refused, not looked up elsewhere.
	 *
	 * @return void
	 */
	public function testAFileOutsideTheCallersFolderIsRefused(): void {
		$results = $this->service()->add(caseId: self::CASE_ID, picks: [['source' => 'files', 'key' => '77']], terms: 'x', user: $this->user);

		self::assertSame('refused', $results[0]['status']);
		self::assertSame('not-readable', $results[0]['reason']);
		self::assertSame([], $this->caseFiles);
	}//end testAFileOutsideTheCallersFolderIsRefused()

	/**
	 * REQ-WOO-014: the projection carries source, location, terms, searchedAt and searchedBy.
	 *
	 * @return void
	 */
	public function testTheAddedDocumentRecordsWhereItWasFound(): void {
		$this->userFiles[11] = $this->fileDouble(path: '/pjansen/files/Team Ruimte/notulen.pdf', fileId: 11, content: 'notulen');

		$results = $this->service()->add(
			caseId: self::CASE_ID,
			picks: [['source' => 'files', 'key' => '11', 'location' => 'Team Ruimte/notulen.pdf']],
			terms: 'bouwvergunning 2024',
			user: $this->user,
		);

		$record = $this->register->row('informatieobject', $results[0]['documentId']);
		self::assertSame('files', $record['provenance']['source']);
		self::assertSame('Team Ruimte/notulen.pdf', $record['provenance']['location']);
		self::assertSame('bouwvergunning 2024', $record['provenance']['terms']);
		self::assertSame('pjansen', $record['provenance']['searchedBy']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $record['provenance']['searchedAt']);
		self::assertSame('notulen.pdf', $record['fileName']);

		// OpenRegister drops an undeclared property in silence, and the validator allows one.
		$real = new RealSchemaValidator();
		self::assertArrayHasKey('provenance', $real->schemas['informatieobject']['properties']);
		self::assertSame([], $real->errors(slug: 'informatieobject', payload: ['provenance' => $record['provenance']], creating: false));
	}//end testTheAddedDocumentRecordsWhereItWasFound()

	/**
	 * A document of another case is linked, not copied, and the provenance sits on this case's join.
	 *
	 * @return void
	 */
	public function testADocumentOfAnotherCaseIsLinkedNotCopied(): void {
		$results = $this->service()->add(
			caseId: self::CASE_ID,
			picks: [['source' => 'cases', 'key' => self::LINKED_DOC, 'location' => 'Omgevingsvergunning Stationsweg']],
			terms: 'Stationsweg',
			user: $this->user,
		);

		self::assertSame('added', $results[0]['status']);
		self::assertSame(self::LINKED_DOC, $results[0]['documentId']);
		self::assertSame([], $this->caseFiles, 'a linked document is not copied');
		self::assertArrayNotHasKey('provenance', $this->register->row('informatieobject', self::LINKED_DOC), 'the other case\'s record is not rewritten');

		$joins = array_values(array_filter($this->register->all('zaakinformatieobject'), static fn (array $join): bool => $join['case'] === self::CASE_ID));
		self::assertCount(1, $joins);
		self::assertSame('cases', $joins[0]['provenance']['source']);

		$real = new RealSchemaValidator();
		self::assertArrayHasKey('provenance', $real->schemas['zaakinformatieobject']['properties']);
		self::assertSame([], $real->errors(slug: 'zaakinformatieobject', payload: ['provenance' => $joins[0]['provenance']], creating: false));
		self::assertSame([self::LINKED_DOC], $this->outstanding()['documents']);
	}//end testADocumentOfAnotherCaseIsLinkedNotCopied()

	/**
	 * A document of a case the caller may not read is refused, and linking it twice is refused too.
	 *
	 * @return void
	 */
	public function testALinkTheCallerMayNotReadOrAlreadyHasIsRefused(): void {
		$this->readsOtherCase = false;
		$refused = $this->service()->add(caseId: self::CASE_ID, picks: [['source' => 'cases', 'key' => self::LINKED_DOC]], terms: 'x', user: $this->user);
		self::assertSame('not-readable', $refused[0]['reason']);

		$this->readsOtherCase = true;
		$service = $this->service();
		$service->add(caseId: self::CASE_ID, picks: [['source' => 'cases', 'key' => self::LINKED_DOC]], terms: 'x', user: $this->user);
		$again = $service->add(caseId: self::CASE_ID, picks: [['source' => 'cases', 'key' => self::LINKED_DOC]], terms: 'x', user: $this->user);
		self::assertSame('already-on-case', $again[0]['reason']);

		$missing = $service->add(caseId: self::CASE_ID, picks: [['source' => 'cases', 'key' => 'no-such-document']], terms: 'x', user: $this->user);
		self::assertSame('not-found', $missing[0]['reason']);
	}//end testALinkTheCallerMayNotReadOrAlreadyHasIsRefused()

	/**
	 * An integriq hit is fetched and stored like a file; one integriq will not hand over is refused.
	 *
	 * @return void
	 */
	public function testAnIntegriqHitIsFetchedIntoTheCaseFolder(): void {
		$this->fetchable['driveItem:d1:i1'] = ['fileName' => 'raadsvoorstel.docx', 'mimeType' => 'application/msword', 'content' => 'voorstel'];

		$results = $this->service()->add(
			caseId: self::CASE_ID,
			picks: [
				['source' => 'microsoft365', 'key' => 'driveItem:d1:i1', 'location' => 'Ruimte / Gedeelde documenten'],
				['source' => 'microsoft365', 'key' => 'message:m9'],
				['source' => 'elsewhere', 'key' => 'x'],
			],
			terms: 'Stationsweg',
			user: $this->user,
		);

		self::assertSame(['added', 'refused', 'refused'], array_column($results, 'status'));
		self::assertSame(['', 'not-fetched', 'unknown-source'], array_column($results, 'reason'));
		self::assertSame('microsoft365', $this->register->row('informatieobject', $results[0]['documentId'])['provenance']['source']);
		self::assertCount(1, $this->caseFiles);
	}//end testAnIntegriqHitIsFetchedIntoTheCaseFolder()

	/**
	 * A record the node listener already projected is reused, not duplicated.
	 *
	 * @return void
	 */
	public function testARecordTheListenerAlreadyMadeIsReused(): void {
		$this->userFiles[11] = $this->fileDouble(path: '/pjansen/files/a.pdf', fileId: 11, content: 'a');
		$this->register->seed('informatieobject', 'listener-made', ['fileId' => 900, 'fileName' => 'a.pdf', 'title' => 'a']);

		$results = $this->service()->add(caseId: self::CASE_ID, picks: [['source' => 'files', 'key' => '11']], terms: 't', user: $this->user);

		self::assertSame('listener-made', $results[0]['documentId']);
		self::assertCount(2, $this->register->all('informatieobject'), 'the seeded linked doc and the listener\'s record, nothing more');
	}//end testARecordTheListenerAlreadyMadeIsReused()
}//end class

