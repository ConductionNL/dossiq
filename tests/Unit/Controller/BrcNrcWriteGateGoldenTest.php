<?php

/**
 * Who may write to the decision and notification APIs.
 *
 * Golden over BrcController and NrcController before each shares its write
 * gate between its endpoints: every write (create, update, patch, destroy)
 * answers the JWT refusal unchanged, then refuses a consumer without the
 * endpoint's scope with the 403 permission body, and only then reaches the
 * store, with the exact arguments.
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

use OCA\Dossiq\Controller\BrcController;
use OCA\Dossiq\Controller\NrcController;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The decision and notification endpoints' refusals and answers.
 *
 * @covers \OCA\Dossiq\Controller\BrcController
 * @covers \OCA\Dossiq\Controller\NrcController
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class BrcNrcWriteGateGoldenTest extends TestCase {
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
	 * Every write endpoint of both controllers, with its component, scope and store call.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function writes(): array {
		return [
			'brc create' => ['brc', 'create', 'besluiten.aanmaken', 'handleCreate'],
			'brc update' => ['brc', 'update', 'besluiten.bijwerken', 'handleUpdate'],
			'brc patch' => ['brc', 'patch', 'besluiten.bijwerken', 'handleUpdate'],
			'brc destroy' => ['brc', 'destroy', 'besluiten.verwijderen', 'handleDestroy'],
			'nrc create' => ['nrc', 'create', 'notificaties.publiceren', 'handleCreate'],
			'nrc update' => ['nrc', 'update', 'notificaties.publiceren', 'handleUpdate'],
			'nrc patch' => ['nrc', 'patch', 'notificaties.publiceren', 'handleUpdate'],
			'nrc destroy' => ['nrc', 'destroy', 'notificaties.publiceren', 'handleDestroy'],
		];
	}//end writes()

	/**
	 * A JWT refusal is answered as it is, before the scope is asked.
	 *
	 * @param string $component The controller's component.
	 * @param string $endpoint  The write endpoint.
	 * @param string $scope     The scope it needs.
	 * @param string $store     The store call it would reach.
	 *
	 * @dataProvider writes
	 *
	 * @return void
	 */
	public function testAJwtRefusalIsAnsweredFirst(string $component, string $endpoint, string $scope, string $store): void {
		$refusal = new JSONResponse(data: ['detail' => 'no token'], statusCode: 401);
		$this->zgw->method('validateJwtAuth')->with($this->request)->willReturn($refusal);
		$this->zgw->expects(self::never())->method('consumerHasScope');
		$this->zgw->expects(self::never())->method($store);

		self::assertSame(expected: $refusal, actual: $this->call(component: $component, endpoint: $endpoint));
	}//end testAJwtRefusalIsAnsweredFirst()

	/**
	 * Without the endpoint's scope the write is refused with the permission body.
	 *
	 * @param string $component The controller's component.
	 * @param string $endpoint  The write endpoint.
	 * @param string $scope     The scope it needs.
	 * @param string $store     The store call it would reach.
	 *
	 * @dataProvider writes
	 *
	 * @return void
	 */
	public function testAConsumerWithoutTheScopeIsRefused(string $component, string $endpoint, string $scope, string $store): void {
		$this->zgw->method('validateJwtAuth')->willReturn(null);
		$this->zgw->expects(self::once())->method('consumerHasScope')->with($this->request, $component, $scope)->willReturn(false);
		$this->zgw->expects(self::never())->method($store);

		$response = $this->call(component: $component, endpoint: $endpoint);

		self::assertSame(
			expected: [
				403,
				[
					'type' => 'PermissionDenied',
					'code' => 'permission_denied',
					'title' => 'Insufficient scope.',
					'status' => 403,
					'detail' => 'Scope ' . $scope . ' is required for this operation.',
				],
			],
			actual: [$response->getStatus(), $response->getData()]
		);
	}//end testAConsumerWithoutTheScopeIsRefused()

	/**
	 * An allowed write reaches the store with the exact arguments, its answer passed through.
	 *
	 * @param string $component The controller's component.
	 * @param string $endpoint  The write endpoint.
	 * @param string $scope     The scope it needs.
	 * @param string $store     The store call it reaches.
	 *
	 * @dataProvider writes
	 *
	 * @return void
	 */
	public function testAnAllowedWriteReachesTheStore(string $component, string $endpoint, string $scope, string $store): void {
		$api = ['brc' => 'besluiten', 'nrc' => 'notificaties'][$component];
		$arguments = [
			'create' => [$this->request, $api, 'others'],
			'update' => [$this->request, $api, 'others', 'u-1', false],
			'patch' => [$this->request, $api, 'others', 'u-1', true],
			'destroy' => [$this->request, $api, 'others', 'u-1'],
		][$endpoint];
		$answer = new JSONResponse(data: ['url' => 'x'], statusCode: 200);
		$this->zgw->method('validateJwtAuth')->willReturn(null);
		$this->zgw->expects(self::once())->method('consumerHasScope')->with($this->request, $component, $scope)->willReturn(true);
		$this->zgw->expects(self::once())->method($store)->with(...$arguments)->willReturn($answer);

		self::assertSame(expected: $answer, actual: $this->call(component: $component, endpoint: $endpoint));
	}//end testAnAllowedWriteReachesTheStore()

	/**
	 * Call one write endpoint on a resource neither controller treats specially.
	 *
	 * @param string $component 'brc' or 'nrc'.
	 * @param string $endpoint  The endpoint.
	 *
	 * @return JSONResponse The answer.
	 */
	private function call(string $component, string $endpoint): JSONResponse {
		$controller = $this->controller(component: $component);
		if ($endpoint === 'create') {
			return $controller->create(resource: 'others');
		}

		return $controller->$endpoint(resource: 'others', uuid: 'u-1');
	}//end call()

	/**
	 * The controller over the doubles.
	 *
	 * @param string $component 'brc' or 'nrc'.
	 *
	 * @return BrcController|NrcController The controller.
	 */
	private function controller(string $component): BrcController|NrcController {
		if ($component === 'brc') {
			return new BrcController(
				appName: 'dossiq',
				request: $this->request,
				zgwService: $this->zgw,
				settingsService: $this->createMock(originalClassName: SettingsService::class)
			);
		}

		return new NrcController(appName: 'dossiq', request: $this->request, zgwService: $this->zgw);
	}//end controller()
}//end class
