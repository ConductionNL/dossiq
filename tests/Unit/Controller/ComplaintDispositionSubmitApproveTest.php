<?php

/**
 * ComplaintDispositionController: submitting and approving a disposition.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ComplaintDispositionController;
use OCA\Dossiq\Kernel\Contracts\ComplaintAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\ComplaintServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DispositionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Without approval a disposition closes the complaint at once; with approval it waits,
 * and only a coordinator's approval closes it.
 *
 * @covers \OCA\Dossiq\Controller\ComplaintDispositionController
 */
class ComplaintDispositionSubmitApproveTest extends TestCase {

	/**
	 * The complaint service.
	 *
	 * @var ComplaintServiceInterface&MockObject
	 */
	private $complaints;

	/**
	 * The disposition service.
	 *
	 * @var DispositionServiceInterface&MockObject
	 */
	private $dispositions;

	/**
	 * The settings service.
	 *
	 * @var SettingsServiceInterface&MockObject
	 */
	private $settings;

	/**
	 * The access guard.
	 *
	 * @var ComplaintAccessGuardInterface&MockObject
	 */
	private $guard;

	/**
	 * Fresh boundaries; the guard knows user-1 and reads the given body.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->complaints = $this->createMock(ComplaintServiceInterface::class);
		$this->dispositions = $this->createMock(DispositionServiceInterface::class);
		$this->settings = $this->createMock(SettingsServiceInterface::class);
		$this->guard = $this->createMock(ComplaintAccessGuardInterface::class);
		$this->guard->method('parseBody')->willReturn(['decision' => 'gegrond']);
		$this->guard->method('notAuthenticated')->willReturn(new JSONResponse(['error' => 'login'], Http::STATUS_UNAUTHORIZED));
	}//end setUp()

	/**
	 * The controller over the mocked boundaries.
	 *
	 * @param string $uid The signed-in user, '' for nobody.
	 *
	 * @return ComplaintDispositionController
	 */
	private function controller(string $uid='user-1'): ComplaintDispositionController {
		$this->guard->method('currentUid')->willReturn($uid);

		return new ComplaintDispositionController(
			'dossiq',
			$this->createMock(IRequest::class),
			$this->complaints,
			$this->dispositions,
			$this->settings,
			$this->guard
		);
	}//end controller()

