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

	public function testCreateTokenAndValidateRoundTrip(): void {
		$svc = new TenantJwtService(signingSecret: self::SECRET);
		$tok = $svc->createToken(
			subject: 'alice',
			tenantId: 'tenant-uuid-1',
			tenantSlug: 'amsterdam',
			roles: ['tenant_admin']
		);

		$claims = $svc->validate($tok);
		$this->assertSame('alice', $claims['sub']);
		$this->assertSame('tenant-uuid-1', $claims['tenant_id']);
		$this->assertSame('amsterdam', $claims['tenant_slug']);
		$this->assertSame(['tenant_admin'], $claims['roles']);
		$this->assertSame('procest', $claims['iss']);
	}

	public function testValidateRejectsForgedSignature(): void {
		$svc = new TenantJwtService(signingSecret: self::SECRET);
		$tok = $svc->createToken('alice', 't', 's');
		$parts = explode('.', $tok);
		// Tamper with the payload.
		$forged = $parts[0] . '.' . rtrim(strtr(base64_encode('{"sub":"evil"}'), '+/', '-_'), '=') . '.' . $parts[2];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Invalid JWT signature');
		$svc->validate($forged);
	}

	public function testValidateRejectsMalformed(): void {
		$svc = new TenantJwtService(signingSecret: self::SECRET);
		$this->expectException(RuntimeException::class);
		$svc->validate('not.a.jwt.at.all');
	}

	public function testValidateRejectsExpired(): void {
		$svc = new TenantJwtService(signingSecret: self::SECRET);
		$tok = $svc->createToken('alice', 't', 's', [], ttl: -10);
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

	public function testCreateTokenFromSamlMapsAssertion(): void {
		$svc = new TenantJwtService(signingSecret: self::SECRET);
		$tok = $svc->createTokenFromSaml([
			'subject' => 'bob',
			'tenantId' => 'tx',
			'tenantSlug' => 'rotterdam',
			'eherkenningLevel' => '3',
			'roles' => ['case_handler'],
		]);

		$claims = $svc->validate($tok);
		$this->assertSame('bob', $claims['sub']);
		$this->assertSame('tx', $claims['tenant_id']);
		$this->assertContains('case_handler', $claims['roles']);
		$this->assertContains('eh:level:3', $claims['roles']);
	}

	public function testCreateTokenFromSamlRejectsMissingField(): void {
		$svc = new TenantJwtService(signingSecret: self::SECRET);
		$this->expectException(InvalidArgumentException::class);
		$svc->createTokenFromSaml(['subject' => 'b']);
	}
}
