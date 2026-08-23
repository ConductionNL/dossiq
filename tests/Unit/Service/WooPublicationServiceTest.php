<?php

/**
 * WooPublicationService Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-publication-via-opencatalogi/specs/woo-publication-via-opencatalogi/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WooPublication\OpenCatalogiApiClient;
use OCA\Dossiq\Service\WooPublication\WooCategoryMapper;
use OCA\Dossiq\Service\WooPublicationService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Object service stub for WOO publication tests (OpenRegister object-first signature).
 */
interface WooPublicationObjectServiceStub {
	/**
	 * Save an object (OpenRegister object-first signature).
	 *
	 * @param array $object Object data.
	 * @param array $extend Extend parameters.
	 * @param string|null $register Register id.
	 * @param string|null $schema Schema id.
	 * @param string|null $uuid Optional object uuid.
	 *
	 * @return mixed
	 */
	public function saveObject(array $object, array $extend = [], ?string $register = null, ?string $schema = null, ?string $uuid = null);

	/**
	 * Single-object fetch (real ObjectService::find()).
	 *
	 * @param string $id Object id.
	 * @param array|null $extend Extend parameters.
	 * @param bool $files Whether to include files.
	 * @param string|null $register Register id.
	 * @param string|null $schema Schema id.
	 *
	 * @return mixed
	 */
	public function find(string $id, ?array $extend = null, bool $files = false, ?string $register = null, ?string $schema = null);

	/**
	 * Slug-aware search bridge (real ObjectService::searchObjectsBySlug()).
	 *
	 * @param string $registerSlug Register slug.
	 * @param string $schemaSlug Schema slug.
	 * @param array<string,mixed> $filters Query filters.
	 *
	 * @return array<int,mixed>|int
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array|int;
}//end interface

/**
 * Unit tests for WooPublicationService.
 *
 * @covers \OCA\Dossiq\Service\WooPublicationService
 *
 * @uses \OCA\Dossiq\Service\WooPublication\WooCategoryMapper
 */
class WooPublicationServiceTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var OpenCatalogiApiClient|\PHPUnit\Framework\MockObject\MockObject
	 */
	private OpenCatalogiApiClient $apiClient;

	/**
	 * @var IAppManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IAppManager $appManager;

	/**
	 * @var WooPublicationService
	 */
	private WooPublicationService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->apiClient = $this->createMock(OpenCatalogiApiClient::class);
		$this->appManager = $this->createMock(IAppManager::class);

