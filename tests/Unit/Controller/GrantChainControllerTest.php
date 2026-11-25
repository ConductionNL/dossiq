<?php

/**
 * `GET /api/cases/{caseId}/grant-chain` answers a reader of the case and
 * refuses anyone else before the grant chain is read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\GrantChainController;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Service\Money\GrantChainSteps;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The read behind the case page's grant actions.
 *
 * @covers \OCA\Dossiq\Controller\GrantChainController
 */
class GrantChainControllerTest extends TestCase {

	/** @var GrantChainSteps&MockObject */
	private GrantChainSteps $steps;

	/** @var CaseAccessGuardInterface&MockObject */
	private CaseAccessGuardInterface $caseAccess;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	protected function setUp(): void {
		$this->steps   = $this->createMock(GrantChainSteps::class);
		$this->caseAccess = $this->createMock(CaseAccessGuardInterface::class);
		$this->session    = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
	}//end setUp()

	/**
	 * A reader of the case gets the grant chain.
	 *
	 * @return void
	 */
	public function testAReaderGetsTheGrantChain(): void {
		$chain = ['empty' => false, 'executions' => [['id' => 'execution-1', 'interimReports' => [], 'settlements' => []]]];
		$this->caseAccess->method('hasCaseReadAccess')->with('case-1', $this->anything())->willReturn(true);
		$this->steps->expects($this->once())->method('forCase')->with('case-1')->willReturn($chain);

		$response = $this->controller()->forCase(caseId: ' case-1 ');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($chain, $response->getData());
	}//end testAReaderGetsTheGrantChain()

	/**
	 * Someone who may not read the case is refused and nothing is read.
	 *
	 * @return void
	 */
	public function testANonReaderIsRefusedBeforeAnythingIsRead(): void {
		$this->caseAccess->method('hasCaseReadAccess')->willReturn(false);
		$this->steps->expects($this->never())->method('forCase');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->forCase(caseId: 'case-1')->getStatus());
	}//end testANonReaderIsRefusedBeforeAnythingIsRead()

	/**
	 * Without a signed-in user the read is refused.
	 *
	 * @return void
	 */
	public function testWithoutAUserTheReadIsRefused(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->steps->expects($this->never())->method('forCase');

		$controller = new GrantChainController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			steps: $this->steps,
			userSession: $session,
			caseAccess: $this->caseAccess,
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->forCase(caseId: 'case-1')->getStatus());
	}//end testWithoutAUserTheReadIsRefused()

	/**
	 * A failing read answers 503 with a sentence.
	 *
	 * @return void
	 */
	public function testAFailingReadAnswersUnavailable(): void {
		$this->caseAccess->method('hasCaseReadAccess')->willReturn(true);
		$this->steps->method('forCase')->willThrowException(new RuntimeException('down'));

		$response = $this->controller()->forCase(caseId: 'case-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('The grants of this case could not be read. Try again later.', $response->getData()['message']);
	}//end testAFailingReadAnswersUnavailable()

	/**
	 * The controller over the doubles.
	 *
	 * @return GrantChainController
	 */
	private function controller(): GrantChainController {
		return new GrantChainController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			steps: $this->steps,
			userSession: $this->session,
			caseAccess: $this->caseAccess,
		);
	}//end controller()
}//end class
