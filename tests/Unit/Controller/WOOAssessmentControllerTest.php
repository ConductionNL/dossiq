<?php

/**
 * WOOAssessmentController Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\WOOAssessmentController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOOAnonymisationAssistService;
use OCA\Dossiq\Service\WOODeadlineService;
use OCA\Dossiq\Service\WOODecisionService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\WooPublicationService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for WOOAssessmentController.
 *
 * @covers \OCA\Dossiq\Controller\WOOAssessmentController
 *
 * @uses \OCA\Dossiq\Service\CaseAccessGuard
 */
class WOOAssessmentControllerTest extends TestCase {

	/**
	 * @var WOODocumentAssessmentService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private WOODocumentAssessmentService $assessmentService;

	/**
	 * @var WOODeadlineService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private WOODeadlineService $deadlineService;

	/**
	 * @var WOODecisionService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private WOODecisionService $decisionService;

	/**
	 * @var WooPublicationService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private WooPublicationService $publicationService;

	/**
	 * @var WOOAnonymisationAssistService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private WOOAnonymisationAssistService $anonymisationAssist;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var WOOAssessmentController
	 */
	private WOOAssessmentController $controller;

	/**
	 * @var CaseAccessGuard
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->assessmentService = $this->createMock(WOODocumentAssessmentService::class);
		$this->deadlineService = $this->createMock(WOODeadlineService::class);
		$this->decisionService = $this->createMock(WOODecisionService::class);
		$this->publicationService = $this->createMock(WooPublicationService::class);
		$this->anonymisationAssist = $this->createMock(WOOAnonymisationAssistService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Per-case authorization moved out of the controller into CaseAccessGuard
		// (authz-bypass-fixes). Every test in this class signs in as an admin, so
		// the guard short-circuits on isAdmin() and never reaches OpenRegister —
		// a bare SettingsService mock is sufficient here. The non-admin BAD paths
		// are covered by WOOAssessmentControllerAuthorizationTest.
		$this->caseAccessGuard = new CaseAccessGuard(
			settingsService: $this->createMock(SettingsService::class),
			groupManager: $this->groupManager,
			logger: $this->logger,
		);

		$this->controller = new WOOAssessmentController(
			'dossiq',
			$this->request,
			$this->assessmentService,
			$this->deadlineService,
			$this->decisionService,
			$this->publicationService,
			$this->anonymisationAssist,
			$this->userSession,
			$this->caseAccessGuard,
			$this->logger,
		);
	}//end setUp()

	/**
	 * BulkAssess returns 401 when user is not authenticated.
	 *
	 * @return void
	 */
	public function testBulkAssessReturns401WhenNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->bulkAssess('case-uuid-001');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testBulkAssessReturns401WhenNotAuthenticated()

	/**
	 * BulkAssess returns 200 with result when authenticated and authorized.
	 *
	 * @return void
	 */
	public function testBulkAssessReturnsResultWhenAuthenticated(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);

		// Admin → bypass case-level check.
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->request->method('getParam')->willReturnMap([
			['assessments', [], [
				['documentRef' => 'doc-001', 'classification' => 'openbaar'],
			]],
		]);

		$this->assessmentService->method('bulkUpsert')->willReturn([
			'saved' => [['id' => 'assessment-001']],
			'errors' => [],
			'outstanding' => ['count' => 0, 'documents' => []],
		]);

