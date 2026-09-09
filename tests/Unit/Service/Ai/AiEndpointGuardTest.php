<?php

/**
 * AI endpoint guard unit tests.
 *
 * WHAT THE DEFECT WAS. The local-model branch rejected exactly one thing: a host
 * that resolved into 169.254.0.0/16. Everything else fell through to `return
 * true`, so `http://evil.example.com` configured as a LOCAL model passed the
 * SSRF check and the prompts were posted to it. "Local mode" confined nothing.
 * A host that failed to resolve passed as well, because the check was written as
 * `$ipAddress !== $host && inCidr(...)` and a failed `gethostbyname()` returns
 * the host unchanged.
 *
 * A deny-list cannot fix that no matter how long it gets, because the promise
 * "this stays on your instance" is a statement about what IS allowed. The guard
 * now carries an ALLOW-list for local: loopback, or the private address space
 * the instance itself sits in, and nothing else.
 *
 * THE ASSERTIONS THAT CARRY THE REQUIREMENT are
 * `testLocalRefusesAPublicAddress()` and `testLocalRefusesAHostThatCannotBe
 * Resolved()`. A suite that only checked "localhost is accepted" and "the
 * metadata range is refused" would have passed against the old guard.
 *
 * NO NETWORK IS TOUCHED. Every host used here is either an IP literal (resolved
 * to itself, no lookup) or `localhost` (answered from the system's own hosts
 * file). A test that depended on a public DNS answer would be a test that fails
 * on a disconnected laptop and passes in CI for the wrong reason.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Ai
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-assistance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Ai;

use OCA\Dossiq\Service\Ai\AiEndpointGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * "Local" is an allow-list, and the guard answers with an address.
 *
 * @covers \OCA\Dossiq\Service\Ai\AiEndpointGuard
 */
class AiEndpointGuardTest extends TestCase {

	private AiEndpointGuard $guard;

	/**
	 * Set up the guard under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->guard = new AiEndpointGuard($this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * A public address is refused for a LOCAL model.
	 *
	 * The defect in one assertion: 93.184.216.34 is a public address and the old
	 * guard accepted it as "local" because it is not in 169.254.0.0/16.
	 *
	 * @return void
	 */
	public function testLocalRefusesAPublicAddress(): void {
		$this->assertNull($this->guard->resolveSafeAddress('http://93.184.216.34:11434', 'local'));
		$this->assertNull($this->guard->resolveSafeAddress('https://8.8.8.8/', 'local'));
	}//end testLocalRefusesAPublicAddress()

	/**
	 * A host that cannot be resolved is refused, not waved through.
	 *
	 * @return void
	 */
	public function testLocalRefusesAHostThatCannotBeResolved(): void {
		$this->assertNull(
			$this->guard->resolveSafeAddress(
				'http://ollama.invalid.dossiq-test-does-not-resolve:11434',
				'local'
			)
		);
	}//end testLocalRefusesAHostThatCannotBeResolved()

	/**
	 * The cloud metadata range is refused for a local model.
	 *
	 * @return void
	 */
	public function testLocalRefusesTheCloudMetadataRange(): void {
		$this->assertNull($this->guard->resolveSafeAddress('http://169.254.169.254/', 'local'));
	}//end testLocalRefusesTheCloudMetadataRange()

	/**
	 * Loopback and the private ranges a container sidecar lives in are accepted,
	 * and the ACCEPTED ADDRESS comes back.
	 *
	 * @return void
	 */
	public function testLocalAcceptsLoopbackAndPrivateAddresses(): void {
		$this->assertSame(
			'127.0.0.1',
			$this->guard->resolveSafeAddress('http://127.0.0.1:11434', 'local')
		);
		$this->assertSame(
			'172.18.0.4',
			$this->guard->resolveSafeAddress('http://172.18.0.4:11434', 'local')
		);
		$this->assertSame(
			'10.1.2.3',
			$this->guard->resolveSafeAddress('http://10.1.2.3:11434', 'local')
		);
		$this->assertSame(
			'192.168.1.9',
			$this->guard->resolveSafeAddress('http://192.168.1.9:11434', 'local')
		);
		$this->assertSame(
			'::1',
			$this->guard->resolveSafeAddress('http://[::1]:11434', 'local')
		);
	}//end testLocalAcceptsLoopbackAndPrivateAddresses()

