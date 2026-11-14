<?php

/**
 * OutboundUrlGuard edge tests: the exact refusal log lines, spelling of
 * schemes and hosts, mapped addresses and options for an unparseable URL.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Outbound
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Outbound;

use OCA\Dossiq\Service\Outbound\HostResolver;
use OCA\Dossiq\Service\Outbound\OutboundUrlGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the guard logs when it refuses, and how it reads a URL's spelling.
 *
 * @covers \OCA\Dossiq\Service\Outbound\OutboundUrlGuard
 * @uses \OCA\Dossiq\Service\Outbound\HostResolver
 * @uses \OCA\Dossiq\Support\SuppressesWarnings
 */
class OutboundUrlGuardEdgesTest extends TestCase {
	use OutboundFakes;

	/**
	 * The names the fake resolver answers.
	 */
	private const NAMES = [
		'api.example.com' => ['93.184.216.34'],
		'mapped-public.example.com' => ['::ffff:93.184.216.34'],
		'ollama' => ['172.18.0.4'],
		'rebind.example.com' => ['93.184.216.34', '127.0.0.1'],
	];

	/**
	 * A guard whose logger records every warning as message and context.
	 *
	 * @param array<int, array{0:string,1:array<string,mixed>}> $logged Receives the warnings.
	 * @param HostResolver|null                                  $resolver The resolver; the fake names when null.
	 *
	 * @return OutboundUrlGuard
	 */
	private function recordingGuard(array &$logged, ?HostResolver $resolver = null): OutboundUrlGuard {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);

		if ($resolver !== null) {
			return new OutboundUrlGuard($logger, $resolver);
		}

		return $this->guard(names: self::NAMES, logger: $logger);
	}//end recordingGuard()

	/**
	 * A wrong scheme or missing host logs the lower-cased scheme and host, nothing else.
	 *
	 * @return void
	 */
	public function testASchemeRefusalLogsSchemeAndHost(): void {
		$logged = [];
		$guard = $this->recordingGuard(logged: $logged);

		$this->assertNull($guard->publicAddress('HTTP://API.Example.com/path?q=1'));
		$this->assertNull($guard->ownNetworkAddress('/relative/path'));

		$this->assertSame(
			[
				['Outbound URL refused: scheme or host', ['scheme' => 'http', 'host' => 'api.example.com']],
				['Outbound URL refused: scheme or host', ['scheme' => '', 'host' => '']],
			],
			$logged
		);
	}//end testASchemeRefusalLogsSchemeAndHost()

	/**
	 * A name that does not resolve logs the host and the resolver's last warning.
	 *
	 * @return void
	 */
	public function testAnUnresolvedNameLogsTheResolverWarning(): void {
		$resolver = new class extends HostResolver {
			/**
			 * Answer nothing, the way a failed lookup does.
			 *
			 * @param string $host The host.
			 *
			 * @return array<int, string>
			 */
			public function addressesOf(string $host): array {
				return [];
			}

			/**
			 * The warning the failed lookup left.
			 *
			 * @return string
			 */
			public function lastWarning(): string {
				return '[2] lookup timed out';
			}
		};
		$logged = [];
		$guard = $this->recordingGuard(logged: $logged, resolver: $resolver);

		$this->assertNull($guard->publicAddress('https://slow.example.com/'));

		$this->assertSame(
			[['Outbound URL refused: host does not resolve', ['host' => 'slow.example.com', 'detail' => '[2] lookup timed out']]],
			$logged
		);
	}//end testAnUnresolvedNameLogsTheResolverWarning()

	/**
	 * An address that is not allowed logs the host, the offending address and which question was asked.
	 *
	 * @return void
	 */
	public function testARefusedAddressLogsTheOffendingAddress(): void {
		$logged = [];
		$guard = $this->recordingGuard(logged: $logged);

		$this->assertNull($guard->publicAddress('https://rebind.example.com/'));
		$this->assertNull($guard->ownNetworkAddress('http://api.example.com/'));

		$this->assertSame(
			[
				['Outbound URL refused: address not allowed', ['host' => 'rebind.example.com', 'ip' => '127.0.0.1', 'insideOwnNetwork' => false]],
				['Outbound URL refused: address not allowed', ['host' => 'api.example.com', 'ip' => '93.184.216.34', 'insideOwnNetwork' => true]],
			],
			$logged
		);
	}//end testARefusedAddressLogsTheOffendingAddress()

	/**
	 * Scheme and host are read case-insensitively, and a bracketed v6 literal is read without its brackets.
	 *
	 * @return void
	 */
	public function testSchemeAndHostAreCaseFolded(): void {
		$guard = $this->guard(names: self::NAMES);

		$this->assertSame('93.184.216.34', $guard->publicAddress('HTTPS://API.EXAMPLE.COM/'));
		$this->assertSame('172.18.0.4', $guard->ownNetworkAddress('Http://OLLAMA:11434/api'));
		$this->assertSame('2001:4860:4860::8888', $guard->publicAddress('https://[2001:4860:4860::8888]/'));
		$this->assertSame('fd12:3456::1', $guard->ownNetworkAddress('http://[FD12:3456::1]:8080/'));
	}//end testSchemeAndHostAreCaseFolded()

	/**
	 * A v4-mapped v6 address is judged by its v4 address outside the own network, and answered as resolved.
	 *
	 * @return void
	 */
	public function testAMappedPublicAddressIsJudgedByItsV4Address(): void {
		$guard = $this->guard(names: self::NAMES);

		$this->assertSame('::ffff:93.184.216.34', $guard->publicAddress('https://mapped-public.example.com/'));
		$this->assertSame('::ffff:93.184.216.34', $guard->publicAddress('https://[::ffff:93.184.216.34]/'));
		$this->assertNull($guard->publicAddress('https://[::ffff:192.168.1.1]/'));
	}//end testAMappedPublicAddressIsJudgedByItsV4Address()

	/**
	 * A URL without a host, or one parse_url cannot read, gets the plain options without a pin.
	 *
	 * @return void
	 */
	public function testAUrlWithoutAHostGetsNoPin(): void {
		$guard = $this->guard(names: []);
		$plain = ['allow_redirects' => false, 'verify' => true];

		$this->assertSame($plain, $guard->requestOptions(url: '/api/generate', address: '93.184.216.34'));
		$this->assertSame($plain, $guard->requestOptions(url: 'http:///nohost', address: '93.184.216.34'));
		$this->assertSame($plain, $guard->requestOptions(url: 'http://:80', address: '93.184.216.34'));
	}//end testAUrlWithoutAHostGetsNoPin()

	/**
	 * Without an injected resolver the guard resolves names itself; localhost is inside the own network.
	 *
	 * @return void
	 */
	public function testWithoutAResolverTheGuardResolvesNamesItself(): void {
		$guard = $this->guard(names: null);

		$this->assertSame('127.0.0.1', $guard->ownNetworkAddress('http://localhost:8080/'));
		$this->assertNull($guard->publicAddress('https://localhost/'));
	}//end testWithoutAResolverTheGuardResolvesNamesItself()
}//end class
