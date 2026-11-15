<?php

/**
 * `GET /api/cases/{caseId}/payment-schedule` answers a reader of the case and
 * refuses anyone else before the schedule is read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-a-handler-runs-a-grant-from-its-case-page-req-sub-case-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PaymentScheduleController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Money\PaymentScheduleOverview;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * REQ-SUB-CASE-01, the read behind the case page's schedule.
 *
 * @covers \OCA\Dossiq\Controller\PaymentScheduleController
 */
class PaymentScheduleControllerTest extends TestCase {

	/** @var PaymentScheduleOverview&MockObject */
	private PaymentScheduleOverview $overview;

	/** @var CaseAccessGuard&MockObject */
	private CaseAccessGuard $caseAccess;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	protected function setUp(): void {
		$this->overview   = $this->createMock(PaymentScheduleOverview::class);
		$this->caseAccess = $this->createMock(CaseAccessGuard::class);
		$this->session    = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
	}//end setUp()

	/**
	 * A reader of the case gets the schedule.
	 *
	 * @return void
	 */
	public function testAReaderGetsTheSchedule(): void {
		$schedule = ['instalments' => [['label' => '1e voorschot', 'state' => 'paid']], 'totals' => ['total' => 4800.0]];
		$this->caseAccess->method('hasCaseReadAccess')->with('case-1', $this->anything())->willReturn(true);
		$this->overview->expects($this->once())->method('forCase')->with('case-1', $this->anything())->willReturn($schedule);

		$response = $this->controller()->forCase(caseId: ' case-1 ');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($schedule, $response->getData());
	}//end testAReaderGetsTheSchedule()

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

		$controller = new PaymentScheduleController(
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
		$this->assertSame('The payment schedule could not be read. Try again later.', $response->getData()['message']);
	}//end testAFailingReadAnswersUnavailable()

	/**
	 * The controller over the doubles.
	 *
	 * @return PaymentScheduleController
	 */
	private function controller(): PaymentScheduleController {
		return new PaymentScheduleController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			overview: $this->overview,
			userSession: $this->session,
			caseAccess: $this->caseAccess,
		);
	}//end controller()
}//end class