	/**
	 * `localhost` resolves through the system resolver and is accepted.
	 *
	 * @return void
	 */
	public function testLocalAcceptsLocalhostByName(): void {
		$address = $this->guard->resolveSafeAddress('http://localhost:11434', 'local');

		$this->assertNotNull($address);
		$this->assertStringStartsWith('127.', $address);
	}//end testLocalAcceptsLocalhostByName()

	/**
	 * A non-http scheme is refused for a local model.
	 *
	 * @return void
	 */
	public function testLocalRefusesANonHttpScheme(): void {
		$this->assertNull($this->guard->resolveSafeAddress('file:///etc/passwd', 'local'));
		$this->assertNull($this->guard->resolveSafeAddress('gopher://127.0.0.1/', 'local'));
	}//end testLocalRefusesANonHttpScheme()

	/**
	 * A cloud model must use https, and must not point back inside the instance.
	 *
	 * @return void
	 */
	public function testCloudRequiresHttpsAndAPublicAddress(): void {
		$this->assertNull(
			$this->guard->resolveSafeAddress('http://93.184.216.34/', 'cloud'),
			'plain http is refused for a cloud model'
		);
		$this->assertNull(
			$this->guard->resolveSafeAddress('https://127.0.0.1/', 'cloud'),
			'a cloud model may not point at loopback'
		);
		$this->assertNull(
			$this->guard->resolveSafeAddress('https://10.0.0.5/', 'cloud'),
			'a cloud model may not point into RFC1918'
		);
		$this->assertNull(
			$this->guard->resolveSafeAddress('https://169.254.169.254/', 'cloud'),
			'a cloud model may not point at the metadata service'
		);
		$this->assertSame(
			'93.184.216.34',
			$this->guard->resolveSafeAddress('https://93.184.216.34/', 'cloud'),
			'a public https endpoint is accepted'
		);
	}//end testCloudRequiresHttpsAndAPublicAddress()

	/**
	 * A URL with no host at all is refused.
	 *
	 * @return void
	 */
	public function testAUrlWithoutAHostIsRefused(): void {
		$this->assertNull($this->guard->resolveSafeAddress('', 'local'));
		$this->assertNull($this->guard->resolveSafeAddress('/api/generate', 'local'));
		$this->assertNull($this->guard->resolveSafeAddress('not a url', 'cloud'));
	}//end testAUrlWithoutAHostIsRefused()

	/**
	 * The local and cloud rules are INVERSES, not one a relaxation of the other.
	 *
	 * Written as a table because the previous shape had them overlap: local
	 * accepted everything cloud accepted, plus everything else.
	 *
	 * @return void
	 */
	public function testLocalAndCloudAcceptDisjointAddresses(): void {
		$cases = [
			// address, allowed as local, allowed as cloud.
			['127.0.0.1', true, false],
			['10.0.0.5', true, false],
			['172.16.0.1', true, false],
			['192.168.0.1', true, false],
			['169.254.169.254', false, false],
			['93.184.216.34', false, true],
			['8.8.8.8', false, true],
		];

		foreach ($cases as [$address, $localOk, $cloudOk]) {
			$local = $this->guard->resolveSafeAddress('https://' . $address . '/', 'local');
			$cloud = $this->guard->resolveSafeAddress('https://' . $address . '/', 'cloud');

			$this->assertSame($localOk, $local !== null, $address . ' as local');
			$this->assertSame($cloudOk, $cloud !== null, $address . ' as cloud');
		}
	}//end testLocalAndCloudAcceptDisjointAddresses()
}//end class
