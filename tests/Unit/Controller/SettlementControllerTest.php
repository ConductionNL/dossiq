<?php

/**
 * `GET /api/cases/{caseId}/settlements` answers a reader of the case and
 * refuses anyone else before the settlements are read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/overview-cards-per-case-type/specs/case-page/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\SettlementController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Money\SettlementOverview;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The read behind the case page's settlement card.
 *
 * @covers \OCA\Dossiq\Controller\SettlementController
 */
class SettlementControllerTest extends TestCase {

	/** @var SettlementOverview&MockObject */
	private SettlementOverview $overview;

	/** @var CaseAccessGuard&MockObject */
	private CaseAccessGuard $caseAccess;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	protected function setUp(): void {
		$this->overview   = $this->createMock(SettlementOverview::class);
		$this->caseAccess = $this->createMock(CaseAccessGuard::class);
		$this->session    = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
	}//end setUp()

	/**
	 * A reader of the case gets the settlements.
	 *
	 * @return void
	 */
	public function testAReaderGetsTheSettlements(): void {
		$settlements = ['empty' => false, 'settlements' => [['id' => 'settlement-1', 'status' => 'determined']]];
		$this->caseAccess->method('hasCaseReadAccess')->with('case-1', $this->anything())->willReturn(true);
		$this->overview->expects($this->once())->method('forCase')->with('case-1')->willReturn($settlements);

		$response = $this->controller()->forCase(caseId: ' case-1 ');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($settlements, $response->getData());
	}//end testAReaderGetsTheSettlements()

	/**
	 * Someone who may not read the case is refused and nothing is read.
	 *
	 * @return void
	 */
	public function testANonReaderIsRefusedBeforeAnythingIsRead(): void {
		$this->caseAccess->method('hasCaseReadAccess')->willReturn(false);
		$this->overview->expects($this->never())->method('forCase');

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
		$this->overview->expects($this->never())->method('forCase');

		$controller = new SettlementController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			overview: $this->overview,
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
		$this->overview->method('forCase')->willThrowException(new RuntimeException('down'));

		$response = $this->controller()->forCase(caseId: 'case-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('The settlement could not be read. Try again later.', $response->getData()['message']);
	}//end testAFailingReadAnswersUnavailable()

	/**
	 * The controller over the doubles.
	 *
	 * @return SettlementController
	 */
	private function controller(): SettlementController {
		return new SettlementController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			overview: $this->overview,
			userSession: $this->session,
			caseAccess: $this->caseAccess,
		);
	}//end controller()
}//end class
