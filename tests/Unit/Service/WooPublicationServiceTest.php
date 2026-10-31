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
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Review\PagesSeen;
use OCA\Dossiq\Review\ReviewDepth;
use OCA\Dossiq\Service\Publication\OpenCatalogiApiClient;
use OCA\Dossiq\Service\WooPublication\WooCategoryMapper;
use OCA\Dossiq\Service\Publication\WithheldListRecorder;
use OCA\Dossiq\Service\Assessment\NothingToDisclose;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\WooPublicationService;
use OCA\Dossiq\Tests\Unit\Service\Publication\EnglishL10n;
use OCA\Dossiq\Tests\Unit\Service\Publication\FakeWithheldDocuments;
use OCA\Dossiq\Woo\WooCaseLedger;
use Psr\Container\ContainerInterface;
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
 * @uses \OCA\Dossiq\Woo\WooCaseLedger
 * @uses \OCA\Dossiq\Service\Publication\PublicationResultLink
 * @uses \OCA\Dossiq\Service\Publication\PublicationSources
 * @uses \OCA\Dossiq\Service\Publication\CatalogTransport
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Review\DocumentRelevance
 * @uses \OCA\Dossiq\Review\PagesSeen
 * @uses \OCA\Dossiq\Service\Publication\WithheldEntries
 * @uses \OCA\Dossiq\Service\Publication\WithheldListRecorder
 * @uses \OCA\Dossiq\Service\Assessment\DocumentSearchOutcome
 * @uses \OCA\Dossiq\Service\Assessment\NothingToDisclose
 */
class WooPublicationServiceTest extends TestCase {

