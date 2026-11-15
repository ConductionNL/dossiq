<?php

/**
 * ConsultationController unit tests: delete and the two extension endpoints, behind a guard double.
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
 *
 * @spec openspec/changes/archive/2026-06-13-consultation-management/tasks.md#TASK-CN-04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ConsultationController;
use OCA\Dossiq\Kernel\Contracts\ConsultationAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\ConsultationServiceInterface;
use OCA\Dossiq\Service\Consultation\ConsultationAccess;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The guard's refusal is the answer; past it, the service's result or its failure message.
 *
 * @covers \OCA\Dossiq\Controller\ConsultationController
 * @uses   \OCA\Dossiq\Service\Consultation\ConsultationAccess
 */
class ConsultationControllerExtensionTest extends TestCase {

	/**
	 * The service double.
	 *
	 * @var ConsultationServiceInterface&MockObject
	 */
	private ConsultationServiceInterface $service;

	/**
	 * The guard double.
	 *
	 * @var ConsultationAccessGuardInterface&MockObject
	 */
	private ConsultationAccessGuardInterface $guard;

	/**
	 * The controller under test.
	 *
	 * @var ConsultationController
	 */
	private ConsultationController $controller;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(ConsultationServiceInterface::class);
		$this->guard = $this->createMock(ConsultationAccessGuardInterface::class);
		$this->controller = new ConsultationController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			consultationService: $this->service,
			accessGuard: $this->guard,
		);
	}//end setUp()

	/**
	 * The guard lets the caller through on c-1.
	 *
	 * @return void
	 */
	private function allow(): void {
		$this->guard->method('authorize')->with('c-1')
			->willReturn(new ConsultationAccess(consultation: ['id' => 'c-1']));
	}//end allow()

	/**
	 * The guard's own refusal is returned as is, for every verb, and the service is never reached.
	 *
	 * @return void
	 */
	public function testTheGuardsRefusalIsTheAnswer(): void {
		$refusal = new JSONResponse(['error' => 'Not allowed'], Http::STATUS_FORBIDDEN);
		$this->guard->method('authorize')->willReturn(new ConsultationAccess(error: $refusal));
		$this->guard->expects($this->never())->method('requestBody');
		$this->service->expects($this->never())->method('deleteConsultation');
		$this->service->expects($this->never())->method('requestExtension');
		$this->service->expects($this->never())->method('approveExtension');

		$this->assertSame($refusal, $this->controller->delete(id: 'c-1'));
		$this->assertSame($refusal, $this->controller->requestExtension(id: 'c-1'));
		$this->assertSame($refusal, $this->controller->approveExtension(id: 'c-1'));
	}//end testTheGuardsRefusalIsTheAnswer()

	/**
	 * A delete is a 204 with an empty body.
	 *
	 * @return void
	 */
	public function testDeleteIsNoContent(): void {
		$this->allow();
		$this->service->expects($this->once())->method('deleteConsultation')->with('c-1')->willReturn(true);

		$response = $this->controller->delete(id: 'c-1');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertSame([], $response->getData());
	}//end testDeleteIsNoContent()

	/**
	 * A delete that fails is a 400 carrying the message.
	 *
	 * @return void
	 */
	public function testAFailingDeleteIsABadRequest(): void {
		$this->allow();
		$this->service->method('deleteConsultation')->willThrowException(new RuntimeException('Already answered'));

		$response = $this->controller->delete(id: 'c-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Already answered'], $response->getData());
	}//end testAFailingDeleteIsABadRequest()

	/**
	 * The justification from the body reaches the service and its result is the answer.
	 *
	 * @return void
	 */
	public function testRequestExtensionPassesTheJustification(): void {
		$this->allow();
		$this->guard->method('requestBody')->willReturn(['justification' => 'Waiting on the soil survey']);
		$this->service->expects($this->once())->method('requestExtension')
			->with('c-1', 'Waiting on the soil survey')
			->willReturn(['id' => 'c-1', 'extensionRequested' => true]);

		$response = $this->controller->requestExtension(id: 'c-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'c-1', 'extensionRequested' => true], $response->getData());
	}//end testRequestExtensionPassesTheJustification()

	/**
	 * Without a justification an empty one is passed, and the service's refusal is a 400.
	 *
	 * @return void
	 */
	public function testRequestExtensionWithoutJustificationIsRefusedByTheService(): void {
		$this->allow();
		$this->guard->method('requestBody')->willReturn([]);
		$this->service->expects($this->once())->method('requestExtension')->with('c-1', '')
			->willThrowException(new RuntimeException('A justification is required'));

		$response = $this->controller->requestExtension(id: 'c-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A justification is required'], $response->getData());
	}//end testRequestExtensionWithoutJustificationIsRefusedByTheService()

	/**
	 * The new deadline from the body reaches the service and its result is the answer.
	 *
	 * @return void
	 */
	public function testApproveExtensionPassesTheNewDeadline(): void {
		$this->allow();
		$this->guard->method('requestBody')->willReturn(['newDeadline' => '2026-12-01']);
		$this->service->expects($this->once())->method('approveExtension')
			->with('c-1', '2026-12-01')
			->willReturn(['id' => 'c-1', 'deadline' => '2026-12-01']);

		$response = $this->controller->approveExtension(id: 'c-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'c-1', 'deadline' => '2026-12-01'], $response->getData());
	}//end testApproveExtensionPassesTheNewDeadline()

	/**
	 * An approval the service refuses is a 400 with its message.
	 *
	 * @return void
	 */
	public function testAFailingApprovalIsABadRequest(): void {
		$this->allow();
		$this->guard->method('requestBody')->willReturn([]);
		$this->service->expects($this->once())->method('approveExtension')->with('c-1', '')
			->willThrowException(new RuntimeException('No extension was requested'));

		$response = $this->controller->approveExtension(id: 'c-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'No extension was requested'], $response->getData());
	}//end testAFailingApprovalIsABadRequest()
}//end class
