<?php

/**
 * dossiq verifies portaliq's X-Portal-Subject assertion, fail-closed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Portal
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-resident-starts-a-woo-request-from-the-portal-req-portal-020
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalAssertionVerifier;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Mints assertions the way portaliq's PortalJwtService does and checks each refusal.
 *
 * @covers \OCA\Dossiq\Portal\PortalAssertionVerifier
 */
class PortalAssertionVerifierTest extends TestCase {

	private const SECRET = 'a-dedicated-portaliq-secret-0123';

	/**
	 * Base64-url encode without padding, as portaliq does.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 */
	private static function b64(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end b64()

	/**
	 * Mint an assertion in portaliq's frozen wire format.
	 *
	 * @param array<string, mixed> $overrides Claims to change; a null value removes the claim.
	 * @param array<string, mixed> $header    The header.
	 * @param string               $secret    The signing secret.
	 *
	 * @return string
	 */
	public static function mint(array $overrides = [], array $header = ['alg' => 'HS256', 'typ' => 'JWT'], string $secret = self::SECRET): string {
		$now = time();
		$claims = array_merge(
			[
				'sub' => 'subject-ref-anna',
				'audience' => 'client',
				'organisation' => 'org-1',
				'trust' => 'substantial',
				'jti' => 'jti-1',
				'use' => 'assertion',
				'iat' => $now,
				'exp' => ($now + 60),
				'iss' => 'portaliq',
			],
			$overrides
		);
		$claims = array_filter($claims, fn (mixed $value): bool => $value !== null);
		$head = self::b64((string)json_encode($header));
		$body = self::b64((string)json_encode($claims));

		return $head . '.' . $body . '.' . self::b64(hash_hmac('sha256', $head . '.' . $body, $secret, true));
	}//end mint()

	/**
	 * A verifier with the test secret.
	 *
	 * @return PortalAssertionVerifier
	 */
	private function verifier(): PortalAssertionVerifier {
		return new PortalAssertionVerifier(config: null, secretOverride: self::SECRET);
	}//end verifier()

	/**
	 * A fresh portaliq assertion verifies and answers its claims.
	 *
	 * @return void
	 */
	public function testAPortaliqAssertionVerifies(): void {
		$claims = $this->verifier()->verify(self::mint());
		self::assertIsArray($claims);
		self::assertSame('subject-ref-anna', $claims['sub']);
		self::assertSame('client', $claims['audience']);
	}//end testAPortaliqAssertionVerifies()

	/**
	 * Every forged, stale or confused token is refused.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refusedTokens(): array {
		$now = time();
		return [
			'expired' => [self::mint(['exp' => ($now - 1)])],
			'no exp' => [self::mint(['exp' => null])],
			'wrong secret' => [self::mint([], ['alg' => 'HS256', 'typ' => 'JWT'], 'another-secret-of-enough-length')],
			'a session token' => [self::mint(['use' => null])],
			'alg none' => [self::mint([], ['alg' => 'none', 'typ' => 'JWT'])],
			'alg RS256' => [self::mint([], ['alg' => 'RS256', 'typ' => 'JWT'])],
			'garbage' => ['not.a.token'],
			'two segments' => ['abc.def'],
			'empty' => [''],
			'no subject' => [self::mint(['sub' => ''])],
			'issued in the future' => [self::mint(['iat' => ($now + 3600), 'exp' => ($now + 3660)])],
			'another issuer' => [self::mint(['iss' => 'someone-else'])],
		];
	}//end refusedTokens()

	/**
	 * Each refused token answers null and never throws.
	 *
	 * @param string $token The token.
	 *
	 * @dataProvider refusedTokens
	 *
	 * @return void
	 */
	public function testARefusedTokenAnswersNull(string $token): void {
		self::assertNull($this->verifier()->verify($token));
	}//end testARefusedTokenAnswersNull()

	/**
	 * The secret is portaliq's dedicated one, read from portaliq's app config.
	 *
	 * @return void
	 */
	public function testTheSecretIsPortaliqsDedicatedOne(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($app === 'portaliq' && $key === 'jwt_signing_secret') ? self::SECRET : $default
		);

		self::assertIsArray((new PortalAssertionVerifier(config: $config))->verify(self::mint()));
	}//end testTheSecretIsPortaliqsDedicatedOne()

	/**
	 * Without a usable dedicated secret nothing verifies: portaliq will not mint either.
	 *
	 * @return void
	 */
	public function testWithoutADedicatedSecretNothingVerifies(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('short');
		$config->method('getSystemValue')->willReturn(self::SECRET);

		self::assertNull((new PortalAssertionVerifier(config: $config))->verify(self::mint()));
	}//end testWithoutADedicatedSecretNothingVerifies()
}//end class