	/**
	 * Without approval the disposition is submitted and the complaint is handled.
	 *
	 * @return void
	 */
	public function testWithoutApprovalTheComplaintIsHandledAtOnce(): void {
		$this->complaints->method('getComplaint')->willReturn(['id' => 'c-1']);
		$this->guard->expects($this->once())->method('authorizeMutation')->with(['id' => 'c-1'], 'user-1');
		$this->settings->method('getConfigValue')->with('complaint_require_approval')->willReturn('no');
		$this->dispositions->expects($this->never())->method('submitDispositionForApproval');
		$this->dispositions->expects($this->once())->method('submitDisposition')
			->with('c-1', ['decision' => 'gegrond'])->willReturn(['id' => 'd-1']);
		$this->complaints->expects($this->once())->method('transitionStatus')->with('c-1', 'handled');

		$response = $this->controller()->submitDisposition('c-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'd-1'], $response->getData());
	}//end testWithoutApprovalTheComplaintIsHandledAtOnce()

	/**
	 * Each spelling of "on" sends the disposition for approval and leaves the status alone.
	 *
	 * @return void
	 */
	public function testWithApprovalTheDispositionWaits(): void {
		$this->complaints->method('getComplaint')->willReturn(['id' => 'c-1']);
		$this->settings->method('getConfigValue')->willReturnOnConsecutiveCalls('1', 'TRUE', 'Yes');
		$this->dispositions->expects($this->exactly(3))->method('submitDispositionForApproval')
			->with('c-1', ['decision' => 'gegrond'])->willReturn(['id' => 'd-1', 'status' => 'pending']);
		$this->dispositions->expects($this->never())->method('submitDisposition');
		$this->complaints->expects($this->never())->method('transitionStatus');
		$controller = $this->controller();

		for ($i = 0; $i < 3; $i++) {
			$response = $controller->submitDisposition('c-1');
			$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
			$this->assertSame(['id' => 'd-1', 'status' => 'pending'], $response->getData());
		}
	}//end testWithApprovalTheDispositionWaits()

	/**
	 * A missing complaint is 404, a refused submission 400, and nobody signed in is the guard's answer.
	 *
	 * @return void
	 */
	public function testSubmitRefusals(): void {
		$this->complaints->method('getComplaint')->willReturnMap([['gone', null], ['c-1', ['id' => 'c-1']]]);
		$this->settings->method('getConfigValue')->willReturn('');
		$this->dispositions->method('submitDisposition')->willThrowException(new \RuntimeException('Besluit ontbreekt'));
		$this->complaints->expects($this->never())->method('transitionStatus');
		$controller = $this->controller();

		$missing = $controller->submitDisposition('gone');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['error' => 'Complaint not found'], $missing->getData());

		$refused = $controller->submitDisposition('c-1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['error' => 'Besluit ontbreekt'], $refused->getData());
	}//end testSubmitRefusals()

	/**
	 * Nobody signed in gets the guard's 401 from every action, and nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutASessionNothingIsRead(): void {
		$this->complaints->expects($this->never())->method('getComplaint');
		$this->dispositions->expects($this->never())->method($this->anything());
		$controller = $this->controller('');

		foreach ([$controller->submitDisposition('c-1'), $controller->approveDisposition('c-1'), $controller->getDisposition('c-1'), $controller->generateLetter('c-1')] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		}
	}//end testWithoutASessionNothingIsRead()

	/**
	 * A coordinator approves the disposition by id, falling back to uuid, and the complaint is handled.
	 *
	 * @return void
	 */
	public function testApprovalByACoordinatorHandlesTheComplaint(): void {
		$this->guard->expects($this->exactly(2))->method('requireCoordinator')->with('user-1');
		$this->dispositions->method('getDispositionForComplaint')
			->willReturnOnConsecutiveCalls(['id' => 'd-1'], ['uuid' => 'u-2']);
		$approved = [];
		$this->dispositions->method('approveDisposition')->willReturnCallback(
			static function (string $id, string $approver) use (&$approved): array {
				$approved[] = $id;
				return ['id' => $id, 'approvedBy' => $approver];
			}
		);
		$this->complaints->expects($this->exactly(2))->method('transitionStatus')->with('c-1', 'handled');
		$controller = $this->controller();

		$first = $controller->approveDisposition('c-1');
		$this->assertSame(Http::STATUS_OK, $first->getStatus());
		$this->assertSame(['id' => 'd-1', 'approvedBy' => 'user-1'], $first->getData());
		$controller->approveDisposition('c-1');

		$this->assertSame(['d-1', 'u-2'], $approved);
	}//end testApprovalByACoordinatorHandlesTheComplaint()

	/**
	 * No disposition is 404; a refused approval is 400; neither handles the complaint.
	 *
	 * @return void
	 */
	public function testApprovalRefusals(): void {
		$this->dispositions->method('getDispositionForComplaint')->willReturnOnConsecutiveCalls(null, ['id' => 'd-1']);
		$this->dispositions->method('approveDisposition')->willThrowException(new \RuntimeException('Al goedgekeurd'));
		$this->complaints->expects($this->never())->method('transitionStatus');
		$controller = $this->controller();

		$missing = $controller->approveDisposition('c-1');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['error' => 'No disposition found for complaint'], $missing->getData());

		$refused = $controller->approveDisposition('c-1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['error' => 'Al goedgekeurd'], $refused->getData());
	}//end testApprovalRefusals()
}//end class