		$this->service = new WooPublicationService(
			$this->settingsService,
			$this->apiClient,
			new WooCategoryMapper(),
			$this->appManager,
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	// -- selectDisclosableDocuments() — D4 redacted-only matrix --------------

	/**
	 * Openbaar documents are included as-is.
	 *
	 * @return void
	 */
	public function testOpenbaarDocumentIncludedAsIs(): void {
		$assessments = [
			['documentRef' => 'doc-001', 'classification' => 'openbaar'],
		];

		$loader = fn (string $ref) => ['id' => $ref, 'content' => 'original-content'];

		$result = $this->service->selectDisclosableDocuments($assessments, $loader);

		$this->assertCount(1, $result);
		$this->assertSame('doc-001', $result[0]['id']);
		$this->assertSame('original-content', $result[0]['content']);
	}//end testOpenbaarDocumentIncludedAsIs()

	/**
	 * Deels openbaar with a finalized redaction is included via the redacted
	 * reference ONLY — the original content must never appear.
	 *
	 * @return void
	 */
	public function testDeelsOpenbaarWithRedactionIncludesRedactedVersionOnly(): void {
		$assessments = [
			[
				'documentRef' => 'doc-002',
				'classification' => 'deels_openbaar',
				'redactedDocumentRef' => 'doc-002-redacted',
			],
		];

		$loader = function (string $ref) {
			if ($ref === 'doc-002-redacted') {
				return ['id' => 'doc-002-redacted', 'content' => 'REDACTED-content'];
			}

			// The original must never be requested by the redacted branch.
			return ['id' => $ref, 'content' => 'UNREDACTED-original-content'];
		};

		$result = $this->service->selectDisclosableDocuments($assessments, $loader);

		$this->assertCount(1, $result);
		$this->assertSame('doc-002-redacted', $result[0]['id']);
		$this->assertSame('REDACTED-content', $result[0]['content']);

		// Assert the unredacted original never appears anywhere in the result.
		foreach ($result as $document) {
			$this->assertStringNotContainsString('UNREDACTED-original-content', (string)$document['content']);
		}
	}//end testDeelsOpenbaarWithRedactionIncludesRedactedVersionOnly()

	/**
	 * Deels openbaar WITHOUT a finalized redaction is excluded entirely —
	 * never falls back to the original.
	 *
	 * @return void
	 */
	public function testDeelsOpenbaarWithoutRedactionIsExcluded(): void {
		$assessments = [
			['documentRef' => 'doc-003', 'classification' => 'deels_openbaar'],
		];

		$loaderCalled = false;
		$loader = function (string $ref) use (&$loaderCalled) {
			$loaderCalled = true;
			return ['id' => $ref, 'content' => 'should-never-be-loaded'];
		};

		$result = $this->service->selectDisclosableDocuments($assessments, $loader);

		$this->assertCount(0, $result);
		$this->assertFalse($loaderCalled, 'The document loader must never be called for an unredacted deels_openbaar document.');
	}//end testDeelsOpenbaarWithoutRedactionIsExcluded()

	/**
	 * Niet openbaar is always excluded, even if it carries a redactedDocumentRef.
	 *
	 * @return void
	 */
	public function testNietOpenbaarIsAlwaysExcluded(): void {
		$assessments = [
			[
				'documentRef' => 'doc-004',
				'classification' => 'niet_openbaar',
				'redactedDocumentRef' => 'doc-004-redacted',
			],
		];

		$result = $this->service->selectDisclosableDocuments($assessments, fn (string $ref) => ['id' => $ref]);

		$this->assertCount(0, $result);
	}//end testNietOpenbaarIsAlwaysExcluded()

	/**
	 * A mixed assessment set only discloses the openbaar + redacted-deels documents.
	 *
	 * @return void
	 */
	public function testMixedAssessmentSetDisclosesOnlySafeDocuments(): void {
		$assessments = [
			['documentRef' => 'doc-a', 'classification' => 'openbaar'],
			['documentRef' => 'doc-b', 'classification' => 'niet_openbaar'],
			['documentRef' => 'doc-c', 'classification' => 'deels_openbaar', 'redactedDocumentRef' => 'doc-c-redacted'],
			['documentRef' => 'doc-d', 'classification' => 'deels_openbaar'],
		];

		$loader = fn (string $ref) => ['id' => $ref];

		$result = $this->service->selectDisclosableDocuments($assessments, $loader);
		$ids = array_column($result, 'id');

		$this->assertCount(2, $result);
		$this->assertContains('doc-a', $ids);
		$this->assertContains('doc-c-redacted', $ids);
		$this->assertNotContains('doc-b', $ids);
		$this->assertNotContains('doc-d', $ids);
	}//end testMixedAssessmentSetDisclosesOnlySafeDocuments()

	// -- checkAvailability() — D5 --------------------------------------------

	/**
	 * OpenCatalogi not installed reports the correct reason.
	 *
	 * @return void
	 */
	public function testCheckAvailabilityReportsOpenCatalogiNotInstalled(): void {
		$this->appManager->method('isInstalled')->willReturn(false);

		$result = $this->service->checkAvailability();

		$this->assertFalse($result['available']);
		$this->assertSame('opencatalogi_not_installed', $result['reason']);
	}//end testCheckAvailabilityReportsOpenCatalogiNotInstalled()

	/**
	 * OpenRegister unavailable reports the correct reason.
	 *
	 * @return void
	 */
	public function testCheckAvailabilityReportsOpenRegisterUnavailable(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->settingsService->method('getObjectService')->willReturn(null);

		$result = $this->service->checkAvailability();

		$this->assertFalse($result['available']);
		$this->assertSame('openregister_unavailable', $result['reason']);
	}//end testCheckAvailabilityReportsOpenRegisterUnavailable()

	/**
	 * Everything present reports available.
	 *
	 * @return void
	 */
	public function testCheckAvailabilityReportsAvailable(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->settingsService->method('getObjectService')->willReturn($this->createMock(WooPublicationObjectServiceStub::class));

		$result = $this->service->checkAvailability();

		$this->assertTrue($result['available']);
	}//end testCheckAvailabilityReportsAvailable()

	// -- publish() / withdraw() — D6 single-save behaviour -------------------

	/**
	 * publish() with no disclosable documents reports no_publishable_documents
	 * and never calls the API client.
	 *
	 * @return void
	 */
	public function testPublishReportsNoPublishableDocuments(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$objectService = $this->createMock(WooPublicationObjectServiceStub::class);
		$objectService->method('find')->willReturnMap([
			['case-001', null, false, 'procest', 'case', ['id' => 'case-001', 'title' => 'Test case']],
			['decision-001', null, false, 'procest', 'decision', ['id' => 'decision-001', 'decisionType' => 'WOO-besluit']],
		]);
		$objectService->method('searchObjectsBySlug')->willReturn([
			['documentRef' => 'doc-001', 'classification' => 'niet_openbaar'],
		]);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'procest'],
			['case_schema', '', 'case'],
			['decision_schema', '', 'decision'],
			['woo_assessment_schema', '', 'wooAssessment'],
			['document_schema', '', 'document'],
		]);

		$this->apiClient->expects($this->never())->method('createPublication');

		$result = $this->service->publish('case-001', 'decision-001');

		$this->assertFalse($result['available']);
		$this->assertSame('no_publishable_documents', $result['reason']);
	}//end testPublishReportsNoPublishableDocuments()

	/**
	 * A successful publish creates the publication, attaches the disclosable
	 * document, and writes the decision back via exactly one saveObject() call.
	 *
	 * @return void
	 */
	public function testPublishSucceedsWithSingleSaveWriteBack(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$objectService = $this->createMock(WooPublicationObjectServiceStub::class);
		$objectService->method('find')->willReturnMap([
			['case-001', null, false, 'procest', 'case', ['id' => 'case-001', 'title' => 'Test case']],
			['decision-001', null, false, 'procest', 'decision', ['id' => 'decision-001', 'decisionType' => 'WOO-besluit', 'decisionDate' => '2026-07-13']],
			['doc-001', null, false, 'procest', 'document', ['id' => 'doc-001', 'title' => 'Besluit document', 'fileName' => 'besluit.pdf', 'format' => 'application/pdf', 'content' => base64_encode('public content')]],
		]);
		$objectService->method('searchObjectsBySlug')->willReturn([
			['documentRef' => 'doc-001', 'classification' => 'openbaar'],
		]);
		$objectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->callback(function (array $object) {
					return isset($object['wooPublication'])
						&& $object['wooPublication']['publicationId'] === 'pub-001'
						&& $object['wooPublication']['status'] === 'published';
				}),
				[],
				'procest',
				'decision',
				'decision-001'
			)
			->willReturn(['id' => 'decision-001']);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'procest'],
			['case_schema', '', 'case'],
			['decision_schema', '', 'decision'],
			['woo_assessment_schema', '', 'wooAssessment'],
			['document_schema', '', 'document'],
			['woo_publication_catalog_slug', 'publication', 'publication'],
		]);
		$this->settingsService->method('getWooPublicationConfigValue')->willReturnMap([
			['woo_publication_register', 'publication'],
			['woo_publication_schema', 'publication'],
			['woo_publication_document_schema', 'document'],
		]);

		$this->apiClient->method('createPublication')->willReturn(['id' => 'pub-001']);
		$this->apiClient->method('attachDocument')->willReturn(['id' => 'ocdoc-001']);
		$this->apiClient->expects($this->once())->method('attachFile');

		$result = $this->service->publish('case-001', 'decision-001');

		$this->assertTrue($result['available']);
		$this->assertSame('pub-001', $result['publicationId']);
	}//end testPublishSucceedsWithSingleSaveWriteBack()

	/**
	 * withdraw() without a prior publish returns no_publication and never calls the API client.
	 *
	 * @return void
	 */
	public function testWithdrawWithoutPriorPublishReturnsNoPublication(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$objectService = $this->createMock(WooPublicationObjectServiceStub::class);
		$objectService->method('find')->willReturn(['id' => 'decision-001']);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'procest'],
			['decision_schema', '', 'decision'],
		]);

		$this->apiClient->expects($this->never())->method('updatePublication');

		$result = $this->service->withdraw('decision-001');

		$this->assertFalse($result['available']);
		$this->assertSame('no_publication', $result['reason']);
	}//end testWithdrawWithoutPriorPublishReturnsNoPublication()

	/**
	 * withdraw() on a published decision sets depublicatiedatum and updates the
	 * decision status via exactly one saveObject() call.
	 *
	 * @return void
	 */
	public function testWithdrawSucceedsWithSingleSaveWriteBack(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$objectService = $this->createMock(WooPublicationObjectServiceStub::class);
		$objectService->method('find')->willReturn([
			'id' => 'decision-001',
			'wooPublication' => ['publicationId' => 'pub-001', 'status' => 'published'],
		]);
		$objectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->callback(function (array $object) {
					return $object['wooPublication']['status'] === 'withdrawn';
				}),
				[],
				'procest',
				'decision',
				'decision-001'
			)
			->willReturn(['id' => 'decision-001']);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'procest'],
			['decision_schema', '', 'decision'],
		]);
		$this->settingsService->method('getWooPublicationConfigValue')->willReturnMap([
			['woo_publication_register', 'publication'],
			['woo_publication_schema', 'publication'],
		]);

		$this->apiClient->expects($this->once())->method('updatePublication');

		$result = $this->service->withdraw('decision-001');

		$this->assertTrue($result['available']);
	}//end testWithdrawSucceedsWithSingleSaveWriteBack()
}//end class
