<?php

/**
 * A Woo decision is assessed and published against the register dossiq really ships.
 *
 * The e2e journey (portaliq#1001) found two defects no mock could: the
 * assessment answered 400 because no schema set `woo_assessment_schema`, and
 * publish found no document because it read `document_schema` while the case
 * upload writes informatieobjecten linked through zaakinformatieobject. So this
 * test takes its configuration from the merged register and the slug map, the
 * way the import sets it, and never from a hand-written config map.
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
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\WooPublication\OpenCatalogiApiClient;
use OCA\Dossiq\Service\WooPublication\WooCategoryMapper;
use OCA\Dossiq\Service\WooPublicationService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Woo\WooCaseLedger;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Assess, then publish, on the real schemas.
 *
 * @covers \OCA\Dossiq\Woo\WooCaseDocuments
 * @covers \OCA\Dossiq\Service\WOODocumentAssessmentService
 * @covers \OCA\Dossiq\Service\WooPublicationService
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses   \OCA\Dossiq\Woo\WooCaseLedger
 * @uses   \OCA\Dossiq\Woo\WooRefusalGrounds
 * @uses   \OCA\Dossiq\Service\WooPublication\WooCategoryMapper
 */
class WooPublishOnTheRealRegisterTest extends TestCase {

	private const CASE = 'case-woo-1';

	private const DOCUMENT = 'io-1';

	private const FILE_ID = 4242;

	/**
	 * The merged register's schemas.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $schemas = [];

	/**
	 * The app config the import sets: config key to schema slug, for every shipped schema.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Settings answering from the real register.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * The case documents reader.
	 *
	 * @var WooCaseDocuments
	 */
	private WooCaseDocuments $documents;

	/**
	 * Build the real configuration and a case with one uploaded document.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$dir = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($dir . '/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $dir . '/register.d');
		$this->schemas = $merged['components']['schemas'];

		$this->config = ['register' => 'dossiq'];
		foreach (SchemaSlugMap::SLUG_TO_CONFIG_KEY as $slug => $key) {
			if (isset($this->schemas[$slug]) === true) {
				$this->config[$key] = $slug;
			}
		}

		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'case', uuid: self::CASE, row: ['title' => 'Parkeerbeleid', 'wooPublicationStatus' => 'ready']);
		// What the case upload writes: an informatieobject with its file, and the join to the case.
		$this->store->seed(schema: 'informatieobject', uuid: self::DOCUMENT, row: [
			'title' => 'Nota parkeren',
			'fileName' => 'nota.pdf',
			'format' => 'application/pdf',
			'fileId' => self::FILE_ID,
			'vertrouwelijkheidaanduiding' => 'openbaar',
			'informatieobjecttype' => 'iot-1',
		]);
		$this->store->seed(schema: 'zaakinformatieobject', uuid: 'zio-1', row: ['case' => self::CASE, 'informatieobject' => self::DOCUMENT]);
		$this->store->seed(schema: 'decision', uuid: 'dec-1', row: ['case' => self::CASE, 'wooSummary' => ['openbaar' => 1]]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$settings->method('getWooPublicationConfigValue')->willReturnCallback(
			fn (string $key): string => (SettingsService::WOO_PUBLICATION_DEFAULTS[$key] ?? '')
		);
		$this->settings = $settings;

		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('%PDF nota');
		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->willReturnCallback(fn (int $id): array => ($id === self::FILE_ID) ? [$file] : []);

		$this->documents = new WooCaseDocuments(settingsService: $settings, rootFolder: $root, logger: $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * The import configures the assessment schema, and it declares what the assessment writes.
	 *
	 * @return void
	 */
	public function testTheImportConfiguresTheAssessmentSchema(): void {
		$slug = ($this->config['woo_assessment_schema'] ?? '');
		self::assertNotSame('', $slug, 'no shipped schema sets woo_assessment_schema, so every assessment answers 400');
		self::assertNotSame('wooAssessment', $slug, 'opencatalogi owns the slug wooAssessment; slugs are global on a shared OpenRegister');
		foreach (['caseRef', 'documentRef', 'classification', 'weigeringsgronden', 'redactedDocumentRef', 'assessedBy', 'assessedAt', 'redactionProposal'] as $field) {
			self::assertArrayHasKey($field, $this->schemas[$slug]['properties'], $field);
		}
	}//end testTheImportConfiguresTheAssessmentSchema()

	/**
	 * The uploaded document is outstanding, then assessed, then published with its file.
	 *
	 * @return void
	 */
	public function testAnUploadedDocumentIsAssessedAndPublished(): void {
		$assessments = new WOODocumentAssessmentService(
			settingsService: $this->settings,
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
			caseDocuments: $this->documents,
		);

		self::assertSame(['count' => 1, 'documents' => [self::DOCUMENT]], $assessments->getOutstanding(caseId: self::CASE));

		$result = $assessments->bulkUpsert(caseId: self::CASE, assessments: [['documentRef' => self::DOCUMENT, 'classification' => 'openbaar']]);
		self::assertSame([], $result['errors']);
		self::assertSame(0, $result['outstanding']['count']);

		$client = $this->createMock(OpenCatalogiApiClient::class);
		$client->method('createPublication')->willReturn(['id' => 'pub-1']);
		$client->expects(self::once())->method('attachFile')->with(
			'publication',
			'publication',
			'pub-1',
			'nota.pdf',
			base64_encode('%PDF nota'),
			'application/pdf',
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturn(true);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnArgument(0);

		$service = new WooPublicationService(
			$this->settings,
			$client,
			new WooCategoryMapper(),
			$apps,
			$this->createMock(LoggerInterface::class),
			null,
			new WooCaseLedger(settingsService: $this->settings, logger: $this->createMock(LoggerInterface::class), urlGenerator: $urls),
			$this->documents,
		);

		$published = $service->publish(self::CASE);

		self::assertTrue($published['available'], (string)($published['reason'] ?? ''));
		self::assertSame('published', $this->store->row(schema: 'case', uuid: self::CASE)['wooPublicationStatus']);

		// Every written row stays inside what its real schema declares.
		foreach ([$this->config['woo_assessment_schema'] => $this->store->all(schema: $this->config['woo_assessment_schema']), 'decision' => [$this->store->row(schema: 'decision', uuid: 'dec-1')], 'case' => [$this->store->row(schema: 'case', uuid: self::CASE)]] as $slug => $rows) {
			foreach ($rows as $row) {
				foreach (array_keys($row) as $key) {
					if ($key === 'id') {
						continue;
					}

					self::assertArrayHasKey($key, $this->schemas[$slug]['properties'], $slug . ' does not declare ' . $key);
				}
			}
		}
	}//end testAnUploadedDocumentIsAssessedAndPublished()
}//end class
