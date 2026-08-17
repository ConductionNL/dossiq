<?php

/**
 * AiController Wire-Contract Tests
 *
 * Contract coverage for the four AI endpoints that had no automated proof of
 * their wire behaviour (gate-25): `ask`, `extract`, `recordAction` and
 * `suggestNext`. All four are `@NoAdminRequired` POSTs that take a
 * caller-supplied `caseId` and hand it to a service, so the properties worth
 * pinning are:
 *
 *  - an anonymous caller gets 401 and the AI/audit service is never reached —
 *    an LLM call made before the session check both leaks case context and
 *    costs money;
 *  - required-input validation is per-endpoint and NOT uniform: `ask` demands
 *    caseId AND question, `extract` demands only caseId (documentId is
 *    optional and must be forwarded as null, not as an empty string, or the
 *    service would look for a document with the id ""), `suggestNext` demands
 *    only caseId, `recordAction` demands caseId, type AND userAction;
 *  - each delegate is called with its arguments in the right ORDER. All of
 *    these methods take several same-typed strings in a row, so a transposed
 *    pair (`type` and `userAction` on recordUserAction, `question` and `userId`
 *    on askQuestion) type-checks perfectly and is the realistic defect.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\AiController;
use OCA\Procest\Service\Ai\AiAuditService;
use OCA\Procest\Service\AiService;
use OCA\Procest\Service\CaseAccessGuard;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wire-contract tests for AiController.
 *
 * @covers \OCA\Procest\Controller\AiController
 */
class AiControllerContractTest extends TestCase {

	/**
	 * The inbound request mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The AI service mock.
	 *
	 * @var AiService|MockObject
	 */
	private AiService $aiService;

	/**
	 * The AI oversight audit service mock.
	 *
	 * @var AiAuditService|MockObject
	 */
	private AiAuditService $auditService;

	/**
	 * The session mock.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The controller under test.
	 *
	 * @var AiController
	 */
	private AiController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->aiService = $this->createMock(AiService::class);
		$this->auditService = $this->createMock(AiAuditService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new AiController(
			appName: 'procest',
			request: $this->request,
			aiService: $this->aiService,
			auditService: $this->auditService,
			userSession: $this->userSession,
			logger: $this->createMock(LoggerInterface::class),
			caseAccessGuard: $this->createMock(CaseAccessGuard::class),
		);
	}//end setUp()

