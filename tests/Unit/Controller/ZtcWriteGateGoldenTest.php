<?php

/**
 * Who may write to the catalogue API, and what a read or write answers.
 *
 * Golden over ZtcController before its gates and its response shaping are
 * shared between the endpoints: every write (create, update, patch, destroy)
 * answers the JWT refusal unchanged, then refuses a consumer without the
 * catalogi.schrijven scope with the 403 permission body, and only then
 * reaches the store, with the exact arguments. Show, update and patch shape a
 * successful zaaktype or besluittype answer; anything else passes through.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ZtcController;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue endpoints' refusals and answers.
 *
 * @covers \OCA\Dossiq\Controller\ZtcController
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 * @uses \OCA\Dossiq\Controller\ZgwController
 * @uses \OCA\Dossiq\Service\Zgw\ZtcCrossReferenceEnricher
 * @uses \OCA\Dossiq\Service\Zgw\ZtcRelatedTypeLookup
 * @uses \OCA\Dossiq\Service\Zgw\ZtcUrlValidityFilter
 */
class ZtcWriteGateGoldenTest extends TestCase {
	/**
	 * The request every call carries.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The ZGW service double.
	 *
	 * @var ZgwService&MockObject
	 */
	private ZgwService $zgw;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->zgw = $this->createMock(originalClassName: ZgwService::class);
	}//end setUp()

	/**
	 * Every write endpoint, with the store call it reaches.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function writes(): array {
		return [
			'create' => ['create', 'handleCreate'],
			'update' => ['update', 'handleUpdate'],
			'patch' => ['patch', 'handleUpdate'],
			'destroy' => ['destroy', 'handleDestroy'],
		];
	}//end writes()

	/**
	 * A JWT refusal is answered as it is, before the scope is asked.
	 *
	 * @param string $endpoint The write endpoint.
	 * @param string $store    The store call it would reach.
	 *
	 * @dataProvider writes
	 *
	 * @return void
	 */
	public function testAJwtRefusalIsAnsweredFirst(string $endpoint, string $store): void {
		$refusal = new JSONResponse(data: ['detail' => 'no token'], statusCode: 401);
		$this->zgw->method('validateJwtAuth')->with($this->request)->willReturn($refusal);
		$this->zgw->expects(self::never())->method('consumerHasScope');
		$this->zgw->expects(self::never())->method($store);

		self::assertSame(expected: $refusal, actual: $this->call(endpoint: $endpoint));
	}//end testAJwtRefusalIsAnsweredFirst()

	/**
	 * Without catalogi.schrijven the write is refused with the permission body.
	 *
	 * @param string $endpoint The write endpoint.
	 * @param string $store    The store call it would reach.
	 *
	 * @dataProvider writes
	 *
	 * @return void
	 */
	public function testAConsumerWithoutTheWriteScopeIsRefused(string $endpoint, string $store): void {
		$this->zgw->method('validateJwtAuth')->willReturn(null);
		$this->zgw->expects(self::once())->method('consumerHasScope')->with($this->request, 'ztc', 'catalogi.schrijven')->willReturn(false);
		$this->zgw->expects(self::never())->method($store);

		$response = $this->call(endpoint: $endpoint);

		self::assertSame(expected: 403, actual: $response->getStatus());
		self::assertSame(
			expected: [
				'type' => 'PermissionDenied',
				'code' => 'permission_denied',
				'title' => 'Insufficient scope.',
				'status' => 403,
				'detail' => 'Scope catalogi.schrijven is required for this operation.',
			],
			actual: $response->getData()
		);
	}//end testAConsumerWithoutTheWriteScopeIsRefused()

	/**
	 * An allowed update reaches the store as a full write, its answer passed through.
	 *
	 * @return void
	 */
	public function testAnAllowedUpdateIsAFullWrite(): void {
		$this->allow();
		$answer = new JSONResponse(data: ['url' => 'x'], statusCode: 200);
		$this->zgw->expects(self::once())->method('handleUpdate')->with($this->request, 'catalogi', 'statustypen', 'u-1', false, null)->willReturn($answer);

		self::assertSame(expected: $answer, actual: $this->controller()->update(resource: 'statustypen', uuid: 'u-1'));
	}//end testAnAllowedUpdateIsAFullWrite()

	/**
	 * An allowed patch reaches the store as a partial write, its answer passed through.
	 *
	 * @return void
	 */
	public function testAnAllowedPatchIsAPartialWrite(): void {
		$this->allow();
		$answer = new JSONResponse(data: ['url' => 'x'], statusCode: 200);
		$this->zgw->expects(self::once())->method('handleUpdate')->with($this->request, 'catalogi', 'statustypen', 'u-1', true, null)->willReturn($answer);

		self::assertSame(expected: $answer, actual: $this->controller()->patch(resource: 'statustypen', uuid: 'u-1'));
	}//end testAnAllowedPatchIsAPartialWrite()

	/**
	 * An allowed destroy reaches the store with the resource and uuid.
	 *
	 * @return void
	 */
	public function testAnAllowedDestroyReachesTheStore(): void {
		$this->allow();
		$answer = new JSONResponse(data: [], statusCode: 204);
		$this->zgw->expects(self::once())->method('handleDestroy')->with($this->request, 'catalogi', 'statustypen', 'u-1', null)->willReturn($answer);

		self::assertSame(expected: $answer, actual: $this->controller()->destroy(resource: 'statustypen', uuid: 'u-1'));
	}//end testAnAllowedDestroyReachesTheStore()

	/**
	 * Show, update and patch shape a successful zaaktype answer, and pass anything else through.
	 *
	 * @return void
	 */
	public function testOnlyASuccessfulTypeAnswerIsShaped(): void {
		$this->allow();
		$typeOk = new JSONResponse(data: ['url' => 'zt', 'omschrijving' => 'A'], statusCode: 200);
		$typeMissing = new JSONResponse(data: ['detail' => 'Not found'], statusCode: 404);
		$otherOk = new JSONResponse(data: ['url' => 'st'], statusCode: 200);
		$this->zgw->method('handleShow')->willReturnOnConsecutiveCalls($typeOk, $typeMissing, $otherOk);
		$this->zgw->method('handleUpdate')->willReturnOnConsecutiveCalls($typeOk, $typeOk, $typeMissing);

		$controller = $this->controller();
		$shaped = [
			$controller->show(resource: 'zaaktypen', uuid: 'u-1'),
			$controller->update(resource: 'zaaktypen', uuid: 'u-1'),
			$controller->patch(resource: 'besluittypen', uuid: 'u-1'),
		];
		foreach ($shaped as $response) {
			self::assertNotSame(expected: $typeOk, actual: $response);
			self::assertSame(expected: [200, ['url' => 'zt', 'omschrijving' => 'A']], actual: [$response->getStatus(), $response->getData()]);
		}

		self::assertSame(expected: $typeMissing, actual: $controller->show(resource: 'zaaktypen', uuid: 'u-1'));
		self::assertSame(expected: $otherOk, actual: $controller->show(resource: 'statustypen', uuid: 'u-1'));
		self::assertSame(expected: $typeMissing, actual: $controller->patch(resource: 'zaaktypen', uuid: 'u-1'));
	}//end testOnlyASuccessfulTypeAnswerIsShaped()

	/**
	 * A consumer that passes the JWT and holds the write scope.
	 *
	 * @return void
	 */
	private function allow(): void {
		$this->zgw->method('validateJwtAuth')->willReturn(null);
		$this->zgw->method('consumerHasScope')->willReturn(true);
	}//end allow()

	/**
	 * Call one write endpoint on a zaaktype-less resource.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return JSONResponse The answer.
	 */
	private function call(string $endpoint): JSONResponse {
		$controller = $this->controller();
		if ($endpoint === 'create') {
			return $controller->create(resource: 'statustypen');
		}

		return $controller->$endpoint(resource: 'statustypen', uuid: 'u-1');
	}//end call()

	/**
	 * The controller over the doubles.
	 *
	 * @return ZtcController The controller.
	 */
	private function controller(): ZtcController {
		return new ZtcController(appName: 'dossiq', request: $this->request, zgwService: $this->zgw);
	}//end controller()
}//end class
