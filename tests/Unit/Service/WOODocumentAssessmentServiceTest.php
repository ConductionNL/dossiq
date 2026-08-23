<?php

/**
 * WOODocumentAssessmentService Unit Tests
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

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Object service stub for redaction-proposal tests — matches the
 * `searchObjectsBySlug()`/`saveObject()` shape `SearchesObjects` calls
 * (woo-llm-anonymisation).
 */
interface RedactionProposalObjectServiceStub {
	/**
	 * Search objects by register/schema slug.
	 *
	 * @param string $registerSlug The register slug.
	 * @param string $schemaSlug The schema slug.
	 * @param array $filters Query filters.
	 *
	 * @return array
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array;

	/**
	 * Save an object (OpenRegister object-first signature).
	 *
	 * @param array $object Object data.
	 * @param array $extend Extend parameters.
	 * @param string|null $register Register id/slug.
	 * @param string|null $schema Schema id/slug.
	 * @param string|null $uuid Optional object uuid.
	 *
	 * @return array
	 */
	public function saveObject(array $object, array $extend = [], ?string $register = null, ?string $schema = null, ?string $uuid = null): array;
}//end interface

/**
 * Unit tests for WOODocumentAssessmentService.
 *
 * @covers \OCA\Dossiq\Service\WOODocumentAssessmentService
 */
class WOODocumentAssessmentServiceTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var WOODocumentAssessmentService
	 */
	private WOODocumentAssessmentService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new WOODocumentAssessmentService(
			$this->settingsService,
			$this->userSession,
			$this->logger,
		);
	}//end setUp()

	/**
	 * Validate returns no errors for a valid 'openbaar' assessment.
	 *
	 * @return void
	 */
	public function testValidatePassesForOpenbarWithoutGrounds(): void {
		$errors = $this->service->validate([
			'documentRef' => 'doc-uuid-001',
			'classification' => 'openbaar',
		]);

		$this->assertEmpty($errors);
	}//end testValidatePassesForOpenbarWithoutGrounds()

	/**
	 * Validate returns error when classification is 'deels_openbaar' and no weigeringsgrond.
	 *
	 * Acceptance criterion: classification 'deels openbaar' without weigeringsgrond → error.
	 *
	 * @return void
	 */
	public function testValidateRequiresWeigeringsgrondForDeelsOpenbaar(): void {
		$errors = $this->service->validate([
			'documentRef' => 'doc-uuid-001',
			'classification' => 'deels_openbaar',
		]);

		$this->assertArrayHasKey('weigeringsgronden', $errors);
		$this->assertStringContainsString('weigeringsgrond', $errors['weigeringsgronden']);
	}//end testValidateRequiresWeigeringsgrondForDeelsOpenbaar()

	/**
	 * Validate returns error when classification is 'niet_openbaar' and no weigeringsgrond.
	 *
	 * @return void
	 */
	public function testValidateRequiresWeigeringsgrondForNietOpenbaar(): void {
		$errors = $this->service->validate([
			'documentRef' => 'doc-uuid-001',
			'classification' => 'niet_openbaar',
		]);

		$this->assertArrayHasKey('weigeringsgronden', $errors);
	}//end testValidateRequiresWeigeringsgrondForNietOpenbaar()

	/**
	 * Validate passes for 'niet_openbaar' with a valid weigeringsgrond.
	 *
	 * @return void
	 */
	public function testValidatePassesForNietOpenbaarWithGrond(): void {
		$errors = $this->service->validate([
			'documentRef' => 'doc-uuid-001',
			'classification' => 'niet_openbaar',
			'weigeringsgronden' => ['5.1.5'],
		]);

		$this->assertEmpty($errors);
	}//end testValidatePassesForNietOpenbaarWithGrond()

	/**
	 * Validate returns error for invalid classification value.
	 *
	 * @return void
	 */
	public function testValidateRejectsInvalidClassification(): void {
		$errors = $this->service->validate([
			'documentRef' => 'doc-uuid-001',
			'classification' => 'geheim',
		]);

		$this->assertArrayHasKey('classification', $errors);
	}//end testValidateRejectsInvalidClassification()

	/**
	 * Validate returns error when documentRef is missing.
	 *
	 * @return void
	 */
	public function testValidateRequiresDocumentRef(): void {
		$errors = $this->service->validate([
			'classification' => 'openbaar',
		]);

		$this->assertArrayHasKey('documentRef', $errors);
	}//end testValidateRequiresDocumentRef()

	/**
	 * BulkUpsert throws RuntimeException when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testBulkUpsertThrowsWhenORUnavailable(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/OpenRegister/i');

		$this->service->bulkUpsert('case-uuid-001', [
			['documentRef' => 'doc-001', 'classification' => 'openbaar'],
		]);
	}//end testBulkUpsertThrowsWhenORUnavailable()

	/**
	 * GetOutstanding returns count=0 when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testGetOutstandingReturnsZeroWhenORUnavailable(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$result = $this->service->getOutstanding('case-uuid-001');

		$this->assertSame(0, $result['count']);
		$this->assertEmpty($result['documents']);
	}//end testGetOutstandingReturnsZeroWhenORUnavailable()

	/**
	 * AllDocumentsAssessed returns true when outstanding count is zero.
	 *
	 * @return void
	 */
	public function testAllDocumentsAssessedReturnsTrueWhenNoneOutstanding(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$result = $this->service->allDocumentsAssessed('case-uuid-001');

		$this->assertTrue($result);
	}//end testAllDocumentsAssessedReturnsTrueWhenNoneOutstanding()

	/**
	 * FindAssessment returns null when OpenRegister is unavailable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-1
	 */
	public function testFindAssessmentReturnsNullWhenORUnavailable(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$this->assertNull($this->service->findAssessment('case-uuid-001', 'doc-001'));
	}//end testFindAssessmentReturnsNullWhenORUnavailable()

	/**
	 * FindAssessment returns the matching record when one exists.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-1
	 */
	public function testFindAssessmentReturnsExistingRecord(): void {
		$objectServiceMock = $this->createMock(RedactionProposalObjectServiceStub::class);
		$objectServiceMock->method('searchObjectsBySlug')->willReturn([
			['id' => 'assessment-001', 'documentRef' => 'doc-001', 'classification' => 'deels_openbaar'],
		]);

		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq-register'],
			['woo_assessment_schema', '', 'woo-assessment'],
		]);

		$result = $this->service->findAssessment('case-uuid-001', 'doc-001');

		$this->assertSame('assessment-001', $result['id']);
		$this->assertSame('deels_openbaar', $result['classification']);
	}//end testFindAssessmentReturnsExistingRecord()

	/**
	 * SaveRedactionProposal throws when OpenRegister is unavailable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-1
	 */
	public function testSaveRedactionProposalThrowsWhenORUnavailable(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$this->expectException(\RuntimeException::class);

		$this->service->saveRedactionProposal('case-uuid-001', 'doc-001', ['status' => 'pending_review']);
	}//end testSaveRedactionProposalThrowsWhenORUnavailable()

	/**
	 * SaveRedactionProposal throws when the document has not been assessed
	 * yet — assess-first business rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-1
	 */
	public function testSaveRedactionProposalThrowsWhenNotYetAssessed(): void {
		$objectServiceMock = $this->createMock(RedactionProposalObjectServiceStub::class);
		$objectServiceMock->method('searchObjectsBySlug')->willReturn([]);

		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq-register'],
			['woo_assessment_schema', '', 'woo-assessment'],
		]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/must be assessed/i');

		$this->service->saveRedactionProposal('case-uuid-001', 'doc-001', ['status' => 'pending_review']);
	}//end testSaveRedactionProposalThrowsWhenNotYetAssessed()

	/**
	 * SaveRedactionProposal attaches the proposal to the existing record and
	 * never touches `classification`/`weigeringsgronden`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-1
	 */
	public function testSaveRedactionProposalAttachesProposalToExistingRecord(): void {
		$existing = [
			'id' => 'assessment-001',
			'documentRef' => 'doc-001',
			'caseRef' => 'case-uuid-001',
			'classification' => 'deels_openbaar',
		];

		$objectServiceMock = $this->createMock(RedactionProposalObjectServiceStub::class);
		$objectServiceMock->method('searchObjectsBySlug')->willReturn([$existing]);

		$capturedObject = null;
		$objectServiceMock->method('saveObject')->willReturnCallback(
			function (array $object) use (&$capturedObject) {
				$capturedObject = $object;
				return $object;
			}
		);

		$this->settingsService->method('getObjectService')->willReturn($objectServiceMock);
		$this->settingsService->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq-register'],
			['woo_assessment_schema', '', 'woo-assessment'],
		]);

		$proposal = ['status' => 'pending_review', 'spans' => [], 'source' => 'rules_only'];

		$result = $this->service->saveRedactionProposal('case-uuid-001', 'doc-001', $proposal);

		$this->assertSame($proposal, $capturedObject['redactionProposal']);
		$this->assertSame('deels_openbaar', $capturedObject['classification']);
		$this->assertSame($proposal, $result['redactionProposal']);
	}//end testSaveRedactionProposalAttachesProposalToExistingRecord()
}//end class
