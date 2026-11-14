<?php

/**
 * Api Credential Resolver Test
 *
 * Which OpenRegister consumer a ZGW API request resolves to for each of the
 * three credentials (bearer JWT, API key, client certificate forwarded by a
 * trusted proxy), that the first credential present decides, that the
 * consumer's user is bound exactly once, and that a removed key or consumer
 * stops resolving at once.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/public-api-access-with-keys-and-certificates/specs/public-api-access/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zgw\ApiCredentialResolver;
use OCA\Dossiq\Service\ZgwAuthValidationException;
use OCA\Dossiq\Service\ZgwJwtValidator;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Ip\IAddress;
use OCP\Security\Ip\IFactory;
use OCP\Security\Ip\IRange;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Zgw\ApiCredentialResolver
 */
class ApiCredentialResolverTest extends TestCase {

	private const FINGERPRINT = 'ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12';

	/**
	 * The consumers OpenRegister holds.
	 *
	 * @var array<int, object>
	 */
	public array $consumers = [];

	/**
	 * Whether reading the consumers fails.
	 */
	public bool $consumersUnreadable = false;

	/**
	 * Request headers, by name.
	 *
	 * @var array<string, string>
	 */
	private array $headers = [];

	/**
	 * Dossiq settings, by key.
	 *
	 * @var array<string, string>
	 */
	private array $settings = [];

	/**
	 * Nextcloud's `trusted_proxies`.
	 *
	 * @var array<int, string>
	 */
	private array $trustedProxies = ['10.0.0.1'];

	/**
	 * The address the connection comes from.
	 */
	private string $remote = '10.0.0.1';

	/**
	 * Every user bound to the session, in order.
	 *
	 * @var array<int, string>
	 */
	private array $bound = [];

	private ZgwJwtValidator&MockObject $jwt;

	protected function setUp(): void {
		$this->jwt = $this->createMock(ZgwJwtValidator::class);
		$this->consumers = [
			$this->consumer(name: 'Zaakportaal', type: 'jwt', userId: 'portaal', config: ['publicKey' => 'secret']),
			$this->consumer(name: 'Archief', type: 'apiKey', userId: 'archief', config: ['apiKey' => 'k-123']),
			$this->consumer(name: 'Inspectie', type: 'certificate', userId: 'inspectie', config: ['certificateFingerprint' => strtoupper(implode(':', str_split(self::FINGERPRINT, 2)))]),
		];
	}

	/**
	 * A consumer as OpenRegister's entity answers.
	 *
	 * @param string               $name   Its name.
	 * @param string               $type   Its authorization type.
	 * @param string               $userId Its Nextcloud user.
	 * @param array<string, mixed> $config Its authorization configuration.
	 *
	 * @return object
	 */
	private function consumer(string $name, string $type, string $userId, array $config): object {
		return new class($name, $type, $userId, $config) {
			/**
			 * @param array<string, mixed> $config The configuration.
			 */
			public function __construct(
				public readonly string $name,
				private readonly string $type,
				private readonly string $userId,
				private readonly array $config,
			) {
			}

			public function getAuthorizationType(): string {
				return $this->type;
			}

			/**
			 * @return array<string, mixed>
			 */
			public function getAuthorizationConfiguration(): array {
				return $this->config;
			}

			public function getUserId(): string {
				return $this->userId;
			}
		};
	}

