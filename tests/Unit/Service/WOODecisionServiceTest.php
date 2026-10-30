<?php

/**
 * WOODecisionService Unit Tests
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Drafts\CaseDocumentDrafts;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODecisionService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Review\PagesSeen;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Object service stub for WOO decision tests (OpenRegister object-first signature).
 */
interface WOODecisionObjectServiceStub {
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
	 * Slug-aware search bridge (real ObjectService::searchObjectsBySlug()).
	 *
	 * @param string $registerSlug Register slug.
	 * @param string $schemaSlug Schema slug.
	 * @param array<string,mixed> $filters Query filters.
	 *
	 * @return array<int,mixed>|int
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array|int;

	/**
	 * Search objects (real ObjectService::searchObjects()).
	 *
	 * @param array<string,mixed> $query Query with @self block and field filters.
	 *
	 * @return array<int,mixed>|int
	 */
	public function searchObjects(array $query = []): array|int;
}//end interface

/**
 * Unit tests for WOODecisionService.
 *
 * @covers \OCA\Dossiq\Service\WOODecisionService
 * @uses \OCA\Dossiq\Service\DecisionDocument\DecisionRecordComposer
 * @uses \OCA\Dossiq\Service\DecisionDocument\PublicationReadiness
 * @uses \OCA\Dossiq\Service\DecisionDocument\CaseDecisionLink
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class WOODecisionServiceTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var WOODocumentAssessmentService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private WOODocumentAssessmentService $assessmentService;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var WOODecisionService
	 */
	private WOODecisionService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->assessmentService = $this->createMock(WOODocumentAssessmentService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new WOODecisionService(
			$this->settingsService,
			$this->assessmentService,
			$this->userSession,
			$this->logger,
		);
	}//end setUp()

	/**
	 * AssembleDecision throws when there are outstanding (unassessed) documents.
	 *
	 * Acceptance criterion: unassessed document → blocked with explicit error.
	 *
	 * @return void
	 */
	public function testAssembleDecisionThrowsWhenDocumentsOutstanding(): void {
		$this->assessmentService
			->method('getOutstanding')
			->willReturn([
				'count' => 3,
				'documents' => ['doc-001', 'doc-002', 'doc-003'],
			]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/3 document/i');

		$this->service->assembleDecision('case-uuid-001');
	}//end testAssembleDecisionThrowsWhenDocumentsOutstanding()

	/**
	 * The decision waits for the last page: page 7 of one in-scope document unseen answers 409 and writes nothing.
	 *
	 * @return void
	 */
	public function testTheDecisionWaitsForTheLastPage(): void {
		$this->assessmentService->method('getOutstanding')->willReturn(['count' => 0, 'documents' => []]);
		$pagesSeen = $this->createMock(PagesSeen::class);
		$pagesSeen->method('unseenInCase')->with('case-001')->willReturn(['doc-7' => [7]]);
		$pagesSeen->method('assertAllSeen')->with('case-001')->willThrowException(
			new RefusedException(rule: 'review-pages-unseen', sentence: 'The decision waits until every required page has been seen.', status: 409)
		);
		$this->settingsService->expects($this->never())->method('getObjectService');
		$service = new WOODecisionService($this->settingsService, $this->assessmentService, $this->userSession, $this->logger, pagesSeen: $pagesSeen);

		$this->assertSame(['doc-7' => [7]], $service->unseenPages(caseId: 'case-001'));
		$this->assertSame([], $this->service->unseenPages(caseId: 'case-001'));
		try {
			$service->assembleDecision(caseId: 'case-001');
			$this->fail('A decision was assembled with a required page unseen.');
		} catch (RefusedException $e) {
			$this->assertSame('review-pages-unseen', $e->getRule());
			$this->assertSame(409, $e->getStatus());
		}
	}//end testTheDecisionWaitsForTheLastPage()

	/**
	 * AssembleDecision throws RuntimeException when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testAssembleDecisionThrowsWhenORUnavailable(): void {
		$this->assessmentService
			->method('getOutstanding')
			->willReturn(['count' => 0, 'documents' => []]);

		$this->settingsService->method('getObjectService')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/OpenRegister/i');

		$this->service->assembleDecision('case-uuid-001');
	}//end testAssembleDecisionThrowsWhenORUnavailable()

	/**
	 * AssembleDecision succeeds when all documents are assessed.
	 *
	 * Acceptance criterion: every document assessed → decision object created.
	 *
	 * @return void
	 */
	public function testAssembleDecisionSucceedsWhenAllAssessed(): void {
		$this->assessmentService
			->method('getOutstanding')
			->willReturn(['count' => 0, 'documents' => []]);

		$decisionMock = new class {
			public function getUuid(): string {
				return 'decision-uuid-001';
			}
		};

		$objectServiceMock = $this->createMock(WOODecisionObjectServiceStub::class);
		$objectServiceMock->method('searchObjectsBySlug')->willReturn([
			['documentRef' => 'doc-001', 'classification' => 'openbaar', 'weigeringsgronden' => []],
			['documentRef' => 'doc-002', 'classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.5']],
		]);
		$objectServiceMock->method('saveObject')->willReturn($decisionMock);

		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
			['decision_schema', '', 'decision'],
			['woo_assessment_schema', '', 'wooAssessment'],
			['case_schema', '', ''],
		]);

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);

		$result = $this->service->assembleDecision('case-uuid-001');

		$this->assertSame('decision-uuid-001', $result['decisionId']);
		$this->assertSame('case-uuid-001', $result['caseId']);
		$this->assertSame(2, $result['assessmentCount']);
		$this->assertSame(1, $result['summary']['openbaar']);
		$this->assertSame(1, $result['summary']['niet_openbaar']);
		$this->assertContains('5.1.5', $result['weigeringsgronden']);
	}//end testAssembleDecisionSucceedsWhenAllAssessed()

	/**
	 * The assembled decision answers what the draft of its document did (REQ-CDD-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-the-draft-is-asked-of-filinq-and-filed-on-the-case-req-cdd-003
	 */
	public function testTheDecisionCarriesTheDraftStatus(): void {
		$this->assessmentService->method('getOutstanding')->willReturn(['count' => 0, 'documents' => []]);
		$objectServiceMock = $this->createMock(WOODecisionObjectServiceStub::class);
		$objectServiceMock->method('searchObjectsBySlug')->willReturn([]);
		$objectServiceMock->method('saveObject')->willReturn(
			new class {
				public function getUuid(): string {
					return 'decision-uuid-002';
				}
			}
		);
		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
			['decision_schema', '', 'decision'],
			['woo_assessment_schema', '', 'wooAssessment'],
			['case_schema', '', ''],
		]);
		$drafted = ['status' => 'failed', 'reason' => 'template not found: woo-besluit', 'informatieobject' => '', 'fileId' => 0];
		$drafts = $this->createMock(CaseDocumentDrafts::class);
		$drafts->expects($this->exactly(2))->method('draft')->willReturnCallback(
			static fn (string $caseId, string $purpose): array => array_merge($drafted, ['reason' => $caseId . '/' . $purpose])
		);
		$service = new WOODecisionService($this->settingsService, $this->assessmentService, $this->userSession, $this->logger, $drafts);

		$result = $service->assembleDecision('case-uuid-002');

		$this->assertSame('decision-uuid-002', $result['decisionId'], 'a draft that failed does not undo the decision');
		$this->assertSame('case-uuid-002/' . WOODecisionService::DRAFT_PURPOSE, $result['draft']['reason']);
		$this->assertSame('case-uuid-002/' . WOODecisionService::INVENTORY_PURPOSE, $result['inventoryDraft']['reason']);
		$this->assertSame('failed', $result['draft']['status']);
	}//end testTheDecisionCarriesTheDraftStatus()

	/**
	 * An assembled decision makes the case ready to publish, and never unpublishes it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
	 */
	public function testAnAssembledDecisionMakesTheCaseReady(): void {
		$store = $this->assembleForCases(
			cases: [
				'case-a' => ['title' => 'A'],
				'case-b' => ['title' => 'B', 'wooPublicationStatus' => 'published'],
			],
		);

		$this->assertSame('ready', $store->row(schema: 'case', uuid: 'case-a')['wooPublicationStatus']);
		$this->assertSame('A', $store->row(schema: 'case', uuid: 'case-a')['title']);
		$this->assertSame('published', $store->row(schema: 'case', uuid: 'case-b')['wooPublicationStatus']);
	}//end testAnAssembledDecisionMakesTheCaseReady()

	/**
	 * The decision's summary names the request by the case's title, never by its uuid.
	 *
	 * The publication shows it under its title on the public site and in
	 * search results.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function testTheDecisionSummaryNamesTheRequestByItsTitle(): void {
		$this->assertSame(
			expected: 'Besluit op het Woo-verzoek "Raadsstukken over de nieuwe brug"',
			actual: WOODecisionService::publicationSummary(caseTitle: 'Raadsstukken over de nieuwe brug'),
		);
	}//end testTheDecisionSummaryNamesTheRequestByItsTitle()

	/**
	 * A case without a title still gives a readable summary.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function testTheDecisionSummaryWithoutATitleStaysReadable(): void {
		$this->assertSame(expected: 'Besluit op een Woo-verzoek', actual: WOODecisionService::publicationSummary(caseTitle: ''));
		$this->assertSame(expected: 'Besluit op een Woo-verzoek', actual: WOODecisionService::publicationSummary(caseTitle: '   '));
	}//end testTheDecisionSummaryWithoutATitleStaysReadable()

	/**
	 * The assembled decision is written with the summary built from the case, and no uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function testTheAssembledDecisionCarriesTheReadableSummary(): void {
		$store = $this->assembleForCases(
			cases: [
				'case-with-title' => ['title' => 'Raadsstukken over de nieuwe brug'],
				'case-without-title' => ['title' => ''],
			],
		);

		$byCase = [];
		foreach ($store->all(schema: 'decision') as $decision) {
			$byCase[$decision['case']] = $decision['description'];
		}

		$this->assertSame(expected: 'Besluit op het Woo-verzoek "Raadsstukken over de nieuwe brug"', actual: $byCase['case-with-title']);
		$this->assertSame(expected: 'Besluit op een Woo-verzoek', actual: $byCase['case-without-title']);
		foreach ($byCase as $caseId => $description) {
			$this->assertStringNotContainsString(needle: (string)$caseId, haystack: $description);
		}
	}//end testTheAssembledDecisionCarriesTheReadableSummary()

	/**
	 * The recorded decision is listed on its case, which is what the case
	 * page's "Record the decision" checklist item reads. A decision listed
	 * there before is kept.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-recorded-decision-is-listed-on-its-case-req-cm-81
	 */
	public function testTheRecordedDecisionIsListedOnItsCase(): void {
		$store = $this->assembleForCases(
			cases: [
				'case-new' => ['title' => 'Brug'],
				'case-earlier' => ['title' => 'Plein', 'decisions' => ['decision-before']],
			],
		);

		$decisionOf = [];
		foreach ($store->all(schema: 'decision') as $decision) {
			$decisionOf[$decision['case']] = (string)$decision['id'];
		}

		$this->assertSame(
			expected: [$decisionOf['case-new']],
			actual: $store->find(id: 'case-new', register: 'dossiq', schema: 'case')['decisions'] ?? null,
		);
		$this->assertSame(
			expected: ['decision-before', $decisionOf['case-earlier']],
			actual: $store->find(id: 'case-earlier', register: 'dossiq', schema: 'case')['decisions'] ?? null,
		);
	}//end testTheRecordedDecisionIsListedOnItsCase()

	/**
	 * Assemble a decision for each seeded case against an in-memory register.
	 *
	 * @param array<string, array<string, mixed>> $cases The case rows by uuid.
	 *
	 * @return \OCA\Dossiq\Tests\Support\InMemoryRegister The register after assembly.
	 */
	private function assembleForCases(array $cases): \OCA\Dossiq\Tests\Support\InMemoryRegister {
		$this->assessmentService->method('getOutstanding')->willReturn(['count' => 0, 'documents' => []]);

		$store = new \OCA\Dossiq\Tests\Support\InMemoryRegister();
		foreach ($cases as $uuid => $row) {
			$store->seed(schema: 'case', uuid: $uuid, row: $row);
		}

		$objects = new class($store) {
			/**
			 * @param \OCA\Dossiq\Tests\Support\InMemoryRegister $store The rows.
			 */
			public function __construct(private readonly \OCA\Dossiq\Tests\Support\InMemoryRegister $store) {
			}

			/**
			 * @param int|string $id       The uuid.
			 * @param mixed      $_extend  Ignored.
			 * @param bool       $files    Ignored.
			 * @param int|string $register Ignored.
			 * @param int|string $schema   The schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(int|string $id, mixed $_extend = null, bool $files = false, int|string $register = '', int|string $schema = ''): ?array {
				return $this->store->find(id: $id, register: $register, schema: $schema);
			}

			/**
			 * @param string               $register Ignored.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  Filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				return $this->store->searchObjectsBySlug($register, $schema, $filters);
			}

			/**
			 * @param array<string, mixed> $object   The row.
			 * @param int|string           $register Ignored.
			 * @param int|string           $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return mixed
			 */
			public function saveObject(array $object, int|string $register = '', int|string $schema = '', ?string $uuid = null): mixed {
				$row = $this->store->saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid);
				if ($schema !== 'decision') {
					return $row;
				}

				return new class((string)$row['id']) {
					/**
					 * @param string $id The uuid.
					 */
					public function __construct(private readonly string $id) {
					}

					/**
					 * @return string
					 */
					public function getUuid(): string {
						return $this->id;
					}
				};
			}
		};

		$this->settingsService->method('getObjectService')->willReturn($objects);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
			['decision_schema', '', 'decision'],
			['woo_assessment_schema', '', 'wooAssessment'],
			['case_schema', '', 'case'],
		]);

		foreach (array_keys($cases) as $caseId) {
			$this->service->assembleDecision((string)$caseId);
		}

		return $store;
	}//end assembleForCases()

}//end class
