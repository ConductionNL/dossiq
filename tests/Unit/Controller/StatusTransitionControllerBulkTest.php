<?php

/**
 * StatusTransitionController bulk endpoint tests.
 *
 * Covers `bulkPreview()`/`bulkExecute()`: authentication gate, input
 * validation (missing transitionId, empty/oversized caseIds), the happy-path
 * pass-through to BulkStatusTransitionService, and RuntimeException → 400
 * mapping (guard-fail-shaped outcomes are reported inside the 200 response
 * body by the service itself, not raised as exceptions here).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\StatusTransitionController;
use OCA\Procest\Service\BulkStatusTransitionService;
use OCA\Procest\Service\CaseAccessGuard;
use OCA\Procest\Service\StatusTransitionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for StatusTransitionController::bulkPreview() and ::bulkExecute().
 *
 * @covers \OCA\Procest\Controller\StatusTransitionController
 *
 * @spec openspec/changes/case-bulk-status-transition/specs/case-bulk-status-transition/spec.md
 */
final class StatusTransitionControllerBulkTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * @var StatusTransitionService&MockObject
	 */
	private StatusTransitionService $transitionEngine;

	/**
	 * @var BulkStatusTransitionService&MockObject
	 */
	private BulkStatusTransitionService $bulkEngine;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * The controller under test.
	 *
	 * @var StatusTransitionController
	 */
	private StatusTransitionController $controller;

	/**
	 * Set up the test environment (authenticated as 'alice' by default).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->transitionEngine = $this->createMock(StatusTransitionService::class);
		$this->bulkEngine = $this->createMock(BulkStatusTransitionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);

		$this->controller = new StatusTransitionController(
			'procest',
			$this->request,
			$this->transitionEngine,
			$this->bulkEngine,
			$this->userSession,
			$this->logger,
			$this->caseAccessGuard,
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * bulkPreview() returns 401 when no user is authenticated.
	 *
	 * @return void
	 */
	public function testBulkPreviewReturnsUnauthorizedWhenNotAuthenticated(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = new StatusTransitionController(
			'procest',
			$this->request,
			$this->transitionEngine,
			$this->bulkEngine,
			$this->userSession,
			$this->logger,
			$this->caseAccessGuard,
		);

		$this->bulkEngine->expects($this->never())->method('preview');

		$response = $this->controller->bulkPreview();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testBulkPreviewReturnsUnauthorizedWhenNotAuthenticated()

	/**
	 * bulkExecute() returns 401 when no user is authenticated.
	 *
	 * @return void
	 */
	public function testBulkExecuteReturnsUnauthorizedWhenNotAuthenticated(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = new StatusTransitionController(
			'procest',
			$this->request,
			$this->transitionEngine,
			$this->bulkEngine,
			$this->userSession,
			$this->logger,
			$this->caseAccessGuard,
		);

		$this->bulkEngine->expects($this->never())->method('execute');

		$response = $this->controller->bulkExecute();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testBulkExecuteReturnsUnauthorizedWhenNotAuthenticated()

	/**
	 * bulkPreview() passes caseIds/transitionId through to the service and
	 * returns its result verbatim.
	 *
	 * @return void
	 */
	public function testBulkPreviewHappyPathPassesThroughToService(): void {
		$this->request->method('getParams')->willReturn(
			['caseIds' => ['case-1', 'case-2'], 'transitionId' => 'submit']
		);

		$serviceResult = [
			'results' => [
				'case-1' => ['status' => 'ready', 'reasons' => []],
				'case-2' => ['status' => 'blocked', 'reasons' => [['message' => 'missing document']]],
			],
			'summary' => ['total' => 2, 'ready' => 1, 'blocked' => 1, 'error' => 0],
		];

		$this->bulkEngine->expects($this->once())
			->method('preview')
			->with($this->equalTo(['case-1', 'case-2']), $this->equalTo('submit'))
			->willReturn($serviceResult);

		$response = $this->controller->bulkPreview();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame($serviceResult, $response->getData());
	}//end testBulkPreviewHappyPathPassesThroughToService()

	/**
	 * bulkPreview() returns 400 without touching the service when
	 * transitionId is missing.
	 *
	 * @return void
	 */
	public function testBulkPreviewReturnsBadRequestWhenTransitionIdMissing(): void {
		$this->request->method('getParams')->willReturn(['caseIds' => ['case-1']]);
		$this->bulkEngine->method('preview')->willThrowException(new RuntimeException('transition_id_required'));

		$response = $this->controller->bulkPreview();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testBulkPreviewReturnsBadRequestWhenTransitionIdMissing()

	/**
	 * bulkPreview() returns 400 when caseIds is empty (service rejects it).
	 *
	 * @return void
	 */
	public function testBulkPreviewReturnsBadRequestWhenCaseIdsEmpty(): void {
		$this->request->method('getParams')->willReturn(['caseIds' => [], 'transitionId' => 'submit']);
		$this->bulkEngine->method('preview')->willThrowException(new RuntimeException('case_ids_required'));

		$response = $this->controller->bulkPreview();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testBulkPreviewReturnsBadRequestWhenCaseIdsEmpty()

	/**
	 * bulkExecute() returns 400 when caseIds exceeds the 100-id cap (service rejects it).
	 *
	 * @return void
	 */
	public function testBulkExecuteReturnsBadRequestWhenCaseIdsOversized(): void {
		$ids = array_map(static fn (int $i): string => "case-$i", range(1, 101));
		$this->request->method('getParams')->willReturn(['caseIds' => $ids, 'transitionId' => 'submit']);
		$this->bulkEngine->method('execute')->willThrowException(new RuntimeException('too_many_case_ids'));

		$response = $this->controller->bulkExecute();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testBulkExecuteReturnsBadRequestWhenCaseIdsOversized()

	/**
	 * bulkExecute() happy path passes caseIds/transitionId/comment through to
	 * the service and returns its per-case result summary (guard-fail mapping
	 * is a 200 body field, not an HTTP error — partial success is reported,
	 * never silently swallowed).
	 *
	 * @return void
	 */
	public function testBulkExecuteHappyPathPassesThroughToServiceWithGuardFailReported(): void {
		$this->request->method('getParams')->willReturn(
			['caseIds' => ['case-1', 'case-2'], 'transitionId' => 'submit', 'comment' => 'batch move']
		);

		$serviceResult = [
			'results' => [
				'case-1' => ['status' => 'succeeded', 'statusRecord' => ['id' => 'rec-1']],
				'case-2' => ['status' => 'failed', 'reasons' => [['message' => 'missing document']]],
			],
			'summary' => ['total' => 2, 'succeeded' => 1, 'failed' => 1, 'error' => 0],
		];

		$this->bulkEngine->expects($this->once())
			->method('execute')
			->with(
				$this->equalTo(['case-1', 'case-2']),
				$this->equalTo('submit'),
				$this->equalTo('batch move'),
			)
			->willReturn($serviceResult);

		$response = $this->controller->bulkExecute();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame($serviceResult, $response->getData());
	}//end testBulkExecuteHappyPathPassesThroughToServiceWithGuardFailReported()
}//end class
