<?php

/**
 * Tests for the ZGW middleware's scope decisions, stated as explicit assertions.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Middleware;

use OCA\Dossiq\Controller\ZgwController;
use OCA\Dossiq\Middleware\ZgwAuthException;
use OCA\Dossiq\Middleware\ZgwAuthMiddleware;
use OCA\Dossiq\Service\Zgw\ApiCredentialResolver;
use OCA\Dossiq\Service\ZgwAuthValidationException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which method needs which scope, which resource prefix a component accepts,
 * and that the caller is authenticated even when its consumer is a superuser.
 *
 * @covers \OCA\Dossiq\Middleware\ZgwAuthMiddleware
 * @covers \OCA\Dossiq\Middleware\ZgwAuthException
 * @uses   \OCA\Dossiq\Service\Zgw\ApiCredentialResolver
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class ZgwAuthMiddlewareScopeTest extends TestCase {

	/**
	 * The credential resolver double.
	 *
	 * @var ApiCredentialResolver&MockObject
	 */
	private ApiCredentialResolver&MockObject $resolver;

	/**
	 * The middleware for one request whose caller resolves to a consumer carrying $config.
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $group  The ZGW API group in the URI.
	 * @param array<string, mixed> $config The consumer's authorization configuration.
	 *
	 * @return ZgwAuthMiddleware
	 */
	private function middleware(string $method, string $group, array $config): ZgwAuthMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($method);
		$request->method('getRequestUri')->willReturn('/index.php/apps/dossiq/api/zgw/' . $group . '/v1/items');

		$consumer = new class ($config) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $config The configuration.
			 */
			public function __construct(private readonly array $config) {
			}

			/**
			 * The configuration.
			 *
			 * @return array<string, mixed>
			 */
			public function getAuthorizationConfiguration(): array {
				return $this->config;
			}
		};

		$this->resolver = $this->createMock(ApiCredentialResolver::class);
		$this->resolver->method('resolve')->with($request)->willReturn($consumer);

		return new ZgwAuthMiddleware($request, $this->resolver, $this->createMock(LoggerInterface::class));
	}//end middleware()

	/**
	 * Run beforeController and answer null when allowed, or the refusal.
	 *
	 * @param ZgwAuthMiddleware $middleware The middleware.
	 *
	 * @return ZgwAuthException|null
	 */
	private function refusal(ZgwAuthMiddleware $middleware): ?ZgwAuthException {
		try {
			$middleware->beforeController($this->createMock(ZgwController::class), 'index');
		} catch (ZgwAuthException $e) {
			return $e;
		}

		return null;
	}//end refusal()

	/**
	 * Each method needs its own action scope.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function methodScopes(): array {
		return [
			'GET reads' => ['GET', 'lezen'],
			'POST creates' => ['POST', 'aanmaken'],
			'PUT updates' => ['PUT', 'bijwerken'],
			'PATCH updates' => ['PATCH', 'bijwerken'],
			'DELETE deletes' => ['DELETE', 'verwijderen'],
		];
	}//end methodScopes()

	/**
	 * A method is allowed by exactly its own action scope, and refused naming it otherwise.
	 *
	 * @dataProvider methodScopes
	 *
	 * @param string $method The HTTP method.
	 * @param string $action The scope action it needs.
	 *
	 * @return void
	 */
	public function testEachMethodNeedsItsOwnAction(string $method, string $action): void {
		$granted = ['scopes' => [['component' => 'zrc', 'scopes' => ['zaken.' . $action]]]];
		$this->assertNull($this->refusal($this->middleware(method: $method, group: 'zaken', config: $granted)));

		$others = array_values(array_diff(['zaken.lezen', 'zaken.aanmaken', 'zaken.bijwerken', 'zaken.verwijderen'], ['zaken.' . $action]));
		$refusal = $this->refusal(
			$this->middleware(method: $method, group: 'zaken', config: ['scopes' => [['component' => 'zrc', 'scopes' => $others]]])
		);

		$this->assertInstanceOf(ZgwAuthException::class, $refusal);
		$this->assertSame(403, $refusal->getStatusCode());
		$this->assertSame("Scope 'zrc." . $action . "' is required for this operation", $refusal->getMessage());
	}//end testEachMethodNeedsItsOwnAction()

	/**
	 * Each API group maps to its component and that component's resource prefix.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function componentPrefixes(): array {
		return [
			'zaken' => ['zaken', 'zrc', 'zaken'],
			'catalogi' => ['catalogi', 'ztc', 'catalogi'],
			'besluiten' => ['besluiten', 'brc', 'besluiten'],
			'documenten' => ['documenten', 'drc', 'documenten'],
			'notificaties' => ['notificaties', 'nrc', 'notificaties'],
			'autorisaties' => ['autorisaties', 'ac', 'autorisaties'],
		];
	}//end componentPrefixes()

	/**
	 * A grant covers its own component's resource prefix and not another's.
	 *
	 * @dataProvider componentPrefixes
	 *
	 * @param string $group     The API group.
	 * @param string $component The component it maps to.
	 * @param string $prefix    The resource prefix that component accepts.
	 *
	 * @return void
	 */
	public function testAGrantCoversOnlyItsOwnResourcePrefix(string $group, string $component, string $prefix): void {
		$this->assertNull(
			$this->refusal(
				$this->middleware(method: 'GET', group: $group, config: ['scopes' => [['component' => $component, 'scopes' => [$prefix . '.lezen']]]])
			)
		);

		$foreign = ($prefix === 'zaken' ? 'besluiten' : 'zaken');
		$refusal = $this->refusal(
			$this->middleware(method: 'GET', group: $group, config: ['scopes' => [['component' => $component, 'scopes' => [$foreign . '.lezen']]]])
		);
		$this->assertSame("Scope '" . $component . ".lezen' is required for this operation", $refusal?->getMessage());
	}//end testAGrantCoversOnlyItsOwnResourcePrefix()

	/**
	 * A grant with the right scope on the wrong component does not cover.
	 *
	 * @return void
	 */
	public function testTheRightScopeOnTheWrongComponentDoesNotCover(): void {
		$refusal = $this->refusal(
			$this->middleware(method: 'GET', group: 'zaken', config: ['scopes' => [['component' => 'brc', 'scopes' => ['zaken.lezen']], ['scopes' => ['zaken.lezen']]]])
		);

		$this->assertSame("Scope 'zrc.lezen' is required for this operation", $refusal?->getMessage());
	}//end testTheRightScopeOnTheWrongComponentDoesNotCover()

	/**
	 * A superuser skips the scope check but never authentication: a credential
	 * that does not resolve is refused with 401 whatever the consumer may do.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-api-access-with-keys-and-certificates/specs/public-api-access/spec.md#requirement-a-calling-system-is-an-openregister-consumer-req-paa-01
	 */
	public function testASuperuserIsStillAuthenticated(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('DELETE');
		$request->method('getRequestUri')->willReturn('/index.php/apps/dossiq/api/zgw/onbekend/v1/items');
		$resolver = $this->createMock(ApiCredentialResolver::class);
		$resolver->expects($this->once())->method('resolve')->willThrowException(new ZgwAuthValidationException('The token does not match the shared secret'));

		$refusal = $this->refusal(new ZgwAuthMiddleware($request, $resolver, $this->createMock(LoggerInterface::class)));

		$this->assertSame(401, $refusal?->getStatusCode());
		$this->assertSame('The token is not valid.', $refusal?->getMessage());
	}//end testASuperuserIsStillAuthenticated()

	/**
	 * A superuser consumer that resolves passes any method on any group.
	 *
	 * @return void
	 */
	public function testAResolvedSuperuserSkipsTheScopeCheck(): void {
		$this->assertNull($this->refusal($this->middleware(method: 'DELETE', group: 'onbekend', config: ['superuser' => true])));
	}//end testAResolvedSuperuserSkipsTheScopeCheck()

	/**
	 * The refusal's own status code is what afterException answers with.
	 *
	 * @return void
	 */
	public function testAfterExceptionAnswersWithTheRefusalsStatus(): void {
		$middleware = $this->middleware(method: 'GET', group: 'zaken', config: []);

		$response = $middleware->afterException(null, 'index', new ZgwAuthException(message: 'The token has expired.', statusCode: 401));

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(401, $response->getData()['status']);
		$this->assertSame('The token has expired.', $response->getData()['detail']);
		$this->assertSame('not_authenticated', $response->getData()['code']);

		$forbidden = $middleware->afterException(null, 'index', new ZgwAuthException(message: 'no scope', statusCode: 403));
		$this->assertSame(403, $forbidden->getStatus());
		$this->assertSame('permission_denied', $forbidden->getData()['code']);
		$this->assertSame(403, (new ZgwAuthException(message: 'x'))->getStatusCode());
	}//end testAfterExceptionAnswersWithTheRefusalsStatus()
}//end class
