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

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODecisionService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
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

}//end class
