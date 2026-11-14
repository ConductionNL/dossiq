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
use OCA\Dossiq\Service\ZgwJwtValidator;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Which method needs which scope, which resource prefix a component accepts,
 * and that the signature is checked even for a superuser.
 *
 * @covers \OCA\Dossiq\Middleware\ZgwAuthMiddleware
 * @covers \OCA\Dossiq\Middleware\ZgwAuthException
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class ZgwAuthMiddlewareScopeTest extends TestCase {

	/**
	 * The signature validator double.
	 *
	 * @var ZgwJwtValidator&MockObject
	 */
	private ZgwJwtValidator&MockObject $validator;

	/**
	 * The issuers the consumer mapper was asked about.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $lookups = [];

	/**
	 * A bearer header whose payload names the issuer.
	 *
	 * @param string $issuer The issuer.
	 *
	 * @return string
	 */
	private static function bearer(string $issuer): string {
		return 'Bearer eyJhbGciOiJIUzI1NiJ9.' . base64_encode((string)json_encode(['iss' => $issuer])) . '.c2ln';
	}//end bearer()

	/**
	 * The middleware for one request by issuer app-1, whose consumer carries $config.
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $group  The ZGW API group in the URI.
	 * @param array<string, mixed> $config The consumer's authorization configuration.
	 *
	 * @return ZgwAuthMiddleware
	 */
	private function middleware(string $method, string $group, array $config): ZgwAuthMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn(self::bearer(issuer: 'app-1'));
		$request->method('getMethod')->willReturn($method);
		$request->method('getRequestUri')->willReturn('/index.php/apps/dossiq/api/zgw/' . $group . '/v1/items');

		$this->validator = $this->createMock(ZgwJwtValidator::class);

		$middleware = new ZgwAuthMiddleware($request, $this->validator, $this->createMock(LoggerInterface::class));

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

		$mapper = new class ($consumer, $this) {
			/**
			 * Constructor.
			 *
			 * @param object                     $consumer The consumer to answer.
			 * @param ZgwAuthMiddlewareScopeTest $test     The test keeping the lookups.
			 */
			public function __construct(private readonly object $consumer, private readonly ZgwAuthMiddlewareScopeTest $test) {
			}

			/**
			 * Record the filters and answer the consumer.
			 *
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return list<object>
			 */
			public function findAll(array $filters = []): array {
				$this->test->lookups[] = $filters;

				return [$this->consumer];
			}
		};

		(new ReflectionProperty(ZgwAuthMiddleware::class, 'consumerMapper'))->setValue($middleware, $mapper);

		return $middleware;
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
	 * The validator sees the whole header and the consumer is looked up by the token's issuer.
	 *
	 * @return void
	 */
	public function testTheIssuerIsLookedUpAfterTheSignatureIsChecked(): void {
		$middleware = $this->middleware(method: 'GET', group: 'zaken', config: ['scopes' => [['component' => 'zrc', 'scopes' => ['zaken.lezen']]]]);
		$this->validator->expects($this->once())->method('validate')->with(self::bearer(issuer: 'app-1'));

		$this->assertNull($this->refusal($middleware));
		$this->assertSame([['name' => 'app-1']], $this->lookups);
	}//end testTheIssuerIsLookedUpAfterTheSignatureIsChecked()

	/**
	 * A superuser skips the scope check but never the signature check.
	 *
	 * @return void
	 */
	public function testASuperuserIsStillSignatureChecked(): void {
		$middleware = $this->middleware(method: 'DELETE', group: 'onbekend', config: ['superuser' => true]);
		$this->validator->expects($this->once())->method('validate')->willThrowException(new \RuntimeException('bad signature'));

		$refusal = $this->refusal($middleware);

		$this->assertSame('Authenticatiegegevens zijn niet geldig.', $refusal?->getMessage());
		$this->assertSame([], $this->lookups);
	}//end testASuperuserIsStillSignatureChecked()

	/**
	 * The refusal's own status code is what afterException answers with.
	 *
	 * @return void
	 */
	public function testAfterExceptionAnswersWithTheRefusalsStatus(): void {
		$middleware = $this->middleware(method: 'GET', group: 'zaken', config: []);

		$response = $middleware->afterException(null, 'index', new ZgwAuthException(message: 'Token expired', statusCode: 401));

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(401, $response->getData()['status']);
		$this->assertSame('Token expired', $response->getData()['detail']);
		$this->assertSame('permission_denied', $response->getData()['code']);
		$this->assertSame(403, (new ZgwAuthException(message: 'x'))->getStatusCode());
	}//end testAfterExceptionAnswersWithTheRefusalsStatus()
}//end class