		$response = $this->controller->bulkAssess('case-uuid-001');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('saved', $data);
	}//end testBulkAssessReturnsResultWhenAuthenticated()

	/**
	 * ExtendDeadline returns 400 when reason is empty.
	 *
	 * @return void
	 */
	public function testExtendDeadlineReturns400ForEmptyReason(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->request->method('getParam')->willReturnMap([
			['reason', '', ''],
		]);

		$this->deadlineService
			->method('extendDeadline')
			->willThrowException(new \InvalidArgumentException('A reason is required'));

		$response = $this->controller->extendDeadline('case-uuid-001');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testExtendDeadlineReturns400ForEmptyReason()

	/**
	 * CreateDecision returns 422 when outstanding documents exist.
	 *
	 * Acceptance criterion: unassessed document → blocked with explicit error.
	 *
	 * @return void
	 */
	public function testCreateDecisionReturns422WhenDocumentsOutstanding(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->request->method('getParam')->willReturnMap([
			['decision', [], []],
		]);

		$this->decisionService
			->method('assembleDecision')
			->willThrowException(new \InvalidArgumentException('Cannot create besluit: 3 document(s) still need assessment.'));

		$response = $this->controller->createDecision('case-uuid-001');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testCreateDecisionReturns422WhenDocumentsOutstanding()

	/**
	 * PublishDecision returns 401 when user is not authenticated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-publication-via-opencatalogi/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function testPublishDecisionReturns401WhenNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->publishDecision('case-uuid-001');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testPublishDecisionReturns401WhenNotAuthenticated()

	/**
	 * PublishDecision returns 400 when decisionId is missing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-publication-via-opencatalogi/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function testPublishDecisionReturns400WhenDecisionIdMissing(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->request->method('getParam')->willReturnMap([
			['decisionId', '', ''],
		]);

		$response = $this->controller->publishDecision('case-uuid-001');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testPublishDecisionReturns400WhenDecisionIdMissing()

	/**
	 * PublishDecision returns the publication service result when authenticated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-publication-via-opencatalogi/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function testPublishDecisionReturnsResultWhenAuthenticated(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->request->method('getParam')->willReturnMap([
			['decisionId', '', 'decision-uuid-001'],
		]);

		$this->publicationService->method('publish')->willReturn([
			'available' => true,
			'publicationId' => 'pub-001',
			'publicationUrl' => '/index.php/apps/opencatalogi/publication/pub-001',
		]);

		$response = $this->controller->publishDecision('case-uuid-001');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['available']);
		$this->assertSame('pub-001', $data['publicationId']);
	}//end testPublishDecisionReturnsResultWhenAuthenticated()

	/**
	 * WithdrawPublication returns 401 when user is not authenticated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-publication-via-opencatalogi/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function testWithdrawPublicationReturns401WhenNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->withdrawPublication('case-uuid-001');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testWithdrawPublicationReturns401WhenNotAuthenticated()

	/**
	 * WithdrawPublication returns the publication service result when authenticated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-publication-via-opencatalogi/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function testWithdrawPublicationReturnsResultWhenAuthenticated(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->request->method('getParam')->willReturnMap([
			['decisionId', '', 'decision-uuid-001'],
		]);

		$this->publicationService->method('withdraw')->willReturn(['available' => true]);

		$response = $this->controller->withdrawPublication('case-uuid-001');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['available']);
	}//end testWithdrawPublicationReturnsResultWhenAuthenticated()

	/**
	 * ProposeRedaction returns 401 when user is not authenticated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-4
	 */
	public function testProposeRedactionReturns401WhenNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->proposeRedaction('case-uuid-001', 'doc-uuid-001');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testProposeRedactionReturns401WhenNotAuthenticated()

	/**
	 * ProposeRedaction is gated by the SAME per-case authorization check
	 * every other WOO mutation endpoint uses — a non-admin user outside the
	 * `procest-gebruikers` group is forbidden.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-4
	 */
	public function testProposeRedactionEnforcesCaseMutationAuthorization(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('outsider');
		$this->userSession->method('getUser')->willReturn($user);

		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$this->anonymisationAssist->expects($this->never())->method('proposeSpans');

		$this->expectException(\OCP\AppFramework\OCS\OCSForbiddenException::class);

		$this->controller->proposeRedaction('case-uuid-001', 'doc-uuid-001');
	}//end testProposeRedactionEnforcesCaseMutationAuthorization()

	/**
	 * ProposeRedaction returns 200 with the proposal when authenticated and authorized.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-4
	 */
	public function testProposeRedactionReturnsResultWhenAuthenticated(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->request->method('getParam')->willReturnMap([
			['text', '', 'Jan Jansen, BSN 123456782'],
		]);

		$this->anonymisationAssist->method('proposeSpans')->with(
			'case-uuid-001',
			'doc-uuid-001',
			'Jan Jansen, BSN 123456782',
			'j.dejong'
		)->willReturn([
			'spans' => [['start' => 0, 'end' => 10, 'category' => 'person', 'source' => 'llm']],
			'source' => 'rules_plus_llm',
			'llmAvailable' => true,
			'status' => 'pending_review',
		]);

		$response = $this->controller->proposeRedaction('case-uuid-001', 'doc-uuid-001');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('pending_review', $data['status']);
	}//end testProposeRedactionReturnsResultWhenAuthenticated()

	/**
	 * ProposeRedaction maps a validation failure from the service to 400.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-4
	 */
	public function testProposeRedactionMapsValidationFailureTo400(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->request->method('getParam')->willReturnMap([['text', '', '']]);

		$this->anonymisationAssist->method('proposeSpans')->willThrowException(
			new \InvalidArgumentException('text is required')
		);

		$response = $this->controller->proposeRedaction('case-uuid-001', 'doc-uuid-001');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testProposeRedactionMapsValidationFailureTo400()

	/**
	 * ReviewRedactionProposal returns 401 when user is not authenticated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-4
	 */
	public function testReviewRedactionProposalReturns401WhenNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->reviewRedactionProposal('case-uuid-001', 'doc-uuid-001');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testReviewRedactionProposalReturns401WhenNotAuthenticated()

	/**
	 * ReviewRedactionProposal returns 200 with the updated proposal when
	 * authenticated and authorized.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-4
	 */
	public function testReviewRedactionProposalReturnsResultWhenAuthenticated(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->request->method('getParam')->willReturnMap([
			['decision', '', 'approve'],
			['spans', null, null],
		]);

		$this->anonymisationAssist->method('reviewProposal')->with(
			'case-uuid-001',
			'doc-uuid-001',
			'approve',
			'j.dejong',
			null
		)->willReturn(['status' => 'approved', 'reviewedBy' => 'j.dejong']);

		$response = $this->controller->reviewRedactionProposal('case-uuid-001', 'doc-uuid-001');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('approved', $data['status']);
	}//end testReviewRedactionProposalReturnsResultWhenAuthenticated()

	/**
	 * ReviewRedactionProposal maps a "no pending proposal" failure to 400.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-4
	 */
	public function testReviewRedactionProposalMapsRuntimeFailureTo400(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->request->method('getParam')->willReturnMap([
			['decision', '', 'approve'],
			['spans', null, null],
		]);

		$this->anonymisationAssist->method('reviewProposal')->willThrowException(
			new \RuntimeException('No pending redaction proposal found for document doc-uuid-001 in case case-uuid-001')
		);

		$response = $this->controller->reviewRedactionProposal('case-uuid-001', 'doc-uuid-001');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testReviewRedactionProposalMapsRuntimeFailureTo400()
}//end class
