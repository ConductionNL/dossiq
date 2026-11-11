<?php

/**
 * The ZGW authorisation gate, recorded outcome by outcome.
 *
 * Every refusal the gate makes and both ways in (a superuser consumer, a
 * consumer whose scopes cover the request) are driven here and compared with
 * a golden file: the outcome, its status and message, and every log line.
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
use OCA\Dossiq\Service\ZgwJwtValidator;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * Every gate outcome matches the recording.
 *
 * @covers \OCA\Dossiq\Middleware\ZgwAuthMiddleware
 * @uses \OCA\Dossiq\Middleware\ZgwAuthException
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
	 * The worlds: header, token validity, consumer, method, URI.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function worlds(): array {
		$reader = ['scopes' => [['component' => 'zrc', 'scopes' => ['zaken.lezen']]]];
		$token = self::token(payload: ['iss' => 'app-1']);
		$base = ['header' => 'Bearer ' . $token, 'valid' => true, 'consumer' => $reader, 'method' => 'GET', 'uri' => '/index.php/apps/dossiq/api/zgw/zaken/v1/zaken'];

		return [
			'no authorization header' => array_replace($base, ['header' => '']),
			'a token that is not three parts' => array_replace($base, ['header' => 'Bearer abc.def']),
			'a payload that is not base64' => array_replace($base, ['header' => 'Bearer a.!!!.c']),
			'a payload that is not json' => array_replace($base, ['header' => 'Bearer a.' . base64_encode('not json') . '.c']),
			'a payload without an issuer' => array_replace($base, ['header' => 'Bearer ' . self::token(payload: ['sub' => 'x'])]),
			'a signature that does not validate' => array_replace($base, ['valid' => false]),
			'an unknown issuer' => array_replace($base, ['consumer' => null]),
			'a failing consumer lookup' => array_replace($base, ['consumer' => 'throws']),
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
	 * A JWT-shaped token carrying the payload.
	 *
	 * @param array<string, mixed> $payload The claims.
	 *
	 * @return string
	 */
	private static function token(array $payload): string {
		return 'eyJhbGciOiJIUzI1NiJ9.' . base64_encode((string)json_encode($payload)) . '.c2ln';
	}//end token()

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
	 * The middleware over the world's request, validator and consumers.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return ZgwAuthMiddleware
	 */
	private function middleware(array $world): ZgwAuthMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($world['header']);
		$request->method('getMethod')->willReturn($world['method']);
		$request->method('getRequestUri')->willReturn($world['uri']);

		$validator = $this->createMock(ZgwJwtValidator::class);
		$validator->method('validate')->willReturnCallback(
			function (string $authorization) use ($world): void {
				$this->log[] = ['validate' => true];
				if ($world['valid'] === false) {
					throw new RuntimeException('signature mismatch');
				}
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					if ($message === 'ZgwAuthMiddleware: OpenRegister auth services not available') {
						return;
					}

					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		$middleware = new ZgwAuthMiddleware($request, $validator, $logger);
		(new ReflectionProperty(ZgwAuthMiddleware::class, 'consumerMapper'))->setValue($middleware, $this->consumers(consumer: $world['consumer']));

		return $middleware;
	}//end middleware()

	/**
	 * A consumer mapper answering the one consumer, none, or a failure.
	 *
	 * @param mixed $consumer The consumer's authorization configuration, null, or `throws`.
	 *
	 * @return object
	 */
	private function consumers(mixed $consumer): object {
		return new class ($consumer, $this) {
			/**
			 * Constructor.
			 *
			 * @param mixed                       $consumer The configuration, null, or `throws`.
			 * @param ZgwAuthMiddlewareGoldenTest $test     The test keeping the log.
			 */
			public function __construct(private readonly mixed $consumer, private readonly ZgwAuthMiddlewareGoldenTest $test) {
			}

			/**
			 * Find the consumers matching the filters.
			 *
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return list<object>
			 */
			public function findAll(array $filters = []): array {
				$this->test->record(entry: ['findAll' => $filters]);
				if ($this->consumer === 'throws') {
					throw new \Exception('database gone');
				}

				if ($this->consumer === null) {
					return [];
				}

				$config = $this->consumer;
				return [
					new class ($config) {
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
					},
				];
			}
		};
	}//end consumers()

	/**
	 * Record a call.
	 *
	 * @param array<string, mixed> $entry The call.
	 *
	 * @return void
	 */
	public function record(array $entry): void {
		$this->log[] = $entry;
	}//end record()
}//end class