	use EnglishL10n;

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
	 * Every save the withheld-list fixture saw, as [schema, object].
	 *
	 * @var list<array{0: ?string, 1: array<string, mixed>}>
	 */
	private array $saves = [];

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
	 * Publish waits for the last page: page 7 of one in-scope document unseen answers pages_unseen naming it, and nothing is sent.
	 *
	 * @return void
	 */
	public function testPublishWaitsForTheLastPage(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$store = new InMemoryRegister();
		$store->seed(schema: 'documentReview', uuid: 'r-1', row: ['case' => 'case-001', 'documentRef' => 'doc-7', 'relevance' => 'in-scope', 'pagesRequired' => [6, 7], 'pagesSeen' => [['page' => 6, 'by' => 'a']]]);
		$store->seed(schema: 'documentReview', uuid: 'r-2', row: ['case' => 'case-001', 'documentRef' => 'doc-8', 'relevance' => 'out-of-scope', 'pagesRequired' => [1]]);
		$config = ['register' => 'dossiq', 'document_review_schema' => 'documentReview', 'decision_schema' => 'decision'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);
		$service = new WooPublicationService(
			settingsService: $settings,
			apiClient: $this->apiClient,
			categoryMapper: new WooCategoryMapper(),
			appManager: $this->appManager,
			logger: $logger,
			pagesSeen: new PagesSeen(reviews: new DocumentRelevance(settingsService: $settings, logger: $logger), depth: new ReviewDepth()),
		);
		$this->apiClient->expects($this->never())->method('createPublication');

		$result = $service->publish('case-001', 'decision-001');

		$this->assertFalse($result['available']);
		$this->assertSame('pages_unseen', $result['reason']);
		$this->assertSame(['doc-7' => [7]], $result['unseen']);
	}//end testPublishWaitsForTheLastPage()

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
			['case-001', null, false, 'dossiq', 'case', ['id' => 'case-001', 'title' => 'Test case']],
			['decision-001', null, false, 'dossiq', 'decision', ['id' => 'decision-001', 'decisionType' => 'WOO-besluit']],
		]);
		$objectService->method('searchObjectsBySlug')->willReturn([
			['documentRef' => 'doc-001', 'classification' => 'niet_openbaar'],
		]);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
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
	 * Publish a case through a service that knows the case's assessments.
	 *
	 * @param array<string, mixed>             $case        The case row.
	 * @param array<int, array<string, mixed>> $assessments The assessments the register holds.
	 * @param array<int, string>               $underReview The documents under review.
	 * @param int                              $outstanding The documents still waiting.
	 *
	 * @return array{result: array<string, mixed>, saves: array<int, array<int, mixed>>, created: int, attached: int}
	 *
	 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/woo-publication-via-opencatalogi/spec.md
	 */
	private function publishRecording(array $case, array $assessments, array $underReview, int $outstanding): array {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$objectService = $this->createMock(WooPublicationObjectServiceStub::class);
		$objectService->method('find')->willReturnMap([
			['case-001', null, false, 'dossiq', 'case', ['id' => 'case-001', 'title' => 'Fietspad Lindelaan'] + $case],
			['decision-001', null, false, 'dossiq', 'decision', ['id' => 'decision-001', 'decisionType' => 'WOO-besluit', 'description' => 'Niet openbaar gemaakt']],
		]);
		$objectService->method('searchObjectsBySlug')->willReturn($assessments);
		$saves = [];
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, array $extend = [], ?string $register = null, ?string $schema = null, ?string $uuid = null) use (&$saves) {
				$saves[] = [$schema, $uuid, $object];
				return ['id' => $uuid];
			}
		);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
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

		$created = 0;
		$attached = 0;
		$this->apiClient->method('createPublication')->willReturnCallback(
			function () use (&$created): array {
				$created++;
				return ['id' => 'pub-001'];
			}
		);
		$this->apiClient->method('attachFile')->willReturnCallback(
			function () use (&$attached) {
				$attached++;
				return [];
			}
		);

		$documents = $this->createMock(WOODocumentAssessmentService::class);
		$documents->method('getDocumentsUnderReview')->willReturn($underReview);
		$documents->method('getOutstanding')->willReturn(['count' => $outstanding, 'documents' => []]);

		$service = new WooPublicationService(
			settingsService: $this->settingsService,
			apiClient: $this->apiClient,
			categoryMapper: new WooCategoryMapper(),
			appManager: $this->appManager,
			logger: $this->createMock(LoggerInterface::class),
			nothingToDisclose: new NothingToDisclose(assessments: $documents),
		);

		return [
			'result' => $service->publish('case-001', 'decision-001'),
			'saves' => $saves,
			'created' => $created,
			'attached' => $attached,
		];
	}//end publishRecording()

	/**
	 * D2: every document assessed and none public publishes the decision alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function testEverythingWithheldPublishesTheDecisionAlone(): void {
		$run = $this->publishRecording(
			case: [],
			assessments: [
				['documentRef' => 'doc-001', 'classification' => 'niet_openbaar'],
				['documentRef' => 'doc-002', 'classification' => 'niet_openbaar'],
			],
			underReview: ['doc-001', 'doc-002'],
			outstanding: 0,
		);

		$this->assertTrue($run['result']['available']);
		$this->assertSame(1, $run['created'], 'the decision itself goes to opencatalogi');
		$this->assertSame(0, $run['attached'], 'with no document attached');
		$case = array_values(array_filter($run['saves'], static fn (array $save): bool => $save[0] === 'case'));
		$this->assertSame('published', $case[0][2]['wooPublicationStatus']);
	}//end testEverythingWithheldPublishesTheDecisionAlone()

	/**
	 * D1 and D2: a recorded "nothing found" with its note publishes the decision alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function testARecordedNothingFoundPublishesTheDecisionAlone(): void {
		$run = $this->publishRecording(
			case: ['documentSearchOutcome' => 'nothing_found', 'documentSearchNote' => 'Gezocht in mail en zaaksysteem 2025-2026.'],
			assessments: [],
			underReview: [],
			outstanding: 0,
		);

		$this->assertTrue($run['result']['available']);
		$this->assertSame(1, $run['created']);
		$this->assertSame(0, $run['attached']);
	}//end testARecordedNothingFoundPublishesTheDecisionAlone()

	/**
	 * "Nothing found" without a note is not a record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function testNothingFoundWithoutANoteIsRefused(): void {
		$run = $this->publishRecording(case: ['documentSearchOutcome' => 'nothing_found'], assessments: [], underReview: [], outstanding: 0);

		$this->assertSame('no_publishable_documents', $run['result']['reason']);
		$this->assertSame(0, $run['created']);
	}//end testNothingFoundWithoutANoteIsRefused()

	/**
	 * A partly public document still waiting for its redaction keeps the refusal.
	 *
	 * The disclosable set is empty here too, and publishing the decision alone
	 * would leave out a document it promised.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function testAPartlyPublicDocumentWithoutItsRedactionIsRefused(): void {
		$run = $this->publishRecording(
			case: [],
			assessments: [
				['documentRef' => 'doc-001', 'classification' => 'niet_openbaar'],
				['documentRef' => 'doc-002', 'classification' => 'deels_openbaar'],
			],
			underReview: ['doc-001', 'doc-002'],
			outstanding: 0,
		);

		$this->assertSame('no_publishable_documents', $run['result']['reason']);
		$this->assertSame(0, $run['created']);
	}//end testAPartlyPublicDocumentWithoutItsRedactionIsRefused()

	/**
	 * A document still waiting for its assessment keeps the refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function testAnOutstandingAssessmentIsRefused(): void {
		$run = $this->publishRecording(
			case: [],
			assessments: [['documentRef' => 'doc-001', 'classification' => 'niet_openbaar']],
			underReview: ['doc-001', 'doc-002'],
			outstanding: 1,
		);

		$this->assertSame('no_publishable_documents', $run['result']['reason']);
	}//end testAnOutstandingAssessmentIsRefused()

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
			['case-001', null, false, 'dossiq', 'case', ['id' => 'case-001', 'title' => 'Test case']],
			['decision-001', null, false, 'dossiq', 'decision', ['id' => 'decision-001', 'decisionType' => 'WOO-besluit', 'decisionDate' => '2026-07-13']],
			['doc-001', null, false, 'dossiq', 'document', ['id' => 'doc-001', 'title' => 'Besluit document', 'fileName' => 'besluit.pdf', 'format' => 'application/pdf', 'content' => base64_encode('public content')]],
		]);
		$objectService->method('searchObjectsBySlug')->willReturn([
			['documentRef' => 'doc-001', 'classification' => 'openbaar'],
		]);
		// Two writes: the decision's write-back, then the case's publication
		// state (woo-publish-decision-from-the-case D-2) through the PATCH
		// seam's read-and-save fallback, since this double has no patchObject().
		$saves = [];
		$objectService->expects($this->exactly(2))
			->method('saveObject')
			->willReturnCallback(function (array $object, array $extend = [], ?string $register = null, ?string $schema = null, ?string $uuid = null) use (&$saves) {
				$saves[] = [$schema, $uuid, $object];
				return ['id' => $uuid];
			});

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
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
		$this->apiClient->expects($this->once())->method('attachFile');

		$result = $this->service->publish('case-001', 'decision-001');

		$this->assertTrue($result['available']);
		$this->assertSame('pub-001', $result['publicationId']);
		$this->assertSame(['decision', 'decision-001'], [$saves[0][0], $saves[0][1]]);
		$this->assertSame('pub-001', $saves[0][2]['wooPublication']['publicationId']);
		$this->assertSame('published', $saves[0][2]['wooPublication']['status']);
		$this->assertSame(['case', 'case-001'], [$saves[1][0], $saves[1][1]]);
		$this->assertSame('published', $saves[1][2]['wooPublicationStatus']);
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
			['register', '', 'dossiq'],
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
				'dossiq',
				'decision',
				'decision-001'
			)
			->willReturn(['id' => 'decision-001']);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
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
	// -- the withheld list (woo-decision-records-what-was-withheld) ----------

	/**
	 * A service on a decided case with seven assessed documents, numbers 3
	 * and 7 (documentRef order) withheld under 5.1.2.e.
	 *
	 * @param WithheldListRecorder|null $recorder       The recorder to wire.
	 * @param bool                      $installed      Whether opencatalogi is installed.
	 * @param array<int, mixed>         $publications   Collects the publication ids created.
	 *
	 * @return WooPublicationService
	 */
	private function decidedCase(?WithheldListRecorder $recorder, bool $installed = true): WooPublicationService {
		$this->appManager->method('isInstalled')->willReturn($installed);
		$this->appManager->method('isEnabledForUser')->willReturn($installed);

		$assessments = [];
		foreach ([7, 2, 3, 1, 5, 4, 6] as $n) {
			$classification = 'openbaar';
			$grounds = [];
			if ($n === 3 || $n === 7) {
				$classification = 'niet_openbaar';
				$grounds = ['5.1.2.e'];
			}

			$assessments[] = ['id' => 'a-' . $n, 'documentRef' => 'doc-' . $n, 'classification' => $classification, 'weigeringsgronden' => $grounds, 'title' => 'Titel ' . $n];
		}

		$objectService = $this->createMock(WooPublicationObjectServiceStub::class);
		$objectService->method('find')->willReturnCallback(
			static function (string $id): array {
				if ($id === 'case-001') {
					return ['id' => 'case-001', 'title' => 'Test case'];
				}

				if ($id === 'decision-001') {
					return ['id' => 'decision-001', 'decisionType' => 'WOO-besluit', 'decisionDate' => '2026-07-13', 'wooPublication' => ['publicationId' => 'pub-001']];
				}

				return ['id' => $id, 'title' => 'Document ' . $id, 'fileName' => $id . '.pdf', 'format' => 'application/pdf', 'content' => base64_encode('public content')];
			}
		);
		$objectService->method('searchObjectsBySlug')->willReturn($assessments);
		$this->saves = [];
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, array $extend = [], ?string $register = null, ?string $schema = null, ?string $uuid = null): array {
				$this->saves[] = [$schema, $object];
				return ['id' => $uuid];
			}
		);

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
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
		$this->apiClient->method('updatePublication')->willReturn(['id' => 'pub-001']);
		$this->apiClient->method('createPublication')->willReturn(['id' => 'pub-001']);

		return new WooPublicationService(
			$this->settingsService,
			$this->apiClient,
			new WooCategoryMapper(),
			$this->appManager,
			$this->createMock(LoggerInterface::class),
			null,
			new WooCaseLedger(settingsService: $this->settingsService, logger: $this->createMock(LoggerInterface::class), withheldRecorder: $recorder),
		);
	}//end decidedCase()

	/**
	 * A recorder whose door is the fake, answering or throwing.
	 *
	 * @param FakeWithheldDocuments $door        The fake door.
	 * @param string                $recordClass The class it looks for.
	 *
	 * @return WithheldListRecorder
	 */
	private function recorderOn(FakeWithheldDocuments $door, string $recordClass = FakeWithheldDocuments::class): WithheldListRecorder {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($door);

		return new WithheldListRecorder(container: $container, logger: $this->createMock(LoggerInterface::class), l10n: $this->english(), recordClass: $recordClass);
	}//end recorderOn()

	/**
	 * Publishing records the two withheld documents, once, by position and grounds only.
	 *
	 * @return void
	 */
	public function testPublishRecordsTheWithheldDocuments(): void {
		$door = new FakeWithheldDocuments();
		$door->answer = ['recorded' => 2, 'refused' => []];

		$result = $this->decidedCase($this->recorderOn($door))->publish('case-001', 'decision-001');

		$this->assertTrue($result['available']);
		$this->assertSame(
			[['pub-001', [['position' => 3, 'grounds' => ['5.1.2.e']], ['position' => 7, 'grounds' => ['5.1.2.e']]], 'dossiq']],
			$door->calls
		);
		$this->assertSame(['status' => 'recorded', 'recorded' => 2, 'refused' => []], $result['withheldRecord']);
	}//end testPublishRecordsTheWithheldDocuments()

	/**
	 * A refused entry is answered, and the publication stands.
	 *
	 * @return void
	 */
	public function testARefusedEntryIsReportedAndThePublicationStands(): void {
		$door = new FakeWithheldDocuments();
		$door->answer = ['recorded' => 1, 'refused' => [['position' => 7, 'code' => '5.1.2.e', 'reason' => 'unknown-ground']]];

		$result = $this->decidedCase($this->recorderOn($door))->publish('case-001', 'decision-001');

		$this->assertTrue($result['available']);
		$this->assertSame('pub-001', $result['publicationId']);
		$this->assertSame([['position' => 7, 'code' => '5.1.2.e', 'reason' => 'unknown-ground']], $result['withheldRecord']['refused']);
		$caseWrite = array_values(array_filter($this->saves, static fn (array $save): bool => $save[0] === 'case'));
		$this->assertStringContainsString('number 7 (5.1.2.e, unknown-ground)', (string)($caseWrite[0][1]['wooWithheldNote'] ?? ''));
	}//end testARefusedEntryIsReportedAndThePublicationStands()

	/**
	 * A throwing record leaves the publication and says it was not recorded.
	 *
	 * @return void
	 */
	public function testAThrowingRecordLeavesThePublication(): void {
		$door = new FakeWithheldDocuments();
		$door->answer = null;

		$result = $this->decidedCase($this->recorderOn($door))->publish('case-001', 'decision-001');

		$this->assertTrue($result['available']);
		$this->assertSame(['status' => 'not-recorded', 'reason' => 'record-failed'], $result['withheldRecord']);
	}//end testAThrowingRecordLeavesThePublication()

	/**
	 * An opencatalogi without the record door records nothing; the publication stands.
	 *
	 * @return void
	 */
	public function testAnOlderOpencatalogiRecordsNothing(): void {
		$door = new FakeWithheldDocuments();

		$result = $this->decidedCase($this->recorderOn($door, 'OCA\OpenCatalogi\Service\Woo\NoSuchDoor'))->publish('case-001', 'decision-001');

		$this->assertTrue($result['available']);
		$this->assertSame([], $door->calls);
		$this->assertSame(['status' => 'not-recorded', 'reason' => 'opencatalogi-too-old'], $result['withheldRecord']);
	}//end testAnOlderOpencatalogiRecordsNothing()

	/**
	 * Without opencatalogi the publish refuses as before and nothing is recorded.
	 *
	 * @return void
	 */
	public function testWithoutOpencatalogiRecordIsNeverCalled(): void {
		$recorder = $this->createMock(WithheldListRecorder::class);
		$recorder->expects($this->never())->method('record');

		$result = $this->decidedCase($recorder, installed: false)->publish('case-001', 'decision-001');

		$this->assertSame(['available' => false, 'reason' => 'opencatalogi_not_installed'], $result);
	}//end testWithoutOpencatalogiRecordIsNeverCalled()

	/**
	 * Withdrawing clears the publication's withheld list.
	 *
	 * @return void
	 */
	public function testWithdrawClearsTheWithheldList(): void {
		$recorder = $this->createMock(WithheldListRecorder::class);
		$recorder->expects($this->once())->method('record')->with('pub-001', [])->willReturn(['status' => 'recorded', 'recorded' => 0, 'refused' => []]);

		$result = $this->decidedCase($recorder)->withdraw('decision-001');

		$this->assertTrue($result['available']);
		$this->assertSame('recorded', $result['withheldRecord']['status']);
	}//end testWithdrawClearsTheWithheldList()
}//end class
