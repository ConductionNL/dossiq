<?php

/**
 * Every Woo write fits the schema dossiq ships, value by value.
 *
 * The e2e Woo journey (portaliq#1001) hit two 500s the unit tests could not
 * see: the Woo decision was written with the decision type's NAME where the
 * decision schema declares the uuid of a decisionType, and the seeded Woo case
 * type never imported because its references were slugs where uuids are
 * declared. Both were keys the schema declares with values it refuses. This
 * validates the exact payloads (and the seed rows) against the merged register
 * with the validator OpenRegister is built on.
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
 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-decision-schema-declares-the-woo-fields-its-writers-send-req-wpi-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODecisionService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\WooPublication\OpenCatalogiApiClient;
use OCA\Dossiq\Service\WooPublication\WooCategoryMapper;
use OCA\Dossiq\Service\WooPublicationService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Dossiq\Tests\Support\UuidAnsweringRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Woo\WooCaseLedger;
use OCA\Dossiq\Woo\WooRequestIntake;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Seed rows, decision, assessment, request and publication against the real schemas.
 *
 * @covers \OCA\Dossiq\Service\WOODecisionService
 * @covers \OCA\Dossiq\Service\WOODocumentAssessmentService
 * @covers \OCA\Dossiq\Woo\WooRequestIntake
 * @covers \OCA\Dossiq\Service\WooPublicationService
 * @uses   \OCA\Dossiq\Woo\WooRefusalGrounds
 * @uses   \OCA\Dossiq\Woo\WooRequesterProperties
 * @uses   \OCA\Dossiq\Woo\WooWrittenCase
 * @uses   \OCA\Dossiq\Woo\WooCaseLedger
 * @uses   \OCA\Dossiq\Woo\WooCaseDocuments
 * @uses   \OCA\Dossiq\Woo\WooRequestForm
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses   \OCA\Dossiq\Service\WooPublication\WooCategoryMapper
 */
class WooWritesMatchTheRealSchemasTest extends TestCase {

	private const CASE = '3c0f5a00-0000-4000-a000-0000000c0001';

	/**
	 * The merged register and its validator.
	 *
	 * @var RealSchemaValidator
	 */
	private RealSchemaValidator $real;

	/**
	 * The rows.
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
	 * Load the register and configure the way the import does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->real = new RealSchemaValidator();
		$config = ['register' => 'dossiq'];
		foreach (SchemaSlugMap::SLUG_TO_CONFIG_KEY as $slug => $key) {
			if (isset($this->real->schemas[$slug]) === true) {
				$config[$key] = $slug;
			}
		}

		$this->store = new InMemoryRegister();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(new UuidAnsweringRegister(store: $this->store));
		$settings->method('getConfigValue')->willReturnCallback(fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$settings->method('getWooPublicationConfigValue')->willReturnCallback(
			fn (string $key): string => (SettingsService::WOO_PUBLICATION_DEFAULTS[$key] ?? '')
		);
		$this->settings = $settings;
	}//end setUp()

	/**
	 * Assert a row fits its schema.
	 *
	 * @param string               $slug     The schema.
	 * @param array<string, mixed> $row      The row.
	 * @param bool                 $creating Whether `required` applies.
	 *
	 * @return void
	 */
	private function assertFits(string $slug, array $row, bool $creating = true): void {
		self::assertSame([], $this->real->errors(slug: $slug, payload: $row, creating: $creating), json_encode($row));
	}//end assertFits()

	/**
	 * Every Woo seed row imports: its references are uuids where uuids are declared.
	 *
	 * @return void
	 */
	public function testEveryWooSeedRowFitsItsSchema(): void {
		$checked = 0;
		foreach ($this->real->objects as $object) {
			$slug = (string)($object['@self']['slug'] ?? '');
			if (str_starts_with($slug, 'woo-verzoek') === false) {
				continue;
			}

			$this->assertFits(slug: (string)$object['@self']['schema'], row: $object);
			$checked++;
		}

		self::assertGreaterThanOrEqual(24, $checked, 'the type, its statuses, results, properties and decision type');
	}//end testEveryWooSeedRowFitsItsSchema()

	/**
	 * The Woo decision names its decision type by the uuid the Woo case type declares.
	 *
	 * @return void
	 */
	public function testTheAssembledDecisionFitsTheDecisionSchema(): void {
		$assessments = $this->createMock(WOODocumentAssessmentService::class);
		$assessments->method('getOutstanding')->willReturn(['count' => 0, 'documents' => []]);
		$this->store->seed(schema: 'case', uuid: self::CASE, row: ['title' => 'Parkeren', 'caseType' => WooRequestIntake::CASE_TYPE_ID]);
		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: ['caseRef' => self::CASE, 'documentRef' => 'io-1', 'classification' => 'openbaar']);