	/**
	 * Mark the session as authenticated for user `alice`.
	 *
	 * @return void
	 */
	private function authenticate(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end authenticate()

	/**
	 * Mark the session as anonymous.
	 *
	 * @return void
	 */
	private function anonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);
	}//end anonymous()

	/**
	 * Feed the request parameter bag.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				if (array_key_exists($key, $params) === true) {
					return $params[$key];
				}

				return $default;
			}
		);
	}//end withParams()

	/**
	 * No AI work is done for an anonymous caller on any of the four endpoints,
	 * and each answers the same 401 body.
	 *
	 * An LLM round-trip started before the session check would both bill the
	 * instance and put case context into a prompt for a caller with no session.
	 *
	 * @return void
	 */
	public function testAllFourEndpointsRefuseAnAnonymousCallerBeforeAnyAiWork(): void {
		$this->anonymous();
		$this->withParams(['caseId' => 'case-1', 'question' => 'q', 'type' => 'routing', 'userAction' => 'accepted']);

		$this->aiService->expects($this->never())->method('askQuestion');
		$this->aiService->expects($this->never())->method('extractData');
		$this->aiService->expects($this->never())->method('suggestNextStep');
		$this->auditService->expects($this->never())->method('recordUserAction');

		$responses = [
			'ask' => $this->controller->ask(),
			'extract' => $this->controller->extract(),
			'suggestNext' => $this->controller->suggestNext(),
			'recordAction' => $this->controller->recordAction(),
		];

		foreach ($responses as $endpoint => $response) {
			$this->assertSame(
				Http::STATUS_UNAUTHORIZED,
				$response->getStatus(),
				$endpoint . ' must answer 401 for a caller without a session'
			);
			$this->assertSame(
				['error' => 'Not authenticated'],
				$response->getData(),
				$endpoint . ' must answer the standard unauthenticated body'
			);
		}
	}//end testAllFourEndpointsRefuseAnAnonymousCallerBeforeAnyAiWork()

	/**
	 * ask() demands BOTH caseId and question; a caseId alone is a 400 and no
	 * prompt is sent.
	 *
	 * @return void
	 */
	public function testAskRejectsAMissingQuestionWithoutPrompting(): void {
		$this->authenticate();
		$this->withParams(['caseId' => 'case-1']);
		$this->aiService->expects($this->never())->method('askQuestion');

		$response = $this->controller->ask();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'caseId and question are required'], $response->getData());
	}//end testAskRejectsAMissingQuestionWithoutPrompting()

	/**
	 * ask() forwards caseId, question and the SESSION user id — in that order —
	 * and returns the service answer verbatim at 200.
	 *
	 * @return void
	 */
	public function testAskForwardsCaseQuestionAndSessionUserInOrder(): void {
		$this->authenticate();
		$this->withParams(['caseId' => 'case-1', 'question' => 'Wat is de beslistermijn?', 'userId' => 'mallory']);

		$answer = ['answer' => 'Acht weken.', 'sources' => []];

		$this->aiService->expects($this->once())
			->method('askQuestion')
			->with('case-1', 'Wat is de beslistermijn?', 'alice')
			->willReturn($answer);

		$response = $this->controller->ask();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($answer, $response->getData());
	}//end testAskForwardsCaseQuestionAndSessionUserInOrder()

	/**
	 * extract() demands a caseId and refuses without one.
	 *
	 * @return void
	 */
	public function testExtractRejectsAMissingCaseId(): void {
		$this->authenticate();
		$this->withParams(['documentId' => 'doc-1']);
		$this->aiService->expects($this->never())->method('extractData');

		$response = $this->controller->extract();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'caseId is required'], $response->getData());
	}//end testExtractRejectsAMissingCaseId()

	/**
	 * extract() treats documentId as OPTIONAL and forwards a genuine null when
	 * it is absent — the service signature is `?string $documentId`, so an
	 * empty string would be a request to extract from a document called "".
	 *
	 * @return void
	 */
	public function testExtractForwardsANullDocumentIdWhenNoneWasGiven(): void {
		$this->authenticate();
		$this->withParams(['caseId' => 'case-1']);

		$extracted = ['fields' => ['bsn' => '***']];

		$this->aiService->expects($this->once())
			->method('extractData')
			->with('case-1', null, 'alice')
			->willReturn($extracted);

		$response = $this->controller->extract();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($extracted, $response->getData());
	}//end testExtractForwardsANullDocumentIdWhenNoneWasGiven()

	/**
	 * With a documentId supplied, extract() scopes the extraction to that
	 * document.
	 *
	 * @return void
	 */
	public function testExtractScopesToTheSuppliedDocument(): void {
		$this->authenticate();
		$this->withParams(['caseId' => 'case-1', 'documentId' => 'doc-9']);

		$this->aiService->expects($this->once())
			->method('extractData')
			->with('case-1', 'doc-9', 'alice')
			->willReturn(['fields' => []]);

		$response = $this->controller->extract();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testExtractScopesToTheSuppliedDocument()

	/**
	 * suggestNext() demands a caseId and refuses without one.
	 *
	 * @return void
	 */
	public function testSuggestNextRejectsAMissingCaseId(): void {
		$this->authenticate();
		$this->withParams([]);
		$this->aiService->expects($this->never())->method('suggestNextStep');

		$response = $this->controller->suggestNext();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'caseId is required'], $response->getData());
	}//end testSuggestNextRejectsAMissingCaseId()

	/**
	 * suggestNext() delegates to `suggestNextStep` — NOT `suggestRouting`,
	 * which is a different endpoint on the same controller — with the case id
	 * and the session user, and returns the suggestion set verbatim.
	 *
	 * @return void
	 */
	public function testSuggestNextDelegatesToSuggestNextStepNotSuggestRouting(): void {
		$this->authenticate();
		$this->withParams(['caseId' => 'case-1']);

		$suggestions = ['steps' => [['action' => 'request_advice', 'confidence' => 0.8]]];

		$this->aiService->expects($this->never())->method('suggestRouting');
		$this->aiService->expects($this->once())
			->method('suggestNextStep')
			->with('case-1', 'alice')
			->willReturn($suggestions);

		$response = $this->controller->suggestNext();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($suggestions, $response->getData());
	}//end testSuggestNextDelegatesToSuggestNextStepNotSuggestRouting()

	/**
	 * recordAction() demands caseId, type AND userAction. A body carrying only
	 * two of the three is a 400 and nothing is written to the oversight log —
	 * a half-identified entry is worse than none, because the Algorithm Act
	 * register is read as complete.
	 *
	 * @return void
	 */
	public function testRecordActionRejectsAMissingUserActionAndWritesNothing(): void {
		$this->authenticate();
		$this->withParams(['caseId' => 'case-1', 'type' => 'routing']);
		$this->auditService->expects($this->never())->method('recordUserAction');

		$response = $this->controller->recordAction();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'caseId, type, and userAction are required'], $response->getData());
	}//end testRecordActionRejectsAMissingUserActionAndWritesNothing()

	/**
	 * recordAction() writes the oversight entry with its seven arguments in the
	 * declared order — caseId, type, userAction, suggestion, actualValue,
	 * reason, userId — and the recorded user id comes from the SESSION, not
	 * from a `userId` the caller may have posted.
	 *
	 * Four of those seven are strings, so a transposition is invisible to the
	 * type system and would silently mislabel every entry in the log.
	 *
	 * @return void
	 */
	public function testRecordActionWritesTheOversightEntryInDeclaredOrder(): void {
		$this->authenticate();
		$this->withParams(
			[
				'caseId' => 'case-1',
				'type' => 'routing',
				'userAction' => 'rejected',
				'suggestion' => ['team' => 'vergunningen'],
				'actualValue' => ['team' => 'handhaving'],
				'reason' => 'Betreft een handhavingsverzoek.',
				'userId' => 'mallory',
			]
		);

		$written = ['id' => 'audit-1'];

		$this->auditService->expects($this->once())
			->method('recordUserAction')
			->with(
				'case-1',
				'routing',
				'rejected',
				['team' => 'vergunningen'],
				['team' => 'handhaving'],
				'Betreft een handhavingsverzoek.',
				'alice'
			)
			->willReturn($written);

		$response = $this->controller->recordAction();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($written, $response->getData());
	}//end testRecordActionWritesTheOversightEntryInDeclaredOrder()

	/**
	 * `actualValue` and `reason` are optional and reach the service as nulls,
	 * not as empty strings/arrays — the audit entry distinguishes "the user did
	 * not correct the suggestion" from "the user corrected it to nothing".
	 *
	 * @return void
	 */
	public function testRecordActionForwardsAbsentOptionalsAsNull(): void {
		$this->authenticate();
		$this->withParams(['caseId' => 'case-1', 'type' => 'routing', 'userAction' => 'accepted']);

		$this->auditService->expects($this->once())
			->method('recordUserAction')
			->with('case-1', 'routing', 'accepted', [], null, null, 'alice')
			->willReturn(['id' => 'audit-2']);

		$response = $this->controller->recordAction();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testRecordActionForwardsAbsentOptionalsAsNull()
}//end class
