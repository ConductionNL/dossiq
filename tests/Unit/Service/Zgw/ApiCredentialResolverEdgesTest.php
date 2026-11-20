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

	/**
	 * The config.
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig&MockObject $config;

	/**
	 * The ips.
	 *
	 * @var IFactory&MockObject
	 */
	private IFactory&MockObject $ips;

	/**
	 * The users.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Build the subject and its collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->savedRemote = $_SERVER['REMOTE_ADDR'] ?? null;
		$this->consumers   = [$this->consumer(userId: 'inspectie', fingerprint: self::FINGERPRINT)];

		$this->config = $this->createMock(originalClassName: IConfig::class);
		$this->config->method('getSystemValue')->willReturnCallback(
			fn (string $key, mixed $default = ''): mixed => match ($key) {
				'trusted_proxies' => $this->trustedProxies,
				default => $default,
			}
		);

		$this->ips = $this->createMock(originalClassName: IFactory::class);
		$this->ips->method('addressFromString')->willReturnCallback(function (string $ip): IAddress {
			$address = $this->createMock(originalClassName: IAddress::class);
			$address->method('__toString')->willReturn($ip);
			return $address;
		});
		$this->ips->method('rangeFromString')->willReturnCallback(function (string $proxy): IRange {
			$range = $this->createMock(originalClassName: IRange::class);
			$range->method('contains')->willReturnCallback(static fn (IAddress $address): bool => (string)$address === $proxy);
			return $range;
		});

		$this->users   = $this->createMock(originalClassName: IUserManager::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
	}//end setUp()

	/**
	 * Restore what the test changed.
	 *
	 * @return void
	 */
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
			/**
			 * Constructor.
			 *
			 * @param ?string $userId The user id.
			 * @param string $fingerprint The fingerprint.
			 */
			public function __construct(private readonly ?string $userId, private readonly string $fingerprint) {
			}

			/**
			 * Stand-in for getAuthorizationType().
			 *
			 * @return string
			 */
			public function getAuthorizationType(): string {
				return 'certificate';
			}

			/**
			 * @return array<string, string>
			 */
			public function getAuthorizationConfiguration(): array {
				return ['certificateFingerprint' => $this->fingerprint];
			}

			/**
			 * Stand-in for getUserId().
			 *
			 * @return ?string
			 */
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
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				ApiCredentialResolver::CERT_HEADER_KEY => self::HEADER,
				default => $default,
			}
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
			jwtValidator: $this->createMock(originalClassName: ZgwJwtValidator::class),
			settings: $settings,
			config: $this->config,
			ipFactory: $this->ips,
			userManager: $this->users,
			userSession: $this->session,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			consumerMapper: $mapper,
		);
	}//end resolver()

	/**
	 * A request carrying the certificate header.
	 *
	 * @return IRequest
	 */
	private function certificateRequest(): IRequest {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => match ($name) {
				self::HEADER => self::FINGERPRINT,
				default => '',
			}
		);
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

		$this->fail(message: 'expected a refusal');
	}//end refusal()

	/**
	 * The connection address is REMOTE_ADDR: a trusted one resolves, any other is refused.
	 *
	 * @return void
	 */
	public function testTheConnectionAddressIsReadFromTheServer(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		$this->users->expects($this->once())->method('get')->with('inspectie')->willReturn(null);

		$this->assertSame(expected: $this->consumers[0], actual: $this->resolver()->resolve($this->certificateRequest()));

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertSame(expected: 'Untrusted certificate header', actual: $this->refusal());
	}//end testTheConnectionAddressIsReadFromTheServer()

	/**
	 * Without a known connection address the header is untrusted, and the proxy list is not even read.
	 *
	 * @return void
	 */
	public function testAnUnknownConnectionAddressIsNeverTrusted(): void {
		unset($_SERVER['REMOTE_ADDR']);
		$this->config->expects($this->never())->method('getSystemValue');

		$this->assertSame(expected: 'Untrusted certificate header', actual: $this->refusal());
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

		$this->assertSame(expected: 'Untrusted certificate header', actual: $this->refusal());
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
			$this->assertSame(expected: $this->consumers[0], actual: $this->resolver()->resolve($this->certificateRequest()));
		}
	}//end testAConsumerWithoutAUserResolvesWithoutBinding()
}//end class
