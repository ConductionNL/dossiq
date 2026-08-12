<?php

/**
 * TenantContext Unit Tests
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\TenantContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Procest\Service\TenantContext
 */
class TenantContextTest extends TestCase {
	public function testUnboundContextReportsUnbound(): void {
		$ctx = new TenantContext();
		$this->assertFalse($ctx->isBound());
	}

	public function testBindMakesContextReadable(): void {
		$ctx = new TenantContext();
		$ctx->bind(
			tenant: ['uuid' => 'aaa', 'slug' => 'amsterdam', 'displayName' => 'Amsterdam'],
			schemaName: 'tenant_aaa_amsterdam'
		);

		$this->assertTrue($ctx->isBound());
		$this->assertSame('aaa', $ctx->getTenantId());
		$this->assertSame('amsterdam', $ctx->getSlug());
		$this->assertSame('tenant_aaa_amsterdam', $ctx->getSchemaName());
		$this->assertSame('Amsterdam', $ctx->getTenant()['displayName']);
	}

	public function testGetTenantIdThrowsWhenUnbound(): void {
		$this->expectException(RuntimeException::class);
		(new TenantContext())->getTenantId();
	}

	public function testGetSchemaNameThrowsWhenUnbound(): void {
		$this->expectException(RuntimeException::class);
		(new TenantContext())->getSchemaName();
	}

	public function testGetTenantThrowsWhenUnbound(): void {
		$this->expectException(RuntimeException::class);
		(new TenantContext())->getTenant();
	}

	public function testGetSlugThrowsWhenUnbound(): void {
		$this->expectException(RuntimeException::class);
		(new TenantContext())->getSlug();
	}

	public function testResetClearsBoundContext(): void {
		$ctx = new TenantContext();
		$ctx->bind(['uuid' => 'aaa'], 'tenant_aaa');
		$ctx->reset();
		$this->assertFalse($ctx->isBound());
	}

	public function testFallsBackToIdWhenUuidMissing(): void {
		$ctx = new TenantContext();
		$ctx->bind(['id' => 'abc-123', 'slug' => 's'], 'tenant_abc123_s');
		$this->assertSame('abc-123', $ctx->getTenantId());
	}
}
