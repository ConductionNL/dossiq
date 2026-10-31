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

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RefusalGroundStore;
use OCA\Dossiq\Woo\WooRefusalGrounds;
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

	/**
	 * Write some fields of a stored object (OpenRegister's PATCH seam).
	 *
	 * @param string      $objectId The object uuid.
	 * @param array       $data     The fields to write.
	 * @param string|null $register Register id/slug.
	 * @param string|null $schema   Schema id/slug.
	 *
	 * @return array
	 */
	public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): array;
}//end interface

/**
 * Unit tests for WOODocumentAssessmentService.
 *
 * @covers \OCA\Dossiq\Service\WOODocumentAssessmentService
 *
 * @uses \OCA\Dossiq\Woo\WooRefusalGrounds
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Review\OutstandingDocuments
 * @uses \OCA\Dossiq\Review\DisclosureAssessmentValidator
 * @uses \OCA\Dossiq\Review\DocumentRelevance
 * @uses \OCA\Dossiq\Review\PagesSeen
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
	 * The seeded refusal grounds the service validates against.
	 *
	 * @var RefusalGroundStore
	 */
	private RefusalGroundStore $grounds;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->grounds = RefusalGroundStore::seeded();
		$groundSettings = $this->createMock(SettingsService::class);
		$groundSettings->method('getObjectService')->willReturn($this->grounds);

		$this->service = new WOODocumentAssessmentService(
			$this->settingsService,
			$this->userSession,
			$this->logger,
			null,
			new WooRefusalGrounds(settingsService: $groundSettings, logger: $this->logger),
		);
	}//end setUp()

	/**
	 * A settled ground that was retired cannot be cited on a new assessment.
	 *
	 * @return void
	 */
	public function testARetiredGroundIsRefused(): void {
		foreach ($this->grounds->rows as $index => $row) {
			if ($row['code'] === '5.1.2.e') {
				$this->grounds->rows[$index]['status'] = 'retired';
			}
		}

		$errors = $this->service->validate([
			'documentRef' => 'doc-uuid-001',
			'classification' => 'deels_openbaar',
			'weigeringsgronden' => ['5.1.2.e'],
		]);

		$this->assertStringContainsString('5.1.2.e', $errors['weigeringsgronden']);
		$this->assertStringContainsString('retired', $errors['weigeringsgronden']);
	}//end testARetiredGroundIsRefused()

	/**
	 * An old code that the settled list does not carry is refused by name.
	 *
	 * @return void
	 */
	public function testAnOldCodeThatNoLongerExistsIsRefused(): void {
		$errors = $this->service->validate([
			'documentRef' => 'doc-uuid-001',
			'classification' => 'niet_openbaar',
			'weigeringsgronden' => ['5.2.5'],
		]);

		$this->assertSame('Unknown weigeringsgrond: 5.2.5', $errors['weigeringsgronden']);
	}//end testAnOldCodeThatNoLongerExistsIsRefused()

	/**
	 * A group node of the tree is not a ground and cannot be cited.
	 *
	 * @return void
	 */
	public function testAGroupNodeCannotBeCited(): void {
		$errors = $this->service->validate([
			'documentRef' => 'doc-uuid-001',
			'classification' => 'niet_openbaar',
			'weigeringsgronden' => ['5.1.2'],
		]);

		$this->assertStringContainsString('cannot be cited', $errors['weigeringsgronden']);
	}//end testAGroupNodeCannotBeCited()

	/**
	 * An unreadable list refuses the assessment with 503, never accepts it.
	 *
	 * @return void
	 */
	public function testAnUnreadableListRefusesTheAssessment(): void {
		$this->grounds->fails = true;

		try {
			$this->service->validate([
				'documentRef' => 'doc-uuid-001',
				'classification' => 'niet_openbaar',
				'weigeringsgronden' => ['5.1.2.e'],
			]);
			$this->fail('an unreadable list accepted the assessment');
		} catch (RefusedException $e) {
			$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			$this->assertSame('woo-refusal-grounds-unavailable', $e->getRule());
		}
	}//end testAnUnreadableListRefusesTheAssessment()

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
			'weigeringsgronden' => ['5.1.2.e'],
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
	 * Without OpenRegister no document is under review.
	 *
	 * @return void
	 */
	public function testNoDocumentIsUnderReviewWhenORUnavailable(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$this->assertSame([], $this->service->getDocumentsUnderReview('case-uuid-001'));
	}//end testNoDocumentIsUnderReviewWhenORUnavailable()

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
		$objectServiceMock->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data) use (&$capturedObject, $existing) {
				$capturedObject = array_merge($existing, $data);
				return $capturedObject;
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
	/**
	 * A document set aside before review leaves the outstanding list (woo-request-corpus-collection REQ-WRC-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-every-exclusion-before-review-is-kept-with-its-reason-req-wrc-003
	 */
	public function testAnExcludedDocumentIsNotOutstanding(): void {
		$register = new InMemoryRegister();
		foreach (['doc-1', 'doc-2', 'doc-3'] as $doc) {
			$register->seed('document', $doc, ['case' => 'case-1']);
		}
		$register->seed('wooDocumentAssessment', 'a-1', ['caseRef' => 'case-1', 'documentRef' => 'doc-1']);
		$register->seed('wooExclusion', 'x-1', ['case' => 'case-1', 'documentRef' => 'doc-2', 'reason' => 'out-of-period']);
		$register->seed('wooExclusion', 'x-2', ['case' => 'case-other', 'documentRef' => 'doc-3', 'reason' => 'duplicate']);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'document_schema' => 'document',
				'woo_assessment_schema' => 'wooDocumentAssessment',
				'woo_exclusion_schema' => 'wooExclusion',
			][$key] ?? $default
		);
		$service = new WOODocumentAssessmentService($settings, $this->userSession, $this->logger, null, new WooRefusalGrounds(settingsService: $settings, logger: $this->logger));

		$this->assertSame(['count' => 1, 'documents' => ['doc-3']], $service->getOutstanding('case-1'));
	}//end testAnExcludedDocumentIsNotOutstanding()

	/**
	 * A service over an in-memory register with a doubled reviewer access, signed in as the user.
	 *
	 * @param InMemoryRegister   $register The register.
	 * @param CaseReviewerAccess $access   The reviewer access double.
	 * @param string             $userId   The caller.
	 *
	 * @return WOODocumentAssessmentService
	 */
	private function serviceWithAccess(InMemoryRegister $register, CaseReviewerAccess $access, string $userId): WOODocumentAssessmentService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'woo_assessment_schema' => 'wooDocumentAssessment',
			][$key] ?? $default
		);
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn($userId);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new WOODocumentAssessmentService($settings, $session, $this->logger, null, new WooRefusalGrounds(settingsService: $settings, logger: $this->logger), reviewerAccess: $access);
	}//end serviceWithAccess()

	/**
	 * REQ-CRA-003: a second reviewer cannot overwrite the first reviewer's assessment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-reviewer-changes-only-the-records-they-made-req-cra-003
	 */
	public function testASecondReviewerCannotOverwrite(): void {
		$register = new InMemoryRegister();
		$register->seed('wooDocumentAssessment', 'a-7', ['caseRef' => 'case-1', 'documentRef' => 'doc-7', 'classification' => 'niet_openbaar', 'assessedBy' => 'j.devries', '@self' => ['owner' => 'j.devries']]);
		$access = $this->createMock(CaseReviewerAccess::class);
		$access->method('refusalFor')->willReturnCallback(
			static fn (string $caseId, array $record, string $userId, string $reference): ?array => $userId === ($record['@self']['owner'] ?? '')
				? null
				: ['documentRef' => $reference, 'errors' => ['assessment' => 'owned-by-another-reviewer']]
		);
		$access->expects($this->once())->method('reconcileAfterCreate')->with('case-1', 0);

		$result = $this->serviceWithAccess(register: $register, access: $access, userId: 'p.bakker')
			->bulkUpsert('case-1', [['documentRef' => 'doc-7', 'classification' => 'openbaar']]);

		$this->assertSame([['documentRef' => 'doc-7', 'errors' => ['assessment' => 'owned-by-another-reviewer']]], $result['errors'], 'the refusal names neither the author nor the verdict');
		$this->assertSame('niet_openbaar', $register->row('wooDocumentAssessment', 'a-7')['classification']);
		$this->assertCount(1, $register->all('wooDocumentAssessment'), 'no second assessment for the same document');
	}//end testASecondReviewerCannotOverwrite()

	/**
	 * REQ-CRA-002: a created assessment is reconciled to the people on the case at once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
	 */
	public function testACreatedAssessmentReconcilesTheCase(): void {
		$register = new InMemoryRegister();
		$access = $this->createMock(CaseReviewerAccess::class);
		$access->expects($this->once())->method('reconcileAfterCreate')->with('case-1', 1)->willReturn(['status' => 'reconciled', 'granted' => 1, 'revoked' => 0, 'refused' => 0]);

		$result = $this->serviceWithAccess(register: $register, access: $access, userId: 'p.bakker')
			->bulkUpsert('case-1', [['documentRef' => 'doc-8', 'classification' => 'openbaar']]);

		$this->assertSame([], $result['errors']);
		$this->assertCount(1, $register->all('wooDocumentAssessment'));
	}//end testACreatedAssessmentReconcilesTheCase()

	/**
	 * REQ-CRA-003 on the redaction half: another reviewer's assessment keeps its proposal.
	 *
	 * Proposing and reviewing both write through saveRedactionProposal(), so
	 * the rule there covers both.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-reviewer-changes-only-the-records-they-made-req-cra-003
	 */
	public function testAnotherReviewersProposalCannotBeChanged(): void {
		$register = new InMemoryRegister();
		$register->seed('wooDocumentAssessment', 'a-7', ['caseRef' => 'case-1', 'documentRef' => 'doc-7', 'redactionProposal' => ['status' => 'pending_review', 'spans' => []], '@self' => ['owner' => 'j.devries']]);
		$access = $this->createMock(CaseReviewerAccess::class);
		$access->method('assertMayChange')->willReturnCallback(
			static function (string $caseId, array $record, string $userId): void {
				if ($userId !== ($record['@self']['owner'] ?? '')) {
					throw new RefusedException(rule: 'owned-by-another-reviewer', sentence: 'Another reviewer made this record.', status: RefusedException::STATUS_FORBIDDEN);
				}
			}
		);

		try {
			$this->serviceWithAccess(register: $register, access: $access, userId: 'p.bakker')
				->saveRedactionProposal('case-1', 'doc-7', ['status' => 'approved', 'spans' => []]);
			$this->fail('Another reviewer must not change the proposal.');
		} catch (RefusedException $refusal) {
			$this->assertSame('owned-by-another-reviewer', $refusal->getRule());
			$this->assertSame(RefusedException::STATUS_FORBIDDEN, $refusal->getStatus());
		}

		$this->assertSame('pending_review', $register->row('wooDocumentAssessment', 'a-7')['redactionProposal']['status']);
		$this->assertSame(0, $register->writes);

		$saved = $this->serviceWithAccess(register: $register, access: $access, userId: 'j.devries')
			->saveRedactionProposal('case-1', 'doc-7', ['status' => 'approved', 'spans' => []]);
		$this->assertSame('approved', $saved['redactionProposal']['status'], 'the author may');
	}//end testAnotherReviewersProposalCannotBeChanged()

	/**
	 * A document whose assessment the caller may not read is assessed, not outstanding (REQ-WSA-004).
	 *
	 * The register hides another reviewer's assessment from a scoped read, as
	 * OpenRegister's private scope does; the count must still see it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/woo-request-scoped-access/spec.md
	 */
	public function testAnUnreadableAssessmentStillCountsAsAssessed(): void {
		$register = new class extends InMemoryRegister {
			public function searchObjectsBySlug(
				string $register,
				string $schema,
				array $filters = [],
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				if ($_rbac === true && $schema === 'wooDocumentAssessment') {
					return [];
				}

				return parent::searchObjectsBySlug($register, $schema, $filters, $_rbac, $_multitenancy);
			}
		};
		foreach (['doc-1', 'doc-2'] as $doc) {
			$register->seed('document', $doc, ['case' => 'case-1']);
		}
		$register->seed('wooDocumentAssessment', 'a-1', ['caseRef' => 'case-1', 'documentRef' => 'doc-1', '@self' => ['owner' => 'j.devries']]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'document_schema' => 'document',
				'woo_assessment_schema' => 'wooDocumentAssessment',
			][$key] ?? $default
		);
		$service = new WOODocumentAssessmentService($settings, $this->userSession, $this->logger, null, new WooRefusalGrounds(settingsService: $settings, logger: $this->logger));

		$this->assertSame(['count' => 1, 'documents' => ['doc-2']], $service->getOutstanding('case-1'));
	}//end testAnUnreadableAssessmentStillCountsAsAssessed()

	/**
	 * The scenario case: 10 documents, 6 in scope, 3 out, 1 unmarked, and 4 of the in-scope ones assessed.
	 *
	 * @return WOODocumentAssessmentService The service over that case, with relevance read.
	 */
	private function relevanceCase(): WOODocumentAssessmentService {
		$store = new \OCA\Dossiq\Tests\Support\InMemoryRegister();
		$marks = ['in-scope', 'in-scope', 'in-scope', 'in-scope', 'in-scope', 'in-scope', 'out-of-scope', 'out-of-scope', 'out-of-scope', 'unmarked'];
		foreach ($marks as $n => $relevance) {
			$store->seed(schema: 'document', uuid: 'doc-'.$n, row: ['case' => 'case-x', 'title' => 'Document '.$n]);
			// doc-9 has no review at all yet, which reads as unmarked too.
			if ($n < 9) {
				$store->seed(schema: 'documentReview', uuid: 'review-'.$n, row: ['case' => 'case-x', 'documentRef' => 'doc-'.$n, 'relevance' => $relevance]);
			}

			if ($n < 4) {
				$store->seed(schema: 'wooDocumentAssessment', uuid: 'a-'.$n, row: ['caseRef' => 'case-x', 'documentRef' => 'doc-'.$n, 'classification' => 'openbaar']);
			}
		}

		// An out-of-scope document that also carries a verdict changes nothing.
		$store->seed(schema: 'wooDocumentAssessment', uuid: 'a-7', row: ['caseRef' => 'case-x', 'documentRef' => 'doc-7', 'classification' => 'openbaar']);
		$config = ['register' => 'dossiq', 'document_schema' => 'document', 'woo_assessment_schema' => 'wooDocumentAssessment', 'document_review_schema' => 'documentReview'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));

		return new WOODocumentAssessmentService(
			$settings,
			$this->userSession,
			$this->logger,
			null,
			null,
			reviews: new \OCA\Dossiq\Review\DocumentRelevance(settingsService: $settings, logger: $this->logger),
		);
	}//end relevanceCase()

	/**
	 * An out-of-scope document needs no verdict: it is never outstanding.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function testAnOutOfScopeDocumentNeedsNoVerdict(): void {
		$outstanding = $this->relevanceCase()->getOutstanding(caseId: 'case-x');

		foreach (['doc-6', 'doc-7', 'doc-8'] as $outOfScope) {
			$this->assertNotContains($outOfScope, $outstanding['documents']);
		}
	}//end testAnOutOfScopeDocumentNeedsNoVerdict()

	/**
	 * The outstanding list holds the two unassessed in-scope documents and the unmarked one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function testUnmarkedDocumentsAreOutstanding(): void {
		$outstanding = $this->relevanceCase()->getOutstanding(caseId: 'case-x');

		$this->assertSame(3, $outstanding['count']);
		$this->assertEqualsCanonicalizing(['doc-4', 'doc-5', 'doc-9'], $outstanding['documents']);
	}//end testUnmarkedDocumentsAreOutstanding()

	/**
	 * The decision waits while a document is unmarked, even when every in-scope one has a verdict.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	public function testTheDecisionWaitsForUnmarkedDocuments(): void {
		$store = new \OCA\Dossiq\Tests\Support\InMemoryRegister();
		$store->seed(schema: 'document', uuid: 'doc-1', row: ['case' => 'case-x']);
		$store->seed(schema: 'document', uuid: 'doc-2', row: ['case' => 'case-x']);
		$store->seed(schema: 'documentReview', uuid: 'r-1', row: ['case' => 'case-x', 'documentRef' => 'doc-1', 'relevance' => 'in-scope']);
		$store->seed(schema: 'documentReview', uuid: 'r-2', row: ['case' => 'case-x', 'documentRef' => 'doc-2', 'relevance' => 'unmarked']);
		$store->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: ['caseRef' => 'case-x', 'documentRef' => 'doc-1', 'classification' => 'openbaar']);
		$store->seed(schema: 'wooDocumentAssessment', uuid: 'a-2', row: ['caseRef' => 'case-x', 'documentRef' => 'doc-2', 'classification' => 'openbaar']);
		$config = ['register' => 'dossiq', 'document_schema' => 'document', 'woo_assessment_schema' => 'wooDocumentAssessment', 'document_review_schema' => 'documentReview'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$service = new WOODocumentAssessmentService($settings, $this->userSession, $this->logger, null, null, reviews: new \OCA\Dossiq\Review\DocumentRelevance(settingsService: $settings, logger: $this->logger));

		$this->assertFalse($service->allDocumentsAssessed(caseId: 'case-x'));

		$store->rows['documentReview']['r-2']['relevance'] = 'out-of-scope';
		$this->assertTrue($service->allDocumentsAssessed(caseId: 'case-x'));
	}//end testTheDecisionWaitsForUnmarkedDocuments()

	/**
	 * A case with document review on, two documents nobody marked, and a signed-in assessor.
	 *
	 * @param InMemoryRegister $store The register the case lives in.
	 *
	 * @return WOODocumentAssessmentService
	 */
	private function reviewedCaseAssessedBy(InMemoryRegister $store): WOODocumentAssessmentService {
		$store->seed(schema: 'document', uuid: 'doc-1', row: ['case' => 'case-x']);
		$store->seed(schema: 'document', uuid: 'doc-2', row: ['case' => 'case-x']);
		$config = ['register' => 'dossiq', 'document_schema' => 'document', 'woo_assessment_schema' => 'wooDocumentAssessment', 'document_review_schema' => 'documentReview'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('thomas');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new WOODocumentAssessmentService($settings, $session, $this->logger, null, new WooRefusalGrounds(settingsService: $settings, logger: $this->logger), reviews: new \OCA\Dossiq\Review\DocumentRelevance(settingsService: $settings, logger: $this->logger));
	}//end reviewedCaseAssessedBy()

	/**
	 * A verdict on a document nobody marked marks it in scope, so it stops holding up the case.
	 *
	 * The second document gets no verdict and stays outstanding: the control
	 * that the marking follows the verdict and is not applied to the whole case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-verdict-marks-its-document-in-scope/specs/document-review/spec.md#requirement-a-verdict-on-an-unmarked-document-marks-it-in-scope-req-wrt-006
	 */
	public function testAVerdictMarksAnUnmarkedDocumentInScope(): void {
		$store = new InMemoryRegister();
		$service = $this->reviewedCaseAssessedBy(store: $store);
		$this->assertSame(2, $service->getOutstanding(caseId: 'case-x')['count']);

		$result = $service->bulkUpsert('case-x', [['documentRef' => 'doc-1', 'classification' => 'openbaar']]);

		$this->assertSame(['count' => 1, 'documents' => ['doc-2']], $result['outstanding']);
		$reviews = array_values($store->all('documentReview'));
		$this->assertCount(1, $reviews, 'only the judged document is marked');
		$this->assertSame('doc-1', $reviews[0]['documentRef']);
		$this->assertSame('in-scope', $reviews[0]['relevance']);
		$this->assertSame('reviewer', $reviews[0]['relevanceSource']);
		$this->assertSame('thomas', $reviews[0]['markedBy']);

		$service->bulkUpsert('case-x', [['documentRef' => 'doc-2', 'classification' => 'openbaar']]);
		$this->assertTrue($service->allDocumentsAssessed(caseId: 'case-x'));
	}//end testAVerdictMarksAnUnmarkedDocumentInScope()

	/**
	 * A verdict leaves a document a reviewer already set aside out of scope.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-verdict-marks-its-document-in-scope/specs/document-review/spec.md#requirement-a-verdict-on-an-unmarked-document-marks-it-in-scope-req-wrt-006
	 */
	public function testAVerdictKeepsAnOutOfScopeMarking(): void {
		$store = new InMemoryRegister();
		$store->seed(schema: 'documentReview', uuid: 'r-1', row: ['case' => 'case-x', 'documentRef' => 'doc-1', 'relevance' => 'out-of-scope', 'markedBy' => 'anna']);
		$service = $this->reviewedCaseAssessedBy(store: $store);

		$service->bulkUpsert('case-x', [['documentRef' => 'doc-1', 'classification' => 'openbaar']]);

		$this->assertSame('out-of-scope', $store->row('documentReview', 'r-1')['relevance']);
		$this->assertSame('anna', $store->row('documentReview', 'r-1')['markedBy']);
		$this->assertCount(1, $store->all('documentReview'));
	}//end testAVerdictKeepsAnOutOfScopeMarking()

	/**
	 * A refused verdict marks nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-verdict-marks-its-document-in-scope/specs/document-review/spec.md#requirement-a-verdict-on-an-unmarked-document-marks-it-in-scope-req-wrt-006
	 */
	public function testARefusedVerdictMarksNothing(): void {
		$store = new InMemoryRegister();
		$service = $this->reviewedCaseAssessedBy(store: $store);

		$result = $service->bulkUpsert('case-x', [['documentRef' => 'doc-1', 'classification' => 'niet_openbaar']]);

		$this->assertNotSame([], $result['errors']);
		$this->assertSame([], $store->all('documentReview'));
		$this->assertSame(2, $result['outstanding']['count']);
	}//end testARefusedVerdictMarksNothing()
}//end class