		$service = new WOODecisionService($this->settings, $assessments, $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class));
		$result = $service->assembleDecision(caseId: self::CASE);

		$decision = $this->store->row(schema: 'decision', uuid: $result['decisionId']);
		$this->assertFits(slug: 'decision', row: $decision);
		self::assertSame(WOODecisionService::DECISION_TYPE_ID, $decision['decisionType']);

		$wooType = array_values(array_filter($this->real->objects, fn (array $o): bool => ($o['@self']['slug'] ?? '') === 'woo-verzoek'))[0];
		self::assertContains(WOODecisionService::DECISION_TYPE_ID, $wooType['decisionTypes']);
		$this->assertFits(slug: 'case', row: $this->store->row(schema: 'case', uuid: self::CASE), creating: false);
	}//end testTheAssembledDecisionFitsTheDecisionSchema()

	/**
	 * An assessment row fits the assessment schema, dates with their offset.
	 *
	 * @return void
	 */
	public function testAnAssessmentRowFitsItsSchema(): void {
		foreach ($this->real->objects as $index => $object) {
			if (($object['@self']['schema'] ?? '') === 'wooRefusalGround') {
				$this->store->seed(schema: 'wooRefusalGround', uuid: 'ground-' . $index, row: $object);
			}
		}

		$service = new WOODocumentAssessmentService($this->settings, $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class));
		$service->bulkUpsert(caseId: self::CASE, assessments: [['documentRef' => 'io-1', 'classification' => 'deels_openbaar', 'weigeringsgronden' => ['5.1.2.e']]]);

		$rows = $this->store->all(schema: 'wooDocumentAssessment');
		self::assertCount(1, $rows);
		$this->assertFits(slug: 'wooDocumentAssessment', row: $rows[0]);
	}//end testAnAssessmentRowFitsItsSchema()

	/**
	 * A request without a period, and its case objects, fit the case and caseObject schemas.
	 *
	 * @return void
	 */
	public function testARequestCaseFitsTheCaseSchema(): void {
		$this->store->seed(schema: 'caseType', uuid: WooRequestIntake::CASE_TYPE_ID, row: ['title' => 'Woo-verzoek', 'initialStatus' => '3c0f5a00-0000-4000-a000-00000000b001']);
		$this->store->seed(schema: 'collection', uuid: 'col-1', row: [
			'title' => 'Parkeren', 'owner' => 'sub-1', 'sourceOf' => [],
			'items' => [['id' => 'i1', 'publication' => 'pub-1', 'attachment' => null, 'note' => '', 'addedAt' => '2026-09-01T10:00:00+00:00', 'addedBy' => 'resident']],
		]);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://gemeente.test' . $path);

		$intake = new WooRequestIntake(settingsService: $this->settings, urlGenerator: $urls, logger: $this->createMock(LoggerInterface::class));
		$result = $intake->start(['subjectRef' => 'sub-1', 'collectionId' => 'col-1', 'onderwerp' => 'Parkeren', 'omschrijving' => '', 'origin' => 'portal']);

		$this->assertFits(slug: 'case', row: $this->store->row(schema: 'case', uuid: $result['caseId']));
		foreach ($this->store->all(schema: 'caseObject') as $object) {
			// The in-memory store's ids are not uuids; OpenRegister's are.
			$object['case'] = self::CASE;
			$this->assertFits(slug: 'caseObject', row: $object);
		}
	}//end testARequestCaseFitsTheCaseSchema()

	/**
	 * Publishing writes a decision and a case state that fit their schemas.
	 *
	 * @return void
	 */
	public function testPublishingWritesFitTheirSchemas(): void {
		$this->store->seed(schema: 'case', uuid: self::CASE, row: ['title' => 'Parkeren', 'caseType' => WooRequestIntake::CASE_TYPE_ID, 'wooPublicationStatus' => 'ready']);
		$this->store->seed(schema: 'decision', uuid: 'dec-1', row: ['case' => self::CASE, 'decisionType' => WOODecisionService::DECISION_TYPE_ID, 'wooSummary' => ['openbaar' => 1]]);
		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: ['caseRef' => self::CASE, 'documentRef' => 'io-1', 'classification' => 'openbaar']);
		$this->store->seed(schema: 'zaakinformatieobject', uuid: 'zio-1', row: ['case' => self::CASE, 'informatieobject' => 'io-1']);
		$this->store->seed(schema: 'informatieobject', uuid: 'io-1', row: ['title' => 'Nota', 'fileName' => 'nota.pdf', 'format' => 'application/pdf', 'content' => base64_encode('pdf')]);

		$client = $this->createMock(OpenCatalogiApiClient::class);
		$client->method('createPublication')->willReturn(['id' => 'pub-1']);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturn(true);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://gemeente.test' . $path);

		$service = new WooPublicationService(
			$this->settings,
			$client,
			new WooCategoryMapper(),
			$apps,
			$this->createMock(LoggerInterface::class),
			null,
			new WooCaseLedger(settingsService: $this->settings, logger: $this->createMock(LoggerInterface::class), urlGenerator: $urls),
			new WooCaseDocuments(settingsService: $this->settings, rootFolder: $this->createMock(IRootFolder::class), logger: $this->createMock(LoggerInterface::class)),
		);

		$published = $service->publish(self::CASE);
		self::assertTrue($published['available'], (string)($published['reason'] ?? ''));
		$this->assertFits(slug: 'decision', row: $this->store->row(schema: 'decision', uuid: 'dec-1'));
		$this->assertFits(slug: 'case', row: $this->store->row(schema: 'case', uuid: self::CASE), creating: false);

		self::assertTrue($service->withdraw('', self::CASE)['available']);
		$this->assertFits(slug: 'decision', row: $this->store->row(schema: 'decision', uuid: 'dec-1'));
	}//end testPublishingWritesFitTheirSchemas()
}//end class
