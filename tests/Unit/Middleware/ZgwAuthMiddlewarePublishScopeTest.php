<?php

/**
 * The ZGW middleware asks the catalogi write scope for the three publish routes.
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
 * @spec openspec/changes/archive/2026-11-25-zgw-publish-endpoint-guard/specs/zgw-business-rules-compliance/spec.md#requirement-the-gateway-asks-the-catalogi-write-scope-for-a-publish
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Middleware;

use OCA\Dossiq\Controller\ZgwController;
use OCA\Dossiq\Middleware\ZgwAuthException;
use OCA\Dossiq\Middleware\ZgwAuthMiddleware;
use OCA\Dossiq\Service\Zgw\ApiCredentialResolver;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Scope decisions for catalogi publish routes.
 *
 * @spec openspec/changes/archive/2026-11-25-zgw-publish-endpoint-guard/specs/zgw-business-rules-compliance/spec.md#requirement-the-gateway-asks-the-catalogi-write-scope-for-a-publish
 */
class ZgwAuthMiddlewarePublishScopeTest extends TestCase {

	/**
	 * The refusal for one request, or null when the middleware lets it through.
	 *
	 * @param string       $method The HTTP method.
	 * @param string       $path   The request path after /api/zgw/.
	 * @param list<string> $scopes The consumer's ztc scopes.
	 *
	 * @return ZgwAuthException|null
	 */
	private function refusal(string $method, string $path, array $scopes): ?ZgwAuthException {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getMethod')->willReturn($method);
		$request->method('getRequestUri')->willReturn('/index.php/apps/dossiq/api/zgw/' . $path);

		$consumer = new class (['scopes' => [['component' => 'ztc', 'scopes' => $scopes]]]) {
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

		$resolver = $this->createMock(originalClassName: ApiCredentialResolver::class);
		$resolver->method('resolve')->willReturn($consumer);
		$middleware = new ZgwAuthMiddleware(
			request: $request,
			credentials: $resolver,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		try {
			$middleware->beforeController(controller: $this->createMock(originalClassName: ZgwController::class), methodName: 'publishZaaktype');
		} catch (ZgwAuthException $e) {
			return $e;
		}

		return null;
	}//end refusal()

	/**
	 * The three publish routes.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function publishRoutes(): array {
		return [
			'zaaktype' => ['catalogi/v1/zaaktypen/0bbf1418-dd31-4cd4-84c0-4e664241c20c/publish'],
			'besluittype' => ['catalogi/v1/besluittypen/0bbf1418-dd31-4cd4-84c0-4e664241c20c/publish'],
			'informatieobjecttype' => ['catalogi/v1/informatieobjecttypen/0bbf1418-dd31-4cd4-84c0-4e664241c20c/publish'],
		];
	}//end publishRoutes()

	/**
	 * A client with catalogi.schrijven reaches a publish route; one with only catalogi.lezen is refused.
	 *
	 * @param string $path The publish route.
	 *
	 * @return void
	 *
	 * @dataProvider publishRoutes
	 */
	public function testAPublishAsksTheWriteScope(string $path): void {
		$this->assertNull(actual: $this->refusal(method: 'POST', path: $path, scopes: ['catalogi.lezen', 'catalogi.schrijven']));

		$refusal = $this->refusal(method: 'POST', path: $path, scopes: ['catalogi.lezen']);
		$this->assertInstanceOf(expected: ZgwAuthException::class, actual: $refusal);
		$this->assertSame(expected: 403, actual: $refusal->getStatusCode());
	}//end testAPublishAsksTheWriteScope()

	/**
	 * Any other catalogi POST keeps the scope its method maps to: the write scope does not create.
	 *
	 * @return void
	 */
	public function testACreateStillAsksItsOwnScope(): void {
		$this->assertInstanceOf(
			expected: ZgwAuthException::class,
			actual: $this->refusal(method: 'POST', path: 'catalogi/v1/zaaktypen', scopes: ['catalogi.schrijven'])
		);
		$this->assertNull(actual: $this->refusal(method: 'POST', path: 'catalogi/v1/zaaktypen', scopes: ['catalogi.aanmaken']));
	}//end testACreateStillAsksItsOwnScope()
}//end class
