<?php

/**
 * A Woo publish records what it delivered, and a failed one leaves nothing behind.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WooPublication\OpenCatalogiApiClient;
use OCA\Dossiq\Service\WooPublication\WooCategoryMapper;
use OCA\Dossiq\Service\WooPublicationService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Dossiq\Woo\WooDeliveredSetWriter;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * REQ-WDS-001 and REQ-WDS-002 through WooPublicationService::publish() and withdraw(), over a real store.
 *
 * @covers \OCA\Dossiq\Service\WooPublicationService
 * @covers \OCA\Dossiq\Woo\WooDeliveredSetWriter
 *
 * @uses \OCA\Dossiq\Woo\WooCaseLedger
 * @uses \OCA\Dossiq\Woo\WooResultLink
 * @uses \OCA\Dossiq\Service\WooPublication\WooCategoryMapper
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class WooPublicationDeliveredSetTest extends TestCase {

	/**
	 * Cases, decisions, assessments, documents and sets.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * OpenCatalogi's side.
	 *
	 * @var OpenCatalogiApiClient&MockObject
	 */
	private OpenCatalogiApiClient&MockObject $api;

	/**
	 * Every file name and content sent to OpenCatalogi.
	 *
	 * @var list<array{string, string}>
	 */
	private array $attached = [];

	/**
	 * A decided Woo case: two public documents and one partly public with a finished redaction.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'case', uuid: 'case-1', row: ['title' => 'Woo-verzoek speeltuinen', 'identifier' => '2026-0087']);
		$this->store->seed(schema: 'decision', uuid: 'dec-1', row: ['case' => 'case-1', 'wooSummary' => ['openbaar' => 2], 'decisionDate' => '2026-10-07']);
		$documents = [
			'doc-1' => 'eerste openbare brief',
			'doc-2' => 'tweede openbare brief',
			'doc-3' => 'origineel met namen',
			'doc-3-red' => 'gelakte versie',
		];
		foreach ($documents as $uuid => $bytes) {
			$this->store->seed(schema: 'document', uuid: $uuid, row: ['title' => $uuid, 'fileName' => $uuid . '.pdf', 'format' => 'application/pdf', 'content' => base64_encode($bytes)]);
		}

		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'as-1', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-1', 'classification' => 'openbaar']);
		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'as-2', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-2', 'classification' => 'openbaar']);
		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'as-3', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-3', 'classification' => 'deels_openbaar', 'redactedDocumentRef' => 'doc-3-red']);
		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'as-4', row: ['caseRef' => 'case-1', 'documentRef' => 'doc-4', 'classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.2.e']]);

		$this->api = $this->createMock(OpenCatalogiApiClient::class);
		$this->attached = [];
		$this->api->method('attachFile')->willReturnCallback(
			function (string $register, string $schema, string $objectId, string $fileName, string $base64Content, string $mimeType): array {
				$this->attached[] = [$fileName, base64_decode($base64Content)];
				return ['id' => count($this->attached)];
			}
		);
	}//end setUp()

	/**
	 * The service over the store, with the real writer.
	 *
	 * @return WooPublicationService
	 */
	private function service(): WooPublicationService {
		$config = [
			'register' => 'dossiq',
			'case_schema' => 'case',
			'decision_schema' => 'decision',
			'woo_assessment_schema' => 'wooDocumentAssessment',
			'document_schema' => 'document',
			'woo_publication_catalog_slug' => 'publication',
		];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$settings->method('getWooPublicationConfigValue')->willReturn('publication');

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturn(true);

		return new WooPublicationService(
			settingsService: $settings,
			apiClient: $this->api,
			categoryMapper: new WooCategoryMapper(),
			appManager: $apps,
			logger: new NullLogger(),
			deliveredSets: new WooDeliveredSetWriter(settings: $settings),
		);
	}//end service()

	/**
	 * The sets the store holds.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sets(): array {
		return $this->store->all(schema: 'wooDeliveredSet');
	}//end sets()

	/**
	 * One frozen set with the publication id and three items; the redacted item carries the redacted bytes' hash.
	 *
	 * @return void
	 */
	public function testAPublishWritesAFrozenSet(): void {
		$this->api->method('createPublication')->willReturn(['id' => 'pub-1']);

		$result = $this->service()->publish(caseId: 'case-1', decisionId: 'dec-1');

		$this->assertTrue($result['available'], (string)json_encode($result));
		$sets = $this->sets();
		$this->assertCount(1, $sets);
		$set = $sets[0];
		$this->assertSame($result['deliveredSet'], $set['id']);
		$this->assertSame('frozen', $set['status']);
		$this->assertSame('pub-1', $set['publication']);
		$this->assertSame(['as-1', 'as-2', 'as-3'], array_column($set['items'], 'assessment'));
		$this->assertSame('doc-3-red', $set['items'][2]['deliveredRef']);
		$this->assertSame('doc-3', $set['items'][2]['originalRef']);
		$this->assertSame(hash('sha256', 'gelakte versie'), $set['items'][2]['sha256']);
		$this->assertSame((new WooDeliveredSetWriter(settings: $this->createMock(SettingsService::class)))->setHash(items: $set['items']), $set['setHash']);
	}//end testAPublishWritesAFrozenSet()

	/**
	 * The written set is valid against its own schema.
	 *
	 * @return void
	 */
	public function testTheWrittenSetValidatesAgainstItsSchema(): void {
		$this->api->method('createPublication')->willReturn(['id' => 'pub-1']);
		$this->service()->publish(caseId: 'case-1', decisionId: 'dec-1');

		$this->assertSame([], (new RealSchemaValidator())->errors(slug: 'wooDeliveredSet', payload: $this->sets()[0], creating: true));
	}//end testTheWrittenSetValidatesAgainstItsSchema()

	/**
	 * When OpenCatalogi refuses, the pending set is gone and publish answers the error.
	 *
	 * @return void
	 */
	public function testAFailedPublishLeavesNoSet(): void {
		$this->api->method('createPublication')->willThrowException(new RuntimeException('opencatalogi down'));

		$result = $this->service()->publish(caseId: 'case-1', decisionId: 'dec-1');

		$this->assertFalse($result['available']);
		$this->assertSame('opencatalogi_api_error', $result['reason']);
		$this->assertSame([], $this->sets());
	}//end testAFailedPublishLeavesNoSet()

	/**
	 * Neither the payload nor any attached file carries the original of the redacted document.
	 *
	 * @return void
	 */
	public function testThePayloadCarriesNoOriginalRef(): void {
		$payloads = [];
		$this->api->method('createPublication')->willReturnCallback(
			function (string $register, string $schema, array $payload) use (&$payloads): array {
				$payloads[] = $payload;
				return ['id' => 'pub-1'];
			}
		);

		$this->service()->publish(caseId: 'case-1', decisionId: 'dec-1');

		$this->assertStringNotContainsString('originalRef', (string)json_encode($payloads));
		$this->assertStringNotContainsString('doc-3"', (string)json_encode($payloads));
		$this->assertNotContains('origineel met namen', array_column($this->attached, 1));
		$this->assertContains('gelakte versie', array_column($this->attached, 1));
	}//end testThePayloadCarriesNoOriginalRef()

	/**
	 * A second delivery after a withdraw is a new set naming the first, which stays frozen with withdrawnAt.
	 *
	 * @return void
	 */
	public function testASecondDeliveryIsANewSetAndAWithdrawKeepsTheSetFrozen(): void {
		$this->api->method('createPublication')->willReturn(['id' => 'pub-1']);
		$this->api->method('updatePublication')->willReturn(['id' => 'pub-1']);
		$service = $this->service();

		$first = $service->publish(caseId: 'case-1', decisionId: 'dec-1');
		$this->assertTrue($service->withdraw(decisionId: 'dec-1', caseId: 'case-1')['available']);
		$second = $service->publish(caseId: 'case-1', decisionId: 'dec-1');

		$firstSet = $this->store->row(schema: 'wooDeliveredSet', uuid: $first['deliveredSet']);
		$secondSet = $this->store->row(schema: 'wooDeliveredSet', uuid: $second['deliveredSet']);
		$this->assertSame('frozen', $firstSet['status']);
		$this->assertNotEmpty($firstSet['withdrawnAt']);
		$this->assertSame($first['deliveredSet'], $secondSet['supersedes']);
	}//end testASecondDeliveryIsANewSetAndAWithdrawKeepsTheSetFrozen()
}//end class