	/**
	 * The resolver under test, with the connection address this test names.
	 *
	 * @return ApiCredentialResolver
	 */
	private function resolver(): ApiCredentialResolver {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => $this->settings[$key] ?? $default
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			fn (string $key, mixed $default = ''): mixed => $key === 'trusted_proxies' ? $this->trustedProxies : $default
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid): ?IUser {
			if ($uid === 'nobody') {
				return null;
			}

			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			return $user;
		});
		$session = $this->createMock(IUserSession::class);
		$session->method('setUser')->willReturnCallback(function (?IUser $user): void {
			$this->bound[] = (string)$user?->getUID();
		});

		$test = $this;
		$mapper = new class($test) {
			public function __construct(private readonly ApiCredentialResolverTest $test) {
			}

			/**
			 * @return array<int, object>
			 */
			public function findAll(): array {
				if ($this->test->consumersUnreadable === true) {
					throw new RuntimeException('db down');
				}

				return $this->test->consumers;
			}
		};

		$remote = $this->remote;
		return new class($this->jwt, $settings, $config, $this->ipFactory(), $users, $session, $this->createMock(LoggerInterface::class), $mapper, $remote) extends ApiCredentialResolver {
			public function __construct(
				ZgwJwtValidator $jwt,
				SettingsService $settings,
				IConfig $config,
				IFactory $ips,
				IUserManager $users,
				IUserSession $session,
				LoggerInterface $logger,
				object $mapper,
				private readonly string $remote,
			) {
				parent::__construct($jwt, $settings, $config, $ips, $users, $session, $logger, $mapper);
			}

			protected function remoteAddress(): string {
				return $this->remote;
			}
		};
	}

	/**
	 * An IP factory over exact addresses and `/n` IPv4 ranges.
	 *
	 * @return IFactory
	 */
	private function ipFactory(): IFactory {
		$factory = $this->createMock(IFactory::class);
		$factory->method('addressFromString')->willReturnCallback(function (string $ip): IAddress {
			$address = $this->createMock(IAddress::class);
			$address->method('__toString')->willReturn($ip);
			return $address;
		});
		$factory->method('rangeFromString')->willReturnCallback(function (string $range): IRange {
			if ($range === 'garbage') {
				throw new \InvalidArgumentException('not a range');
			}

			$mock = $this->createMock(IRange::class);
			$mock->method('contains')->willReturnCallback(static function (IAddress $address) use ($range): bool {
				[$net, $bits] = array_pad(explode('/', $range), 2, '32');
				$mask = (-1 << (32 - (int)$bits)) & 0xFFFFFFFF;
				return (ip2long((string)$address) & $mask) === (ip2long($net) & $mask);
			});
			return $mock;
		});

		return $factory;
	}

	/**
	 * A request carrying `$this->headers`.
	 *
	 * @return IRequest
	 */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		return $request;
	}

	/**
	 * Resolve and expect a refusal with this message.
	 *
	 * @param string $message The expected message.
	 *
	 * @return void
	 */
	private function assertRefused(string $message): void {
		try {
			$this->resolver()->resolve($this->request());
			$this->fail('expected a refusal: ' . $message);
		} catch (ZgwAuthValidationException $refused) {
			$this->assertSame($message, $refused->getMessage());
		}

		$this->assertSame([], $this->bound, 'a refused request binds nobody');
	}

	public function testAValidJwtResolvesItsConsumerAndBindsItsUserOnce(): void {
		$this->headers['Authorization'] = 'Bearer a.b.c';
		$this->jwt->expects($this->once())->method('consumerFor')->with('Bearer a.b.c')->willReturn($this->consumers[0]);
		$this->jwt->expects($this->never())->method('validate');

		$this->assertSame($this->consumers[0], $this->resolver()->resolve($this->request()));
		$this->assertSame(['portaal'], $this->bound);
	}

	public function testAFailingJwtIsRefusedAndDoesNotFallThroughToAValidApiKey(): void {
		$this->headers['Authorization'] = 'Bearer expired.token.sig';
		$this->headers['X-Api-Key'] = 'k-123';
		$this->jwt->method('consumerFor')->willThrowException(new ZgwAuthValidationException('The token has expired'));

		$this->assertRefused('The token has expired');
	}

	public function testAnApiKeyResolvesItsConsumer(): void {
		$this->headers['X-Api-Key'] = 'k-123';
		$this->jwt->expects($this->never())->method('consumerFor');

		$this->assertSame('Archief', $this->resolver()->resolve($this->request())->name);
		$this->assertSame(['archief'], $this->bound);
	}

	public function testAnApiKeyMayAlsoTravelAsAnAuthorizationScheme(): void {
		$this->headers['Authorization'] = 'ApiKey k-123';

		$this->assertSame('Archief', $this->resolver()->resolve($this->request())->name);
	}

	public function testAnUnknownApiKeyIsRefused(): void {
		$this->headers['X-Api-Key'] = 'k-999';

		$this->assertRefused('Unknown API key');
	}

	public function testAKeyStoredOnAConsumerOfAnotherTypeDoesNotCount(): void {
		$this->consumers[] = $this->consumer(name: 'Oud', type: 'jwt', userId: 'oud', config: ['apiKey' => 'k-old']);
		$this->headers['X-Api-Key'] = 'k-old';

		$this->assertRefused('Unknown API key');
	}

	public function testARemovedKeyStopsResolvingOnTheNextRequest(): void {
		$this->headers['X-Api-Key'] = 'k-123';
		$this->assertSame('Archief', $this->resolver()->resolve($this->request())->name);

		$this->bound = [];
		$this->consumers[1] = $this->consumer(name: 'Archief', type: 'apiKey', userId: 'archief', config: []);
		$this->assertRefused('Unknown API key');

		unset($this->consumers[1]);
		$this->assertRefused('Unknown API key');
	}

	public function testACertificateFromATrustedProxyResolvesItsConsumer(): void {
		$this->settings['public_api_client_cert_header'] = 'X-SSL-Client-SHA256';
		$this->headers['X-SSL-Client-SHA256'] = self::FINGERPRINT;

		$this->assertSame('Inspectie', $this->resolver()->resolve($this->request())->name);
		$this->assertSame(['inspectie'], $this->bound);
	}

	public function testATrustedProxyRangeCountsToo(): void {
		$this->settings['public_api_client_cert_header'] = 'X-SSL-Client-SHA256';
		$this->headers['X-SSL-Client-SHA256'] = self::FINGERPRINT;
		$this->trustedProxies = ['garbage', '192.168.0.0/16'];
		$this->remote = '192.168.4.7';

		$this->assertSame('Inspectie', $this->resolver()->resolve($this->request())->name);
	}

	public function testACertificateHeaderFromAnUntrustedAddressIsRefused(): void {
		$this->settings['public_api_client_cert_header'] = 'X-SSL-Client-SHA256';
		$this->headers['X-SSL-Client-SHA256'] = self::FINGERPRINT;
		$this->remote = '203.0.113.9';

		$this->assertRefused('Untrusted certificate header');
	}

	public function testAnUnknownOrMalformedFingerprintIsRefused(): void {
		$this->settings['public_api_client_cert_header'] = 'X-SSL-Client-SHA256';
		$this->headers['X-SSL-Client-SHA256'] = str_repeat('0', 64);
		$this->assertRefused('Unknown certificate');

		$this->headers['X-SSL-Client-SHA256'] = 'not-hex';
		$this->assertRefused('Unknown certificate');
	}

	public function testWithoutTheSettingACertificateHeaderIsNoCredential(): void {
		$this->headers['X-SSL-Client-SHA256'] = self::FINGERPRINT;

		$this->assertNull($this->resolver()->resolve($this->request()));
		$this->assertSame([], $this->bound);
	}

	public function testARequestWithoutAnyCredentialResolvesToNobody(): void {
		$this->settings['public_api_client_cert_header'] = 'X-SSL-Client-SHA256';

		$this->assertNull($this->resolver()->resolve($this->request()));
		$this->assertSame([], $this->bound);
	}

	public function testAnotherAuthorizationSchemeIsRefused(): void {
		$this->headers['Authorization'] = 'Basic dXNlcjpwYXNz';

		$this->assertRefused('Unsupported authorization scheme');
	}

	public function testUnreadableConsumersRefuseRatherThanPass(): void {
		$this->consumersUnreadable = true;
		$this->headers['X-Api-Key'] = 'k-123';

		$this->assertRefused('Authorization service is unavailable');
	}

	public function testAConsumerWithoutAKnownUserBindsNobody(): void {
		$this->consumers[1] = $this->consumer(name: 'Archief', type: 'apiKey', userId: 'nobody', config: ['apiKey' => 'k-123']);
		$this->headers['X-Api-Key'] = 'k-123';

		$this->assertSame('Archief', $this->resolver()->resolve($this->request())->name);
		$this->assertSame([], $this->bound);
	}
}
