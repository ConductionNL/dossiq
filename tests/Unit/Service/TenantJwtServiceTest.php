<?php

/**
 * TenantJwtService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-05-auth-jwt-tenant-claim/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Procest\Service\TenantJwtService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Procest\Service\TenantJwtService
 */
class TenantJwtServiceTest extends TestCase {
	private const SECRET = 'a-very-secret-test-secret-32+chars-long!';

	public function testConstructorRejectsShortSecret(): void {
		$this->expectException(InvalidArgumentException::class);
		new TenantJwtService(signingSecret: 'short');
	}

	/**
	 * The service exposes no token minter. Procest validates tenant JWTs
	 * issued by the external broker; a minting method here would let anything
	 * that can reach it assert an arbitrary tenant and role set.
	 *
	 * @return void
	 */
	public function testServiceExposesNoTokenMinter(): void {
		$this->assertFalse(
			condition: method_exists(TenantJwtService::class, 'createToken'),
			message: 'TenantJwtService must not mint tokens.'
		);
		$this->assertFalse(
			condition: method_exists(TenantJwtService::class, 'createTokenFromSaml'),
			message: 'TenantJwtService must not mint tokens from a SAML assertion.'
		);
	}

	public function testValidateAcceptsABrokerIssuedToken(): void {
		$svc = new TenantJwtService(signingSecret: self::SECRET);

		$claims = $svc->validate(
			$this->brokerToken(
				[
					'sub' => 'alice',
					'tenant_id' => 'tenant-uuid-1',
					'tenant_slug' => 'amsterdam',
					'roles' => ['tenant_admin'],
					'iss' => 'procest',
				]
			)
		);

		$this->assertSame('alice', $claims['sub']);
		$this->assertSame('tenant-uuid-1', $claims['tenant_id']);
		$this->assertSame('amsterdam', $claims['tenant_slug']);
		$this->assertSame(['tenant_admin'], $claims['roles']);
		$this->assertSame('procest', $claims['iss']);
	}

	public function testValidateRejectsForgedSignature(): void {
		$svc = new TenantJwtService(signingSecret: self::SECRET);
		$tok = $this->brokerToken(['sub' => 'alice']);
		$parts = explode('.', $tok);
		// Tamper with the payload.
		$forged = $parts[0] . '.' . rtrim(strtr(base64_encode('{"sub":"evil"}'), '+/', '-_'), '=') . '.' . $parts[2];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Invalid JWT signature');
		$svc->validate($forged);
	}

	public function testValidateRejectsATokenSignedWithAnotherSecret(): void {
		$svc = new TenantJwtService(signingSecret: self::SECRET);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Invalid JWT signature');
		$svc->validate($this->brokerToken(['sub' => 'mallory'], secret: 'a-different-secret-32-chars-long!!!!'));
	}

	public function testValidateRejectsMalformed(): void {
		$svc = new TenantJwtService(signingSecret: self::SECRET);
		$this->expectException(RuntimeException::class);
		$svc->validate('not.a.jwt.at.all');
	}

	public function testValidateRejectsExpired(): void {
		$svc = new TenantJwtService(signingSecret: self::SECRET);
		$tok = $this->brokerToken(['sub' => 'alice', 'exp' => (time() - 10)]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Expired JWT');
		$svc->validate($tok);
	}

	public function testExtractTenantId(): void {
		$svc = new TenantJwtService(signingSecret: self::SECRET);
		$claims = ['tenant_id' => 't-123'];
		$this->assertSame('t-123', $svc->extractTenantId($claims));
	}

	public function testExtractTenantIdThrowsWhenMissing(): void {
		$svc = new TenantJwtService(signingSecret: self::SECRET);
		$this->expectException(RuntimeException::class);
		$svc->extractTenantId([]);
	}

	/**
	 * Mint a token the way the external broker does — HS256 over
	 * `<header>.<claims>`, base64url, unpadded. Deliberately built here in the
	 * test rather than by the service: the service under test validates
	 * tokens, it does not issue them.
	 *
	 * @param array<string, mixed> $claims Claim set; `exp` defaults to +1h.
	 * @param string|null $secret Signing secret; defaults to the service's.
	 *
	 * @return string Compact JWT.
	 */
	private function brokerToken(array $claims, ?string $secret = null): string {
		$claims += ['exp' => (time() + 3600), 'iat' => time()];

		$b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

		$hPart = $b64((string)json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
		$cPart = $b64((string)json_encode($claims, JSON_UNESCAPED_SLASHES));
		$sig = $b64(hash_hmac('sha256', $hPart . '.' . $cPart, ($secret ?? self::SECRET), true));

		return $hPart . '.' . $cPart . '.' . $sig;
	}
}
