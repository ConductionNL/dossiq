<?php

/**
 * NrcController unit tests: the read endpoints authenticate first and then delegate to the ZGW service.
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
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\NrcController;
use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Each read verb: a JWT refusal short-circuits, otherwise the service's answer for the notificaties API.
 *
 * @covers \OCA\Dossiq\Controller\NrcController
 */
class NrcControllerReadTest extends TestCase {

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The ZGW service double.
	 *
	 * @var ZgwServiceInterface&MockObject
	 */
	private ZgwServiceInterface $zgw;

	/**
	 * The controller under test.
	 *
	 * @var NrcController
	 */
	private NrcController $controller;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->zgw = $this->createMock(ZgwServiceInterface::class);
		$this->controller = new NrcController(appName: 'dossiq', request: $this->request, zgwService: $this->zgw);
	}//end setUp()

	/**
	 * A JWT refusal is the answer of every read verb and nothing is read.
	 *
	 * @return void
	 */
	public function testAJwtRefusalShortCircuitsEveryRead(): void {
		$refusal = new JSONResponse(['detail' => 'Invalid token'], 403);
		$this->zgw->expects($this->exactly(4))->method('validateJwtAuth')->with($this->request)->willReturn($refusal);
		$this->zgw->expects($this->never())->method('handleIndex');
		$this->zgw->expects($this->never())->method('handleShow');
		$this->zgw->expects($this->never())->method('handleAudittrailIndex');
		$this->zgw->expects($this->never())->method('handleAudittrailShow');

		$this->assertSame($refusal, $this->controller->index(resource: 'abonnement'));
		$this->assertSame($refusal, $this->controller->show(resource: 'abonnement', uuid: 'u-1'));
		$this->assertSame($refusal, $this->controller->audittrailIndex(resource: 'abonnement', uuid: 'u-1'));
		$this->assertSame($refusal, $this->controller->audittrailShow(resource: 'abonnement', uuid: 'u-1', auditUuid: 'a-1'));
	}//end testAJwtRefusalShortCircuitsEveryRead()

	/**
	 * The index is the service's index of the notificaties API.
	 *
	 * @return void
	 */
	public function testIndexDelegatesToTheNotificatiesApi(): void {
		$answer = new JSONResponse(['count' => 0, 'results' => []]);
		$this->zgw->method('validateJwtAuth')->willReturn(null);
		$this->zgw->expects($this->once())->method('handleIndex')
			->with($this->request, 'notificaties', 'kanaal')
			->willReturn($answer);

		$this->assertSame($answer, $this->controller->index(resource: 'kanaal'));
	}//end testIndexDelegatesToTheNotificatiesApi()

	/**
	 * The show is the service's show of the notificaties API.
	 *
	 * @return void
	 */
	public function testShowDelegatesToTheNotificatiesApi(): void {
		$answer = new JSONResponse(['uuid' => 'u-1']);
		$this->zgw->method('validateJwtAuth')->willReturn(null);
		$this->zgw->expects($this->once())->method('handleShow')
			->with($this->request, 'notificaties', 'kanaal', 'u-1')
			->willReturn($answer);

		$this->assertSame($answer, $this->controller->show(resource: 'kanaal', uuid: 'u-1'));
	}//end testShowDelegatesToTheNotificatiesApi()

	/**
	 * Both audit trail reads delegate with the object and audit ids.
	 *
	 * @return void
	 */
	public function testTheAuditTrailReadsDelegate(): void {
		$list = new JSONResponse([['uuid' => 'a-1']]);
		$one = new JSONResponse(['uuid' => 'a-1']);
		$this->zgw->method('validateJwtAuth')->willReturn(null);
		$this->zgw->expects($this->once())->method('handleAudittrailIndex')
			->with($this->request, 'notificaties', 'abonnement', 'u-1')
			->willReturn($list);
		$this->zgw->expects($this->once())->method('handleAudittrailShow')
			->with($this->request, 'notificaties', 'abonnement', 'u-1', 'a-1')
			->willReturn($one);

		$this->assertSame($list, $this->controller->audittrailIndex(resource: 'abonnement', uuid: 'u-1'));
		$this->assertSame($one, $this->controller->audittrailShow(resource: 'abonnement', uuid: 'u-1', auditUuid: 'a-1'));
	}//end testTheAuditTrailReadsDelegate()
}//end class
