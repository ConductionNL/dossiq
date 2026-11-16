<?php

/**
 * ApiCredentialResolver edges: the real connection address, unusable proxy settings and a consumer without a user.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
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
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Ip\IAddress;
use OCP\Security\Ip\IFactory;
use OCP\Security\Ip\IRange;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The resolver as the container builds it: the connection address read from the server.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ApiCredentialResolver
 * @uses \OCA\Dossiq\Service\ZgwAuthValidationException
 */
class ApiCredentialResolverEdgesTest extends TestCase {
	private const HEADER = 'X-SSL-Client-SHA256';

	private const FINGERPRINT = 'ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12';

	/**
	 * The REMOTE_ADDR before the test, null when unset.
	 *
	 * @var string|null
	 */
	private ?string $savedRemote;

	/**
	 * What Nextcloud answers for `trusted_proxies`.
	 *
	 * @var mixed
	 */
	private mixed $trustedProxies = ['10.0.0.1'];

	/**
	 * The consumers OpenRegister holds.
	 *
	 * @var array<int, object>
	 */
	private array $consumers = [];

	private IConfig&MockObject $config;

	private IFactory&MockObject $ips;

	private IUserManager&MockObject $users;

	private IUserSession&MockObject $session;

	protected function setUp(): void {
		$this->savedRemote = $_SERVER['REMOTE_ADDR'] ?? null;
		$this->consumers   = [$this->consumer(userId: 'inspectie', fingerprint: self::FINGERPRINT)];

		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, mixed $default = ''): mixed => $key === 'trusted_proxies' ? $this->trustedProxies : $default
		);

		$this->ips = $this->createMock(IFactory::class);
		$this->ips->method('addressFromString')->willReturnCallback(function (string $ip): IAddress {
			$address = $this->createMock(IAddress::class);
			$address->method('__toString')->willReturn($ip);
			return $address;
		});
		$this->ips->method('rangeFromString')->willReturnCallback(function (string $proxy): IRange {
			$range = $this->createMock(IRange::class);
			$range->method('contains')->willReturnCallback(static fn (IAddress $address): bool => (string)$address === $proxy);
			return $range;
		});

		$this->users   = $this->createMock(IUserManager::class);
		$this->session = $this->createMock(IUserSession::class);
	}//end setUp()

	protected function tearDown(): void {
		if ($this->savedRemote === null) {
			unset($_SERVER['REMOTE_ADDR']);
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->savedRemote;
		}
	}//end tearDown()

	/**
	 * A certificate consumer with this user.
	 *
	 * @param string|null $userId      Its Nextcloud user.
	 * @param string      $fingerprint Its stored fingerprint.
	 *
	 * @return object
	 */
	private function consumer(?string $userId, string $fingerprint): object {
		return new class($userId, $fingerprint) {
			public function __construct(private readonly ?string $userId, private readonly string $fingerprint) {
			}

			public function getAuthorizationType(): string {
				return 'certificate';
			}

			/**
			 * @return array<string, string>
			 */
			public function getAuthorizationConfiguration(): array {
				return ['certificateFingerprint' => $this->fingerprint];
			}

			public function getUserId(): ?string {
				return $this->userId;
			}
		};
	}//end consumer()

	/**
	 * The resolver, unsubclassed, with certificate access switched on.
	 *
	 * @return ApiCredentialResolver
	 */
	private function resolver(): ApiCredentialResolver {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => $key === ApiCredentialResolver::CERT_HEADER_KEY ? self::HEADER : $default
		);

		$consumers = &$this->consumers;
		$mapper = new class($consumers) {
			/**
			 * @param array<int, object> $consumers The consumers.
			 */
			public function __construct(private array &$consumers) {
			}

			/**
			 * @return array<int, object>
			 */
			public function findAll(): array {
				return $this->consumers;
			}
		};

		return new ApiCredentialResolver(
			jwtValidator: $this->createMock(ZgwJwtValidator::class),
			settings: $settings,
			config: $this->config,
			ipFactory: $this->ips,
			userManager: $this->users,
			userSession: $this->session,
			logger: $this->createMock(LoggerInterface::class),
			consumerMapper: $mapper,
		);
	}//end resolver()

	/**
	 * A request carrying the certificate header.
	 *
	 * @return IRequest
	 */
	private function certificateRequest(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => $name === self::HEADER ? self::FINGERPRINT : '');
		return $request;
	}//end certificateRequest()

	/**
	 * Resolve the certificate request and return the refusal message.
	 *
	 * @return string
	 */
	private function refusal(): string {
		try {
			$this->resolver()->resolve($this->certificateRequest());
		} catch (ZgwAuthValidationException $refused) {
			return $refused->getMessage();
		}

		$this->fail('expected a refusal');
	}//end refusal()

	/**
	 * The connection address is REMOTE_ADDR: a trusted one resolves, any other is refused.
	 *
	 * @return void
	 */
	public function testTheConnectionAddressIsReadFromTheServer(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$this->users->expects($this->once())->method('get')->with('inspectie')->willReturn(null);

		$this->assertSame($this->consumers[0], $this->resolver()->resolve($this->certificateRequest()));

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertSame('Untrusted certificate header', $this->refusal());
	}//end testTheConnectionAddressIsReadFromTheServer()

	/**
	 * Without a known connection address the header is untrusted, and the proxy list is not even read.
	 *
	 * @return void
	 */
	public function testAnUnknownConnectionAddressIsNeverTrusted(): void {
		unset($_SERVER['REMOTE_ADDR']);
		$this->config->expects($this->never())->method('getSystemValue');

		$this->assertSame('Untrusted certificate header', $this->refusal());
	}//end testAnUnknownConnectionAddressIsNeverTrusted()

	/**
	 * A `trusted_proxies` that is no list trusts nobody, and no range is parsed from it.
	 *
	 * @return void
	 */
	public function testATrustedProxiesSettingThatIsNoListTrustsNobody(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$this->trustedProxies = '10.0.0.1';
		$this->ips->expects($this->never())->method('rangeFromString');

		$this->assertSame('Untrusted certificate header', $this->refusal());
	}//end testATrustedProxiesSettingThatIsNoListTrustsNobody()

	/**
	 * A consumer without a user resolves, and no user is looked up or bound.
	 *
	 * @return void
	 */
	public function testAConsumerWithoutAUserResolvesWithoutBinding(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$this->users->expects($this->never())->method('get');
		$this->session->expects($this->never())->method('setUser');

		foreach ([null, ''] as $userId) {
			$this->consumers = [$this->consumer(userId: $userId, fingerprint: self::FINGERPRINT)];
			$this->assertSame($this->consumers[0], $this->resolver()->resolve($this->certificateRequest()));
		}
	}//end testAConsumerWithoutAUserResolvesWithoutBinding()
}//end class
