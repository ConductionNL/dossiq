<?php

/**
 * OutboundUrlGuard Unit Tests
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

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The guard on names: what a resolver answers decides, and every answer counts.
 *
 * The address table is in OutboundRefusalCasesTest. This suite is the part no
 * copy could be tested on before, because each asked DNS itself.
 *
 * @covers \OCA\Dossiq\Service\Outbound\OutboundUrlGuard
 * @uses \OCA\Dossiq\Service\Outbound\HostResolver
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class OutboundUrlGuardTest extends TestCase {
	use OutboundFakes;

	private const NAMES = [
		'api.example.com' => ['93.184.216.34', '2001:4860:4860::8888'],
		'one-bad.example.com' => ['93.184.216.34', '10.0.0.5'],
		'bad-v6.example.com' => ['93.184.216.34', 'fe80::1'],
		'mapped.example.com' => ['::ffff:10.0.0.5'],
		'rebind.example.com' => ['127.0.0.1'],
		'ollama' => ['172.18.0.4'],
		'half-out.internal' => ['172.18.0.4', '93.184.216.34'],
		'garbage.example.com' => ['not-an-address'],
	];

	/**
	 * A public name answers with the first of its addresses.
	 *
	 * @return void
	 */
	public function testAPublicNameAnswersWithItsFirstAddress(): void {
		$guard = $this->guard(names: self::NAMES);

		$this->assertSame('93.184.216.34', $guard->publicAddress('https://api.example.com/v1'));
		$this->assertSame('93.184.216.34', $guard->publicAddress('HTTPS://API.Example.COM:8443/v1'));
	}//end testAPublicNameAnswersWithItsFirstAddress()

	/**
	 * One address that does not pass refuses the name, wherever it stands in the answer.
	 *
	 * @return void
	 */
	public function testOneBadAddressRefusesTheWholeName(): void {
		$guard = $this->guard(names: self::NAMES);

		$this->assertNull($guard->publicAddress('https://one-bad.example.com/'));
		$this->assertNull($guard->publicAddress('https://bad-v6.example.com/'));
		$this->assertNull($guard->publicAddress('https://mapped.example.com/'));
		$this->assertNull($guard->publicAddress('https://rebind.example.com/'));
		$this->assertNull($guard->ownNetworkAddress('http://half-out.internal:11434'));
	}//end testOneBadAddressRefusesTheWholeName()

	/**
	 * A name nothing answers for, and an answer that is no address, are refused.
	 *
	 * @return void
	 */
	public function testNoAnswerAndNoAddressAreRefused(): void {
		$guard = $this->guard(names: self::NAMES);

		$this->assertNull($guard->publicAddress('https://unknown.example.com/'));
		$this->assertNull($guard->ownNetworkAddress('http://unknown.internal/'));
		$this->assertNull($guard->publicAddress('https://garbage.example.com/'));
		$this->assertNull($guard->ownNetworkAddress('http://garbage.example.com/'));
	}//end testNoAnswerAndNoAddressAreRefused()

	/**
	 * The two questions are inverses: no name passes both.
	 *
	 * @return void
	 */
	public function testNoNamePassesBothQuestions(): void {
		$guard = $this->guard(names: self::NAMES);

		$this->assertSame('172.18.0.4', $guard->ownNetworkAddress('http://ollama:11434'));
		$this->assertNull($guard->publicAddress('https://ollama/'));
		$this->assertNull($guard->ownNetworkAddress('https://api.example.com/'));
		$this->assertNull($guard->ownNetworkAddress('http://mapped.example.com/'), 'the allow-list does not list the mapped spelling');
	}//end testNoNamePassesBothQuestions()

	/**
	 * Outside the own network only https passes; inside, http does too, and nothing else.
	 *
	 * @return void
	 */
	public function testSchemesPerQuestion(): void {
		$guard = $this->guard(names: self::NAMES);

		$this->assertNull($guard->publicAddress('http://api.example.com/'));
		$this->assertNull($guard->publicAddress('ftp://api.example.com/'));
		$this->assertSame('172.18.0.4', $guard->ownNetworkAddress('https://ollama/'));
		$this->assertNull($guard->ownNetworkAddress('gopher://ollama/'));
		$this->assertNull($guard->ownNetworkAddress('//ollama/'));
	}//end testSchemesPerQuestion()

	/**
	 * Every refusal is logged at warning, and an accepted URL logs nothing.
	 *
	 * @return void
	 */
	public function testARefusalIsLoggedAndAnAcceptanceIsNot(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(3))->method('warning')->with($this->stringStartsWith('Outbound URL refused: '));
		$guard = $this->guard(names: self::NAMES, logger: $logger);

		$guard->publicAddress('https://api.example.com/');
		$guard->publicAddress('file:///etc/passwd');
		$guard->publicAddress('https://unknown.example.com/');
		$guard->publicAddress('https://rebind.example.com/');
	}//end testARefusalIsLoggedAndAnAcceptanceIsNot()

	/**
	 * The request options refuse redirects, verify TLS and pin the name to the approved address.
	 *
	 * @return void
	 */
	public function testRequestOptionsPinTheNameToTheApprovedAddress(): void {
		$guard = $this->guard(names: self::NAMES);

		$this->assertSame(
			['allow_redirects' => false, 'verify' => true, 'curl' => [CURLOPT_RESOLVE => ['api.example.com:443:93.184.216.34']]],
			$guard->requestOptions(url: 'https://API.example.com/v1', address: '93.184.216.34')
		);
		$this->assertSame(
			['api.example.com:8443:93.184.216.34'],
			$guard->requestOptions(url: 'https://api.example.com:8443/v1', address: '93.184.216.34')['curl'][CURLOPT_RESOLVE]
		);
		$this->assertSame(
			['ollama:80:172.18.0.4'],
			$guard->requestOptions(url: 'http://ollama/api/generate', address: '172.18.0.4')['curl'][CURLOPT_RESOLVE]
		);
	}//end testRequestOptionsPinTheNameToTheApprovedAddress()

	/**
	 * An IP literal gets the same options without a pin: there is no name to re-resolve.
	 *
	 * @return void
	 */
	public function testALiteralNeedsNoPin(): void {
		$guard = $this->guard(names: []);

		$this->assertSame(
			['allow_redirects' => false, 'verify' => true],
			$guard->requestOptions(url: 'https://93.184.216.34/', address: '93.184.216.34')
		);
		$this->assertSame(
			['allow_redirects' => false, 'verify' => true],
			$guard->requestOptions(url: 'http://[::1]:11434', address: '::1')
		);
	}//end testALiteralNeedsNoPin()
}//end class
