<?php

/**
 * GuardsZgwRequests — the JWT, scope and missing-resource guards of the ZGW controllers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\GuardsZgwRequests;
use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * The guard trait, through a minimal host class.
 *
 * @covers \OCA\Dossiq\Controller\Support\GuardsZgwRequests
 */
final class GuardsZgwRequestsTest extends TestCase {

	/**
	 * The ZGW service the host delegates to.
	 *
	 * @var ZgwServiceInterface&MockObject
	 */
	private ZgwServiceInterface&MockObject $zgwService;

	/**
	 * Set up the mocked service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->zgwService = $this->createMock(ZgwServiceInterface::class);
	}//end setUp()

	/**
	 * A host composing the trait, exposing its private methods.
	 *
	 * @return object The host.
	 */
	private function host(): object {
		return new class($this->zgwService, $this->createMock(IRequest::class)) {
			use GuardsZgwRequests;

			private const ZGW_API = 'documenten';

			/**
			 * Constructor.
			 *
			 * @param ZgwServiceInterface $zgwService The ZGW service.
			 * @param IRequest            $request    The request.
			 */
			public function __construct(
				private readonly ZgwServiceInterface $zgwService,
				private readonly IRequest $request,
			) {
			}//end __construct()

			/**
			 * The scope refusal the controllers return.
			 *
			 * @param string $scope The missing scope.
			 *
			 * @return JSONResponse
			 */
			protected function scopeDeniedResponse(string $scope): JSONResponse {
				return new JSONResponse(data: ['scope' => $scope], statusCode: Http::STATUS_FORBIDDEN);
			}//end scopeDeniedResponse()

			/**
			 * Call a private trait method.
			 *
			 * @param string       $method The method name.
			 * @param array<mixed> $args   The named arguments.
			 *
			 * @return mixed
			 */
			public function call(string $method, array $args = []): mixed {
				return $this->$method(...$args);
			}//end call()
		};
	}//end host()

	/**
	 * An invalid token is refused before the scope is looked at.
	 *
	 * @return void
	 */
	public function testJwtRefusalComesBeforeTheScope(): void {
		$denied = new JSONResponse(data: [], statusCode: Http::STATUS_UNAUTHORIZED);
		$this->zgwService->method('validateJwtAuth')->willReturn($denied);
		$this->zgwService->expects($this->never())->method('consumerHasScope');

		self::assertSame($denied, $this->host()->call('scopedWriteRefusal', ['component' => 'drc', 'scope' => 'documenten.aanmaken']));
	}//end testJwtRefusalComesBeforeTheScope()

	/**
	 * A valid token without the scope gets the scope refusal; with it, null.
	 *
	 * @return void
	 */
	public function testScopeDecidesForAValidToken(): void {
		$this->zgwService->method('validateJwtAuth')->willReturn(null);
		$this->zgwService->method('consumerHasScope')->willReturnOnConsecutiveCalls(false, true);
		$host = $this->host();

		$refusal = $host->call('scopedWriteRefusal', ['component' => 'drc', 'scope' => 'documenten.aanmaken']);
		self::assertSame(['scope' => 'documenten.aanmaken'], $refusal->getData());
		self::assertNull($host->call('scopedWriteRefusal', ['component' => 'drc', 'scope' => 'documenten.aanmaken']));
	}//end testScopeDecidesForAValidToken()

	/**
	 * The audit-trail endpoints delegate behind the JWT check.
	 *
	 * @return void
	 */
	public function testAudittrailDelegatesForAValidToken(): void {
		$list = new JSONResponse(data: ['list']);
		$entry = new JSONResponse(data: ['entry']);
		$this->zgwService->method('validateJwtAuth')->willReturn(null);
		$this->zgwService->expects($this->once())->method('handleAudittrailIndex')
			->with($this->anything(), 'documenten', 'enkelvoudiginformatieobjecten', 'u1')->willReturn($list);
		$this->zgwService->expects($this->once())->method('handleAudittrailShow')
			->with($this->anything(), 'documenten', 'enkelvoudiginformatieobjecten', 'u1', 'a1')->willReturn($entry);
		$host = $this->host();

		self::assertSame($list, $host->call('audittrailList', ['resource' => 'enkelvoudiginformatieobjecten', 'uuid' => 'u1']));
		self::assertSame($entry, $host->call('audittrailEntry', ['resource' => 'enkelvoudiginformatieobjecten', 'uuid' => 'u1', 'auditUuid' => 'a1']));
	}//end testAudittrailDelegatesForAValidToken()

	/**
	 * A resource the register cannot find is a 404; without an object service nothing is refused.
	 *
	 * @return void
	 */
	public function testMissingResourceIsNotFound(): void {
		$objectService = new class {
			/**
			 * Always fail to find.
			 *
			 * @param string $uuid     The UUID.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return never
			 */
			public function find(string $uuid, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): never {
				$register = $options?->register;
				$schema = $options?->schema;
				throw new \RuntimeException('gone');
			}//end find()
		};
		$this->zgwService->method('getObjectService')->willReturnOnConsecutiveCalls($objectService, null);
		$this->zgwService->method('loadMappingConfig')->willReturn(['sourceRegister' => 'r', 'sourceSchema' => 's']);
		$host = $this->host();

		$refusal = $host->call('missingResourceRefusal', ['resource' => 'x', 'uuid' => 'u1', 'detail' => 'Not found']);
		self::assertSame(Http::STATUS_NOT_FOUND, $refusal->getStatus());
		self::assertSame(['detail' => 'Not found'], $refusal->getData());
		self::assertNull($host->call('missingResourceRefusal', ['resource' => 'x', 'uuid' => 'u1', 'detail' => 'Not found']));
	}//end testMissingResourceIsNotFound()
}//end class
