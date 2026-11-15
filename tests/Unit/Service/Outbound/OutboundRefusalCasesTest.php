<?php

/**
 * What the outbound-URL guards refuse
 *
 * Three classes each carried their own guard against server-side request
 * forgery: ZgwRulesBase (the selectielijst fetch), NotificatieService (the
 * subscriber callback) and Ai/AiEndpointGuard (the model endpoint). The two
 * tables below are what each one refused. They were written and green against
 * the three copies, and have not changed since: only the private helpers at
 * the bottom did, which now ask the real entry points over the one guard.
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

use OCA\Dossiq\Service\Ai\AiEndpointGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The refusal table of the three guards.
 *
 * @covers \OCA\Dossiq\Service\Outbound\OutboundUrlGuard
 * @covers \OCA\Dossiq\Service\Ai\AiEndpointGuard
 * @uses \OCA\Dossiq\Service\Outbound\HostResolver
 * @uses \OCA\Dossiq\Service\NotificatieService
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class OutboundRefusalCasesTest extends TestCase {
	use OutboundFakes;

	/**
	 * Addresses none of the three copies blocked and the one guard does.
	 *
	 * The only rows where the guard goes past the strictest copy. Each connects
	 * to this machine or to a blocked IPv4 address under another spelling.
	 *
	 * @var string[]
	 */
	private const BLOCKED_BY_NONE_BEFORE = ['::ffff:127.0.0.1', '::ffff:169.254.169.254', '0.0.0.0', '::'];

	/**
	 * Every address a guard was asked about, and what each guard said.
	 *
	 * Columns: blocked for the ZGW fetch, blocked for a notification callback,
	 * blocked for a cloud model, allowed for a local model. The last column is
	 * an allow-list, so it reads the other way round.
	 *
	 * @return array<string, array{0: string, 1: bool, 2: bool, 3: bool, 4: bool}>
	 */
	public static function addresses(): array {
		return [
			// RFC 1918, 10.0.0.0/8, with the address on each side of both edges.
			'below 10/8' => ['9.255.255.255', false, false, false, false],
			'first of 10/8' => ['10.0.0.0', true, true, true, true],
			'last of 10/8' => ['10.255.255.255', true, true, true, true],
			'above 10/8' => ['11.0.0.0', false, false, false, false],
			// RFC 1918, 172.16.0.0/12.
			'below 172.16/12' => ['172.15.255.255', false, false, false, false],
			'first of 172.16/12' => ['172.16.0.0', true, true, true, true],
			'last of 172.16/12' => ['172.31.255.255', true, true, true, true],
			'above 172.16/12' => ['172.32.0.0', false, false, false, false],
			// RFC 1918, 192.168.0.0/16.
			'below 192.168/16' => ['192.167.255.255', false, false, false, false],
			'first of 192.168/16' => ['192.168.0.0', true, true, true, true],
			'last of 192.168/16' => ['192.168.255.255', true, true, true, true],
			'above 192.168/16' => ['192.169.0.0', false, false, false, false],
			// Loopback, 127.0.0.0/8.
			'below loopback' => ['126.255.255.255', false, false, false, false],
			'loopback' => ['127.0.0.1', true, true, true, true],
			'last of loopback' => ['127.255.255.255', true, true, true, true],
			'above loopback' => ['128.0.0.0', false, false, false, false],
			// Link-local, 169.254.0.0/16: blocked everywhere, a local model included.
			'below link-local' => ['169.253.255.255', false, false, false, false],
			'first of link-local' => ['169.254.0.0', true, true, true, false],
			'cloud metadata' => ['169.254.169.254', true, true, true, false],
			'last of link-local' => ['169.254.255.255', true, true, true, false],
			'above link-local' => ['169.255.0.0', false, false, false, false],
			// IPv6 loopback, ::1/128.
			'v6 loopback' => ['::1', true, true, true, true],
			'next to v6 loopback' => ['::2', false, false, false, false],
			// IPv6 unique local, fc00::/7.
			'below unique local' => ['fbff:ffff::1', false, false, false, false],
			'first of unique local' => ['fc00::1', true, true, true, true],
			'last of unique local' => ['fdff:ffff::1', true, true, true, true],
			'above unique local' => ['fe00::1', false, false, false, false],
			// IPv6 link-local, fe80::/10: only the model guard knew it.
			'below v6 link-local' => ['fe7f::1', false, false, false, false],
			'first of v6 link-local' => ['fe80::1', false, false, true, false],
			'last of v6 link-local' => ['febf::1', false, false, true, false],
			'above v6 link-local' => ['fec0::1', false, false, false, false],
			// Public.
			'public v4' => ['93.184.216.34', false, false, false, false],
			'public v4 dns' => ['8.8.8.8', false, false, false, false],
			'public v6' => ['2001:4860:4860::8888', false, false, false, false],
			// Loopback and the unspecified address in other clothes: no guard knew these.
			'v4 loopback written as v6' => ['::ffff:127.0.0.1', false, false, false, false],
			'v4 metadata written as v6' => ['::ffff:169.254.169.254', false, false, false, false],
			'unspecified v4' => ['0.0.0.0', false, false, false, false],
			'unspecified v6' => ['::', false, false, false, false],
		];
	}//end addresses()

	/**
	 * The one guard blocks every address any of the three blocked.
	 *
	 * Where none did, it blocks only the four spellings named above. A local
	 * model is allowed exactly where it was. Each answer is asked twice, for
	 * the address as a literal and for a name that resolves to it.
	 *
	 * @param string $address The address.
	 * @param bool $zgw Blocked for the ZGW fetch, before.
	 * @param bool $notification Blocked for a notification callback, before.
	 * @param bool $cloud Blocked for a cloud model, before.
	 * @param bool $local Allowed for a local model.
	 *
	 * @return void
	 */
	#[DataProvider('addresses')]
	public function testTheOneGuardBlocksWhatAnyOfTheThreeBlocked(string $address, bool $zgw, bool $notification, bool $cloud, bool $local): void {
		$named = $this->guard(names: ['name.test' => [$address]]);
		$blocked = ($this->guard(names: [])->publicAddress('https://' . $this->asHost(address: $address) . '/') === null);

		$this->assertSame($blocked, $named->publicAddress('https://name.test/') === null, 'literal and name agree');
		$this->assertSame(
			($zgw || $notification || $cloud || in_array($address, self::BLOCKED_BY_NONE_BEFORE, true)),
			$blocked,
			'outside the own network'
		);
		$this->assertSame($cloud || in_array($address, self::BLOCKED_BY_NONE_BEFORE, true), $this->cloudModelBlocks(address: $address), 'cloud model');
		$this->assertSame($local, $this->localModelAllows(address: $address), 'local model');
		$this->assertSame($local, $named->ownNetworkAddress('http://name.test:11434') !== null, 'local model by name');
	}//end testTheOneGuardBlocksWhatAnyOfTheThreeBlocked()

	/**
	 * URLs that are refused whatever a resolver answers, and by which guard.
	 *
	 * Columns: refused by the ZGW fetch, by a notification callback, as a cloud
	 * model, as a local model.
	 *
	 * @return array<string, array{0: string, 1: bool, 2: bool, 3: bool, 4: bool}>
	 */
	public static function urls(): array {
		return [
			// Schemes.
			'ftp' => ['ftp://example.com/file', true, true, true, true],
			'file' => ['file:///etc/passwd', true, true, true, true],
			'gopher' => ['gopher://127.0.0.1/', true, true, true, true],
			'dict' => ['dict://127.0.0.1:11211/stats', true, true, true, true],
			'javascript' => ['javascript:alert(1)', true, true, true, true],
			'no scheme' => ['//93.184.216.34/', true, true, true, true],
			'plain http to a callback or cloud model' => ['http://93.184.216.34/', false, true, true, true],
			// No host.
			'empty' => ['', true, true, true, true],
			'a path' => ['/api/generate', true, true, true, true],
			'not a url' => ['not a url', true, true, true, true],
			'scheme only' => ['https://', true, true, true, true],
			'no host before the path' => ['https:///latest/meta-data/', true, true, true, true],
			// A name nothing answers for: refused, not waved through.
			'unresolvable' => ['https://nothing.dossiq-test.invalid/', true, true, true, true],
			// Private, loopback and link-local literals over https.
			'10/8' => ['https://10.0.0.1/admin', true, true, true, false],
			'172.16/12' => ['https://172.16.0.5/secret', true, true, true, false],
			'192.168/16' => ['https://192.168.1.1/login', true, true, true, false],
			'loopback' => ['https://127.0.0.1/internal', true, true, true, false],
			'loopback with a port' => ['https://127.0.0.1:8080/', true, true, true, false],
			'cloud metadata' => ['https://169.254.169.254/latest/meta-data/', true, true, true, true],
			'v6 loopback' => ['https://[::1]/', true, true, true, false],
			'v6 unique local' => ['https://[fd12:3456::1]/', true, true, true, false],
			'v6 link-local' => ['https://[fe80::1]/', true, true, true, true],
			// The same over http, which only the ZGW fetch and a local model accept as a scheme.
			'metadata over http' => ['http://169.254.169.254/latest/meta-data/', true, true, true, true],
			'10/8 over http' => ['http://10.0.0.1/admin', true, true, true, false],
			'loopback over http' => ['http://127.0.0.1/internal', true, true, true, false],
			// A private address behind credentials, and loopback written as one number.
			'credentials in front' => ['https://public.example@10.0.0.1/', true, true, true, false],
			'loopback as a decimal' => ['https://2130706433/', true, true, true, false],
			// A public address is no local model.
			'public as local' => ['https://8.8.8.8/', false, false, false, true],
		];
	}//end urls()

	/**
	 * Each guard refuses the URLs the table says it refuses.
	 *
	 * A false in the table is not asserted for the ZGW fetch and the callback:
	 * whether they accept depends on what the resolver answers for the host. A
	 * model endpoint resolves a literal to itself, so both of its answers are.
	 *
	 * @param string $url The URL.
	 * @param bool $zgw Refused by the ZGW fetch.
	 * @param bool $notification Refused by a notification callback.
	 * @param bool $cloud Refused as a cloud model.
	 * @param bool $local Refused as a local model.
	 *
	 * @return void
	 */
	#[DataProvider('urls')]
	public function testEachGuardRefusesWhatTheTableSays(string $url, bool $zgw, bool $notification, bool $cloud, bool $local): void {
		if ($zgw === true) {
			$this->assertTrue($this->zgwRefuses(url: $url), 'ZGW fetch');
		}

		if ($notification === true) {
			$this->assertTrue($this->notificationRefuses(url: $url), 'notification callback');
		}

		$this->assertSame($cloud, $this->cloudModelRefuses(url: $url), 'cloud model');
		$this->assertSame($local, $this->localModelRefuses(url: $url), 'local model');
	}//end testEachGuardRefusesWhatTheTableSays()

	/**
	 * An accepted model endpoint answers with the address that was inspected.
	 *
	 * @return void
	 */
	public function testAnAcceptedModelEndpointAnswersWithItsAddress(): void {
		$guard = new AiEndpointGuard($this->createMock(LoggerInterface::class));

		$this->assertSame('93.184.216.34', $guard->resolveSafeAddress('HTTPS://93.184.216.34/', 'cloud'));
		$this->assertSame('172.18.0.4', $guard->resolveSafeAddress('http://172.18.0.4:11434', 'local'));
		$this->assertSame('::1', $guard->resolveSafeAddress('http://[::1]:11434', 'local'));
		$this->assertStringStartsWith('127.', (string)$guard->resolveSafeAddress('http://localhost:11434', 'local'));
	}//end testAnAcceptedModelEndpointAnswersWithItsAddress()

	/**
	 * Whether a cloud model may not sit at an address.
	 *
	 * @param string $address The address.
	 *
	 * @return bool
	 */
	private function cloudModelBlocks(string $address): bool {
		return $this->cloudModelRefuses(url: 'https://' . $this->asHost(address: $address) . '/');
	}//end cloudModelBlocks()

	/**
	 * Whether a local model may sit at an address.
	 *
	 * @param string $address The address.
	 *
	 * @return bool
	 */
	private function localModelAllows(string $address): bool {
		return $this->localModelRefuses(url: 'http://' . $this->asHost(address: $address) . ':11434') === false;
	}//end localModelAllows()

	/**
	 * Whether the ZGW fetch refuses a URL: it answers null and connects to nothing.
	 *
	 * @param string $url The URL.
	 *
	 * @return bool
	 */
	private function zgwRefuses(string $url): bool {
		$answer = $this->zgwFetch(url: $url, guard: $this->guard(names: null));

		return $answer === null && count($this->sent) === 0;
	}//end zgwRefuses()

	/**
	 * Whether a notification callback refuses a URL: nothing is delivered to it.
	 *
	 * @param string $url The URL.
	 *
	 * @return bool
	 */
	private function notificationRefuses(string $url): bool {
		$this->notify(subscription: ['kanalen' => [['name' => 'zaken']], 'callbackUrl' => $url], guard: $this->guard(names: null));

		return count($this->sent) === 0;
	}//end notificationRefuses()

	/**
	 * Whether a URL is refused as a cloud model.
	 *
	 * @param string $url The URL.
	 *
	 * @return bool
	 */
	private function cloudModelRefuses(string $url): bool {
		return (new AiEndpointGuard($this->createMock(LoggerInterface::class)))->resolveSafeAddress($url, 'cloud') === null;
	}//end cloudModelRefuses()

	/**
	 * Whether a URL is refused as a local model.
	 *
	 * @param string $url The URL.
	 *
	 * @return bool
	 */
	private function localModelRefuses(string $url): bool {
		return (new AiEndpointGuard($this->createMock(LoggerInterface::class)))->resolveSafeAddress($url, 'local') === null;
	}//end localModelRefuses()

	/**
	 * An address as the host part of a URL: IPv6 goes in brackets.
	 *
	 * @param string $address The address.
	 *
	 * @return string
	 */
	private function asHost(string $address): string {
		if (str_contains($address, ':') === true) {
			return '[' . $address . ']';
		}

		return $address;
	}//end asHost()
}//end class
