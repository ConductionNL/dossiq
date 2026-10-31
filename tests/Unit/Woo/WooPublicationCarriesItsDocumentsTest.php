<?php

/**
 * The Woo publication carries every disclosable document, read from OpenRegister's tree.
 *
 * Rehearsal R3 (16 Oct): a case with one public and one partly public
 * document published with no files at all. The case files live in the
 * openregister account's home, the handler has no mount on it, so
 * `IRootFolder::getById()` under the handler's session found nothing, the
 * documents went out without content and the transport skipped each one.
 * Here the root folder answers nothing, exactly as it did for the handler,
 * and only OpenRegister's file service knows the files.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Woo
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\Publication\OpenCatalogiApiClient;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WooPublication\WooCategoryMapper;
use OCA\Dossiq\Service\WooPublicationService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Woo\WooCaseLedger;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Publish a case with every kind of assessment, as the handler.
 *
 * @covers \OCA\Dossiq\Woo\WooCaseDocuments
 * @covers \OCA\Dossiq\Service\WooPublicationService
 * @covers \OCA\Dossiq\Service\Support\FindsFilesById
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses   \OCA\Dossiq\Woo\WooCaseLedger
 * @uses   \OCA\Dossiq\Service\WooPublication\WooCategoryMapper
 * @uses   \OCA\Dossiq\Woo\WooResultLink
 * @uses   \OCA\Dossiq\Service\Publication\PublicationSources
 * @uses   \OCA\Dossiq\Service\Publication\CatalogTransport
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class WooPublicationCarriesItsDocumentsTest extends TestCase {

	private const CASE = 'case-r3';

	/**
	 * The file id of each informatieobject, and its bytes.
	 *
	 * @var array<string, array{0: int, 1: string, 2: string}>
	 */
	private const FILES = [
		'io-public' => [101, 'klachtenoverzicht.pdf', '%PDF overzicht'],
		'io-partly' => [102, 'mail-wijkteam.pdf', '%PDF mail with names'],
		'io-redacted' => [103, 'mail-wijkteam_gelakt.pdf', '%PDF mail without names'],
		'io-partly-uncopied' => [104, 'notitie.pdf', '%PDF notitie with names'],
		'io-withheld' => [105, 'advies.pdf', '%PDF advies'],
	];

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The app config the import sets.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Every attach the catalog was asked for: file name and decoded bytes.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $attached = [];

	/**
	 * Seed a case with one document per kind of assessment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$dir = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($dir . '/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $dir . '/register.d');
		$this->config = ['register' => 'dossiq'];
		foreach (SchemaSlugMap::SLUG_TO_CONFIG_KEY as $slug => $key) {
			if (isset($merged['components']['schemas'][$slug]) === true) {
				$this->config[$key] = $slug;
			}
		}

		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'case', uuid: self::CASE, row: ['title' => 'Klachten afval 2025', 'wooPublicationStatus' => 'ready']);
		$this->store->seed(schema: 'decision', uuid: 'dec-1', row: ['case' => self::CASE, 'wooSummary' => ['openbaar' => 1, 'deels_openbaar' => 2, 'niet_openbaar' => 1]]);
		foreach (self::FILES as $id => [$fileId, $name]) {
			$this->store->seed(schema: 'informatieobject', uuid: $id, row: ['title' => $name, 'fileName' => $name, 'format' => 'application/pdf', 'fileId' => $fileId]);
			$this->store->seed(schema: 'zaakinformatieobject', uuid: 'zio-' . $id, row: ['case' => self::CASE, 'informatieobject' => $id]);
		}

		$assessments = $this->config['woo_assessment_schema'];
		$this->store->seed(schema: $assessments, uuid: 'a-1', row: ['caseRef' => self::CASE, 'documentRef' => 'io-public', 'classification' => 'openbaar']);
		$this->store->seed(schema: $assessments, uuid: 'a-2', row: ['caseRef' => self::CASE, 'documentRef' => 'io-partly', 'classification' => 'deels_openbaar', 'redactedDocumentRef' => 'io-redacted']);
		$this->store->seed(schema: $assessments, uuid: 'a-3', row: ['caseRef' => self::CASE, 'documentRef' => 'io-partly-uncopied', 'classification' => 'deels_openbaar']);
		$this->store->seed(schema: $assessments, uuid: 'a-4', row: ['caseRef' => self::CASE, 'documentRef' => 'io-withheld', 'classification' => 'niet_openbaar']);
	}//end setUp()

	/**
	 * Every public document and the redacted copy go out, read through OpenRegister; nothing else does.
	 *
	 * @return void
	 */
	public function testThePublicDocumentAndTheRedactedCopyAreAttached(): void {
		$published = $this->service(fileService: $this->openRegisterFiles())->publish(self::CASE);

		self::assertTrue($published['available'], (string)($published['reason'] ?? ''));
		self::assertSame(
			[
				['klachtenoverzicht.pdf', '%PDF overzicht'],
				['mail-wijkteam_gelakt.pdf', '%PDF mail without names'],
			],
			$this->attached
		);
	}//end testThePublicDocumentAndTheRedactedCopyAreAttached()

	/**
	 * Control: where nothing can read the files, nothing is published, rather than a publication without them.
	 *
	 * @return void
	 */
	public function testAPublicationWhoseFilesCannotBeReadIsRefused(): void {
		$published = $this->service(fileService: null)->publish(self::CASE);

		self::assertFalse($published['available']);
		self::assertSame('documents_unreadable', $published['reason']);
		self::assertSame(['klachtenoverzicht.pdf', 'mail-wijkteam_gelakt.pdf'], $published['documents']);
		self::assertSame([], $this->attached);
		self::assertSame('ready', $this->store->row(schema: 'case', uuid: self::CASE)['wooPublicationStatus']);
	}//end testAPublicationWhoseFilesCannotBeReadIsRefused()

	/**
	 * A disclosable document that names its only readers is not published, and nothing goes out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-with-an-exception-is-never-published
	 */
	public function testAPublicationWithADocumentUnderAnAccessExceptionIsRefused(): void {
		$row = $this->store->row(schema: 'informatieobject', uuid: 'io-public');
		$row['accessException'] = ['users' => ['pieter'], 'groups' => [], 'reason' => 'Bedrijfsgevoelig'];
		$this->store->seed(schema: 'informatieobject', uuid: 'io-public', row: $row);

		$published = $this->service(fileService: $this->openRegisterFiles())->publish(self::CASE);

		self::assertFalse($published['available']);
		self::assertSame('documents_restricted', $published['reason']);
		self::assertSame(['klachtenoverzicht.pdf'], $published['documents']);
		self::assertSame([], $this->attached);
	}//end testAPublicationWithADocumentUnderAnAccessExceptionIsRefused()

	/**
	 * OpenRegister's file service as the case files see it: it knows every file by id.
	 *
	 * @return object
	 */
	private function openRegisterFiles(): object {
		$files = [];
		foreach (self::FILES as [$fileId, , $bytes]) {
			$file = $this->createMock(File::class);
			$file->method('getContent')->willReturn($bytes);
			$files[$fileId] = $file;
		}

		return new class($files) {
			/**
			 * Constructor.
			 *
			 * @param array<int, File> $files The files by id.
			 */
			public function __construct(private readonly array $files) {
			}

			/**
			 * The file, from the managed tree.
			 *
			 * @param int $fileId The file id.
			 *
			 * @return File|null
			 */
			public function getFileById(int $fileId): ?File {
				return ($this->files[$fileId] ?? null);
			}
		};
	}//end openRegisterFiles()

	/**
	 * The publication service, as the handler: the root folder finds none of the case files.
	 *
	 * @param object|null $fileService OpenRegister's file service, or null.
	 *
	 * @return WooPublicationService
	 */
	private function service(?object $fileService): WooPublicationService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getFileService')->willReturn($fileService);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$settings->method('getWooPublicationConfigValue')->willReturnCallback(
			fn (string $key): string => (SettingsService::WOO_PUBLICATION_DEFAULTS[$key] ?? '')
		);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->willReturn([]);

		$client = $this->createMock(OpenCatalogiApiClient::class);
		$client->method('createPublication')->willReturn(['id' => 'pub-1']);
		$client->method('attachFile')->willReturnCallback(
			function (string $register, string $schema, string $objectId, string $fileName, string $base64Content): array {
				$this->attached[] = [$fileName, (string)base64_decode($base64Content, true)];
				return [];
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturn(true);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);

		return new WooPublicationService(
			$settings,
			$client,
			new WooCategoryMapper(),
			$apps,
			$logger,
			null,
			new WooCaseLedger(settingsService: $settings, logger: $logger, urlGenerator: $urls),
			new WooCaseDocuments(settingsService: $settings, rootFolder: $root, logger: $logger),
		);
	}//end service()
}//end class
