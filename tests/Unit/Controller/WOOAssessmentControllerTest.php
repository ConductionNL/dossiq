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
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Cases\HandlerActsAsSystem;
use OCA\Dossiq\Service\DecisionDocument\DecisionLetter;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOOAnonymisationAssistService;
use OCA\Dossiq\Service\WOODeadlineService;
use OCA\Dossiq\Service\WOODecisionService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\WooPublicationService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RefusalGroundStore;
use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IL10N;
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
 * @uses \OCA\Dossiq\Service\Cases\HandlerActsAsSystem
 * @uses \OCA\Dossiq\Service\WOODocumentAssessmentService
 * @uses \OCA\Dossiq\Woo\WooRefusalGrounds
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Review\OutstandingDocuments
 * @uses \OCA\Dossiq\Review\DisclosureAssessmentValidator
 * @uses \OCA\Dossiq\Review\DocumentRelevance
 * @uses \OCA\Dossiq\Review\PagesSeen
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
			$this->untranslated(),
			$this->handlerActs(),
		);
	}//end setUp()

	/**
	 * The handler gate over this test's guard, on a case whose decision is published.
	 *
	 * @param array<string, mixed> $case The case the store answers.
	 *
	 * @return HandlerActsAsSystem The gate.
	 */
	private function handlerActs(array $case = ['wooPublicationStatus' => 'published']): HandlerActsAsSystem {
		$store = $this->createMock(CaseStatusStore::class);
		$store->method('loadCase')->willReturn($case);

		return new HandlerActsAsSystem(
			guard: $this->caseAccessGuard,
			store: $store,
			settings: $this->createMock(SettingsService::class),
		);
	}//end handlerActs()

	/**
	 * Translations that hand every source sentence back unchanged.
	 *
	 * @return IL10N
	 */
	private function untranslated(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		return $l10n;
	}//end untranslated()

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
	 * The controller over the real assessment service and the seeded grounds.
	 *
	 * @param RefusalGroundStore $grounds The grounds store.
	 *
	 * @return WOOAssessmentController The controller, signed in as an admin.
	 */
	private function controllerOverTheRealList(RefusalGroundStore $grounds): WOOAssessmentController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($grounds);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => (['register' => 'dossiq', 'woo_assessment_schema' => 'wooDocumentAssessment'][$key] ?? '')
		);

		$service = new WOODocumentAssessmentService(
			$settings,
			$this->userSession,
			$this->logger,
			null,
			new WooRefusalGrounds(settingsService: $settings, logger: $this->logger),
		);

		return new WOOAssessmentController(
			'dossiq',
			$this->request,
			$service,
			$this->deadlineService,
			$this->decisionService,
			$this->publicationService,
			$this->anonymisationAssist,
			$this->userSession,
			$this->caseAccessGuard,
			$this->logger,
			$this->untranslated(),
			$this->handlerActs(),
		);
	}//end controllerOverTheRealList()

	/**
	 * An assessment citing a ground the settled list does not carry answers 422.
	 *
	 * @return void
	 */
	public function testBulkAssessWithAnUnknownGroundAnswers422(): void {
		$this->request->method('getParam')->willReturnMap([
			['assessments', [], [
				['documentRef' => 'doc-001', 'classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.2.5']],
			]],
		]);

		$response = $this->controllerOverTheRealList(grounds: RefusalGroundStore::seeded())->bulkAssess('case-uuid-001');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('Unknown weigeringsgrond: 5.2.5', $response->getData()['errors'][0]['errors']['weigeringsgronden']);
	}//end testBulkAssessWithAnUnknownGroundAnswers422()

	/**
	 * A verdict on an in-scope document whose required pages are unseen is not stored, and the answer names the pages.
	 *
	 * @return void
	 */
	public function testBulkAssessRefusesUnseenPages(): void {
		$store = new InMemoryRegister();
		$store->seed(schema: 'document', uuid: 'doc-001', row: ['case' => 'case-uuid-001']);
		$store->seed(schema: 'document', uuid: 'doc-002', row: ['case' => 'case-uuid-001']);
		$store->seed(schema: 'documentReview', uuid: 'r-1', row: ['case' => 'case-uuid-001', 'documentRef' => 'doc-001', 'relevance' => 'in-scope', 'pagesRequired' => [1, 2, 3], 'pagesSeen' => [['page' => 1, 'by' => 'j.dejong']]]);
		$store->seed(schema: 'documentReview', uuid: 'r-2', row: ['case' => 'case-uuid-001', 'documentRef' => 'doc-002', 'relevance' => 'in-scope', 'pagesRequired' => [1], 'pagesSeen' => [['page' => 1, 'by' => 'j.dejong']]]);
		$this->request->method('getParam')->willReturnMap([
			['assessments', [], [
				['documentRef' => 'doc-001', 'classification' => 'openbaar'],
				['documentRef' => 'doc-002', 'classification' => 'openbaar'],
			]],
		]);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$config = ['register' => 'dossiq', 'woo_assessment_schema' => 'wooDocumentAssessment', 'document_review_schema' => 'documentReview', 'document_schema' => 'document'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));
		$reviews = new DocumentRelevance(settingsService: $settings, logger: $this->logger);
		$service = new WOODocumentAssessmentService($settings, $this->userSession, $this->logger, null, null, reviews: $reviews);
		$controller = new WOOAssessmentController(
			'dossiq',
			$this->request,
			$service,
			$this->deadlineService,
			$this->decisionService,
			$this->publicationService,
			$this->anonymisationAssist,
			$this->userSession,
			$this->caseAccessGuard,
			$this->logger,
			$this->untranslated(),
			$this->handlerActs(),
		);

		$response = $controller->bulkAssess('case-uuid-001');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('doc-001', $response->getData()['errors'][0]['documentRef']);
		$this->assertSame('Open these pages before giving a verdict: 2, 3.', $response->getData()['errors'][0]['errors']['pagesSeen']);
		$stored = array_column($store->all('wooDocumentAssessment'), 'documentRef');
		$this->assertSame(['doc-002'], $stored);
	}//end testBulkAssessRefusesUnseenPages()

	/**
	 * An unreadable list refuses the whole call with 503 and the rule.
	 *
	 * @return void
	 */
	public function testBulkAssessWithAnUnreadableListAnswers503(): void {
		$this->request->method('getParam')->willReturnMap([
			['assessments', [], [
				['documentRef' => 'doc-001', 'classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.2.e']],
			]],
		]);
		$grounds = RefusalGroundStore::seeded();
		$grounds->fails = true;

		$response = $this->controllerOverTheRealList(grounds: $grounds)->bulkAssess('case-uuid-001');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('woo-refusal-grounds-unavailable', $response->getData()['error']);
	}//end testBulkAssessWithAnUnreadableListAnswers503()

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
	 * A second Woo extension answers 409 with the term engine's rule and
	 * sentence, not a 500 (one-term-engine REQ: only one extension allowed).
	 *
	 * @return void
	 */
	public function testASecondWooExtensionAnswers409(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([['reason', '', 'Nog meer tijd nodig']]);

		$this->deadlineService->method('extendDeadline')->willThrowException(
			new RefusedException(
				rule: 'extension-ceiling-reached',
				sentence: 'This term has had every extension it allows.',
			)
		);

		$response = $this->controller->extendDeadline('case-uuid-001');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('extension-ceiling-reached', $response->getData()['error']);
		$this->assertSame('This term has had every extension it allows.', $response->getData()['message']);
	}//end testASecondWooExtensionAnswers409()

	/**
	 * Without an ExtensionNotice wired the extension stands, and the answer
	 * says the requester was not told (REQ-WRN-005).
	 *
	 * @return void
	 */
	public function testAnExtensionWithoutANoticeSaysTheRequesterWasNotTold(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([['reason', '', 'Zienswijzen van derden']]);
		$this->deadlineService->method('extendDeadline')->willReturn(
			['caseId' => 'case-uuid-001', 'deadline' => '2026-11-16', 'termInstanceId' => 'ti-1', 'extensionReason' => 'Zienswijzen van derden']
		);

		$response = $this->controller->extendDeadline('case-uuid-001');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('2026-11-16', $response->getData()['deadline']);
		$this->assertSame('not-sent', $response->getData()['noticeStatus']);
		$this->assertSame('notice-not-wired', $response->getData()['noticeReasonCode']);
	}//end testAnExtensionWithoutANoticeSaysTheRequesterWasNotTold()

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
	 * A letter from another case is refused with 422 before the decision is written, and nothing is linked.
	 *
	 * @return void
	 */
	public function testALetterOfAnotherCaseIsRefusedBeforeTheDecisionIsWritten(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['decision', [], []],
			['letter', '', 'io-of-case-b'],
		]);

		$letters = $this->createMock(DecisionLetter::class);
		$letters->expects($this->once())->method('assertOnCase')->with('case-a', 'io-of-case-b')
			->willThrowException(new \InvalidArgumentException('The letter is not a document of this case.'));
		$letters->expects($this->never())->method('linkDecision');
		$this->decisionService->expects($this->never())->method('assembleDecision');
		$controller = new WOOAssessmentController(
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
			$this->untranslated(),
			$this->handlerActs(),
			null,
			$letters,
		);

		$response = $controller->createDecision('case-a');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('The letter is not a document of this case.', $response->getData()['error']);
	}//end testALetterOfAnotherCaseIsRefusedBeforeTheDecisionIsWritten()

	/**
	 * A letter of the case is passed on, so no second letter is drafted, and the decision is linked to it.
	 *
	 * @return void
	 */
	public function testALetterOfTheCaseIsLinkedToTheDecision(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['decision', [], []],
			['letter', '', 'io-9'],
		]);

		$letters = $this->createMock(DecisionLetter::class);
		$letters->expects($this->once())->method('assertOnCase')->with('case-a', 'io-9');
		$letters->expects($this->once())->method('linkDecision')
			->with(['decisionId' => 'dec-1', 'draft' => null], 'io-9')->willReturn('io-9');
		$this->decisionService->expects($this->once())->method('assembleDecision')
			->with('case-a', [], 'io-9')->willReturn(['decisionId' => 'dec-1', 'draft' => null]);
		$controller = new WOOAssessmentController(
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
			$this->untranslated(),
			$this->handlerActs(),
			null,
			$letters,
		);

		$response = $controller->createDecision('case-a');

		$this->assertSame('io-9', $response->getData()['letter']);
	}//end testALetterOfTheCaseIsLinkedToTheDecision()

	/**
	 * A decision that waits for unseen pages answers 409 naming the documents and pages; publish does the same.
	 *
	 * @return void
	 */
	public function testDecisionAndPublishAnswer409NamingTheUnseenPages(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([['decision', [], []], ['decisionId', '', '']]);
		$this->decisionService->method('assembleDecision')->willThrowException(
			new RefusedException(rule: 'review-pages-unseen', sentence: 'The decision waits until every required page has been seen.', status: 409)
		);
		$this->decisionService->method('unseenPages')->willReturn(['doc-7' => [7]]);
		$this->publicationService->method('publish')->willReturn(['available' => false, 'reason' => 'pages_unseen', 'unseen' => ['doc-7' => [7]]]);

		$decision = $this->controller->createDecision('case-uuid-001');
		$this->assertSame(409, $decision->getStatus());
		$this->assertSame('review-pages-unseen', $decision->getData()['error']);
		$this->assertSame(['doc-7' => [7]], $decision->getData()['unseen']);

		$published = $this->controller->publishDecision('case-uuid-001');
		$this->assertSame(409, $published->getStatus());
		$this->assertSame(['doc-7' => [7]], $published->getData()['unseen']);
		$this->assertSame('Publishing waits until every required page has been seen.', $published->getData()['message']);
	}//end testDecisionAndPublishAnswer409NamingTheUnseenPages()

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
	 * Without a decision id the case's Woo decision is used; a case with none answers 409.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-endpoints-find-the-cases-woo-decision-req-wpi-005
	 */
	public function testPublishWithoutADecisionIdAnswers409WhenTheCaseHasNoWooDecision(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->request->method('getParam')->willReturnMap([
			['decisionId', '', ''],
		]);
		$this->publicationService->expects($this->once())->method('publish')
			->with('case-uuid-001', '')
			->willReturn(['available' => false, 'reason' => 'no_woo_decision']);

		$response = $this->controller->publishDecision('case-uuid-001');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('This case has no Woo decision yet. Assemble the decision first.', $response->getData()['message']);
	}//end testPublishWithoutADecisionIdAnswers409WhenTheCaseHasNoWooDecision()

	/**
	 * A refusal reads in the user's language: the header action shows the
	 * server's `message` as it is, so the server translates it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-action-shows-only-to-whoever-may-publish-and-says-what-happened-req-wpi-009
	 */
	public function testARefusalIsTranslatedForTheHeaderAction(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['decisionId', '', ''],
		]);
		$this->publicationService->method('publish')
			->willReturn(['available' => false, 'reason' => 'no_publishable_documents']);

		$dutch = $this->createMock(IL10N::class);
		$dutch->expects($this->once())->method('t')
			->with('Nothing can be published yet: no document is assessed as public.')
			->willReturn('Er kan nog niets gepubliceerd worden: geen enkel document is als openbaar beoordeeld.');

		$controller = new WOOAssessmentController(
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
			$dutch,
			$this->handlerActs(),
		);

		$response = $controller->publishDecision('case-uuid-001');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			'Er kan nog niets gepubliceerd worden: geen enkel document is als openbaar beoordeeld.',
			$response->getData()['message']
		);
	}//end testARefusalIsTranslatedForTheHeaderAction()

	/**
	 * A missing OpenCatalogi answers 503 with a sentence the header action shows.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-endpoints-find-the-cases-woo-decision-req-wpi-005
	 */
	public function testWithdrawWithoutOpenCatalogiAnswers503(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->request->method('getParam')->willReturnMap([
			['decisionId', '', ''],
		]);
		$this->publicationService->expects($this->once())->method('withdraw')
			->with('', 'case-uuid-001')
			->willReturn(['available' => false, 'reason' => 'opencatalogi_not_installed']);

		$response = $this->controller->withdrawPublication('case-uuid-001');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}//end testWithdrawWithoutOpenCatalogiAnswers503()

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
	 * The publish answer carries the delivered set's id (woo-delivered-set-is-a-record REQ-WDS-001).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
	 */
	public function testPublishDecisionAnswersTheSetId(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->publicationService->method('publish')->willReturn(['available' => true, 'publicationId' => 'pub-001', 'deliveredSet' => 'set-001']);

		$response = $this->controller->publishDecision('case-uuid-001');

		$this->assertSame('set-001', $response->getData()['deliveredSet']);
	}//end testPublishDecisionAnswersTheSetId()

	/**
	 * The publish answer carries the withheld record, refusals included.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-published-woo-decision-records-its-withheld-documents-in-opencatalogi-req-wrw-001
	 */
	public function testPublishDecisionAnswersTheWithheldRecord(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$record = ['status' => 'recorded', 'recorded' => 1, 'refused' => [['position' => 7, 'code' => '5.1.2.e', 'reason' => 'unknown-ground']]];
		$this->publicationService->method('publish')->willReturn(['available' => true, 'publicationId' => 'pub-001', 'withheldRecord' => $record]);

		$response = $this->controller->publishDecision('case-uuid-001');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($record, $response->getData()['withheldRecord']);
	}//end testPublishDecisionAnswersTheWithheldRecord()

	/**
	 * An unrecorded delivered set refuses the publish with 503 and a sentence.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
	 */
	public function testAnUnrecordedSetRefusesThePublish(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->publicationService->method('publish')->willReturn(['available' => false, 'reason' => 'delivered_set_not_written']);

		$response = $this->controller->publishDecision('case-uuid-001');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertStringContainsString('recorded', $response->getData()['message']);
	}//end testAnUnrecordedSetRefusesThePublish()

	/**
	 * A disclosable document whose file cannot be read refuses the publish with 409, naming it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	public function testAnUnreadableDocumentRefusesThePublish(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.dejong');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->publicationService->method('publish')->willReturn(['available' => false, 'reason' => 'documents_unreadable', 'documents' => ['nota.pdf']]);

		$response = $this->controller->publishDecision('case-uuid-001');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertStringContainsString('could not be read', $response->getData()['message']);
		$this->assertSame(['nota.pdf'], $response->getData()['documents']);
	}//end testAnUnreadableDocumentRefusesThePublish()

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

	/**
	 * Another reviewer's proposal is refused with 403 and its rule, not a 400 (REQ-CRA-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-reviewer-changes-only-the-records-they-made-req-cra-003
	 */
	public function testReviewRedactionProposalRefusesAnotherReviewersProposal(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('p.bakker');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['decision', '', 'approve'],
			['spans', null, null],
		]);

		$this->anonymisationAssist->method('reviewProposal')->willThrowException(
			new \OCA\Dossiq\Exception\RefusedException(
				rule: 'owned-by-another-reviewer',
				sentence: 'Another reviewer assessed this document.',
				status: Http::STATUS_FORBIDDEN,
			)
		);

		$response = $this->controller->reviewRedactionProposal('case-uuid-001', 'doc-uuid-001');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('owned-by-another-reviewer', $response->getData()['error']);
	}//end testReviewRedactionProposalRefusesAnotherReviewersProposal()

	/**
	 * Proposing on another reviewer's assessment is refused the same way.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-reviewer-changes-only-the-records-they-made-req-cra-003
	 */
	public function testProposeRedactionRefusesAnotherReviewersAssessment(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('p.bakker');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([['text', '', 'Jan Jansen woont hier']]);

		$this->anonymisationAssist->method('proposeSpans')->willThrowException(
			new \OCA\Dossiq\Exception\RefusedException(
				rule: 'owned-by-another-reviewer',
				sentence: 'Another reviewer assessed this document.',
				status: Http::STATUS_FORBIDDEN,
			)
		);

		$response = $this->controller->proposeRedaction('case-uuid-001', 'doc-uuid-001');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('owned-by-another-reviewer', $response->getData()['error']);
	}//end testProposeRedactionRefusesAnotherReviewersAssessment()
}//end class
