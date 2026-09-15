<?php

/**
 * StatusTransitionController JSON-body Phase-0 Regression Tests
 *
 * Locks the Phase-0 fix where the controller reads the JSON request body via
 * the PUBLIC IRequest::getParams() (which the AppFramework auto-populates from
 * the decoded body) instead of the PROTECTED Request::getContent(), whose call
 * from a controller raised a fatal "Call to protected method" 500 and broke the
 * POST /transition endpoint.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\StatusTransitionController;
use OCA\Dossiq\Service\BulkStatusTransitionService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\StatusTransitionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for StatusTransitionController body parsing.
 *
 * @covers \OCA\Dossiq\Controller\StatusTransitionController
 */
class StatusTransitionControllerBodyRegressionTest extends TestCase {

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
	 * The controller under test.
	 *
	 * @var StatusTransitionController
	 */
	private StatusTransitionController $controller;

	/**
	 * Set up the test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->transitionEngine = $this->createMock(StatusTransitionService::class);
		$this->bulkEngine = $this->createMock(BulkStatusTransitionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$caseAccessGuard = $this->createMock(CaseAccessGuard::class);
		$caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);

		$this->controller = new StatusTransitionController(
			'dossiq',
			$this->request,
			$this->transitionEngine,
			$this->bulkEngine,
			$this->userSession,
			$this->logger,
			$caseAccessGuard,
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

	}//end setUp()

	/**
	 * execute() reads the transitionId/comment from IRequest::getParams() — the
	 * public accessor — never from the protected getContent(). Proven by feeding
	 * the body solely through getParams() and asserting the engine sees it.
	 *
	 * @return void
	 */
	public function testExecuteReadsBodyFromGetParams(): void {
		$this->request->expects($this->atLeastOnce())
			->method('getParams')
			->willReturn(
				[
					'transitionId' => 'submit',
					'comment' => 'looks good',
				]
			);

		$this->transitionEngine->expects($this->once())
			->method('execute')
			->with(
				$this->equalTo('case-1'),
				$this->equalTo('submit'),
				$this->equalTo('looks good'),
			)
			->willReturn(['status' => 'ok']);

		$response = $this->controller->execute('case-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(['status' => 'ok'], $response->getData());

	}//end testExecuteReadsBodyFromGetParams()

	/**
	 * A missing transitionId in the params yields a 400 without touching the
	 * engine — confirms the body source is getParams() (empty here).
	 *
	 * @return void
	 */
	public function testExecuteReturnsBadRequestWhenTransitionIdMissing(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->transitionEngine->expects($this->never())->method('execute');

		$response = $this->controller->execute('case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testExecuteReturnsBadRequestWhenTransitionIdMissing()
}//end class
