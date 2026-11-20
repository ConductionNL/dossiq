<?php

/**
 * The ZGW authorisation gate, recorded outcome by outcome.
 *
 * Every refusal the gate makes and both ways in (a superuser consumer, a
 * consumer whose scopes cover the request) are driven here and compared with
 * a golden file: the outcome, its status and message, and every log line.
 * The caller is resolved by ApiCredentialResolver; each kind of credential
 * failure it raises is a world of its own (401), the scope worlds follow (403).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every gate outcome matches the recording.
 *
 * @covers \OCA\Dossiq\Middleware\ZgwAuthMiddleware
 * @uses \OCA\Dossiq\Middleware\ZgwAuthException
 * @uses \OCA\Dossiq\Service\Zgw\ApiCredentialResolver
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class ZgwAuthMiddlewareGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The calls, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The worlds: what the caller resolves to (a consumer configuration, null
	 * for no credential, or a refusal message), method, URI.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function worlds(): array {
		$reader = ['scopes' => [['component' => 'zrc', 'scopes' => ['zaken.lezen']]]];
		$base = ['consumer' => $reader, 'method' => 'GET', 'uri' => '/index.php/apps/dossiq/api/zgw/zaken/v1/zaken'];
		$refused = static fn (string $message): array => array_replace($base, ['consumer' => ['refuse' => $message]]);

		return [
			'no credential' => array_replace($base, ['consumer' => null]),
			'an expired token' => $refused('The token has expired'),
			'a token that does not validate' => $refused('The token does not match the shared secret'),
			'an unknown issuer' => $refused('Unknown issuer'),
			'a token that is not three parts' => $refused('Invalid JWT format'),
			'an unknown api key' => $refused('Unknown API key'),
			'a certificate header from an untrusted address' => $refused('Untrusted certificate header'),
			'an unknown certificate' => $refused('Unknown certificate'),
			'another authorization scheme' => $refused('Unsupported authorization scheme'),
			'unreadable consumers' => $refused('Authorization service is unavailable'),
			'a superuser' => array_replace($base, ['consumer' => ['superuser' => true], 'uri' => '/elsewhere']),
			'a superuser flag that is not true' => array_replace($base, ['consumer' => ['superuser' => 1] + $reader]),
			'a uri outside the api' => array_replace($base, ['uri' => '/index.php/apps/dossiq/elsewhere']),
			'an unknown api group' => array_replace($base, ['uri' => '/index.php/apps/dossiq/api/zgw/onbekend/v1/x']),
			'an unsupported method' => array_replace($base, ['method' => 'HEAD']),
			'a covering scope' => $base,
			'a covering scope among others' => array_replace(
				$base,
				['consumer' => ['scopes' => [['component' => 'ztc', 'scopes' => ['catalogi.lezen']], ['component' => 'zrc', 'scopes' => ['zaken.bijwerken', 'zaken.lezen']]]]]
			),
			'a scope for another method' => array_replace($base, ['method' => 'POST']),
			'a scope for another component' => array_replace($base, ['uri' => '/index.php/apps/dossiq/api/zgw/besluiten/v1/besluiten']),
			'a malformed scope' => array_replace($base, ['consumer' => ['scopes' => [['component' => 'zrc', 'scopes' => ['zaken.lezen.extra', 'lezen']]]]]),
			'no scopes at all' => array_replace($base, ['consumer' => []]),
		];
	}//end worlds()

	/**
	 * Every world matches the recording.
	 *
	 * @return void
	 */
	public function testEveryOutcomeMatchesTheRecording(): void {
		$recorded = [];
		foreach (self::worlds() as $name => $world) {
			$this->log = [];
			try {
				$this->middleware(world: $world)->beforeController($this->createMock(ZgwController::class), 'index');
				$this->log[] = ['outcome' => 'allowed'];
			} catch (ZgwAuthException $e) {
				$this->log[] = ['outcome' => 'refused', 'status' => $e->getStatusCode(), 'message' => $e->getMessage()];
			}

			$recorded[$name] = $this->log;
		}

		$this->assertMatchesGolden(name: 'zgw-auth-middleware/outcomes', value: $recorded);
	}//end testEveryOutcomeMatchesTheRecording()

	/**
	 * The middleware over the world's request and resolver.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return ZgwAuthMiddleware
	 */
	private function middleware(array $world): ZgwAuthMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($world['method']);
		$request->method('getRequestUri')->willReturn($world['uri']);

		$resolver = $this->createMock(ApiCredentialResolver::class);
		$resolver->method('resolve')->willReturnCallback(
			function () use ($world): ?object {
				$this->log[] = ['resolve' => true];
				$consumer = $world['consumer'];
				if ($consumer === null) {
					return null;
				}

				if (isset($consumer['refuse']) === true) {
					throw new ZgwAuthValidationException($consumer['refuse']);
				}

				return new class ($consumer) {
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
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		return new ZgwAuthMiddleware($request, $resolver, $logger);
	}//end middleware()
}//end class
