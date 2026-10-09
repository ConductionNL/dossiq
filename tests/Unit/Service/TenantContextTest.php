<?php

/**
 * TenantContext Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\TenantContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\TenantContext
 */
class TenantContextTest extends TestCase {
	public function testUnboundContextReportsUnbound(): void {
		$ctx = new TenantContext();
		$this->assertFalse($ctx->isBound());
	}

	public function testBindMakesContextReadable(): void {
		$ctx = new TenantContext();
		$ctx->bind(
			tenant: ['uuid' => 'aaa', 'slug' => 'amsterdam', 'displayName' => 'Amsterdam']
		);

		$this->assertTrue($ctx->isBound());
		$this->assertSame('aaa', $ctx->getTenantId());
		$this->assertSame('amsterdam', $ctx->getSlug());
		$this->assertSame('Amsterdam', $ctx->getTenant()['displayName']);
	}

	/**
	 * The context carries the tenant and no schema name (REQ-TIS-004).
	 *
	 * @return void
	 */
	public function testBindTakesTheTenantOnly(): void {
		$ctx = new TenantContext();
		$ctx->bind(tenant: ['uuid' => 'aaa', 'slug' => 'amsterdam']);

		$this->assertTrue($ctx->isBound());
		$this->assertSame('aaa', $ctx->getTenantId());
		$this->assertSame('amsterdam', $ctx->getSlug());
		$this->assertSame(1, (new \ReflectionMethod(TenantContext::class, 'bind'))->getNumberOfParameters());
		$this->assertFalse(method_exists($ctx, 'getSchemaName'), 'TenantContext must carry no schema name');
	}

	public function testGetTenantIdThrowsWhenUnbound(): void {
		$this->expectException(RuntimeException::class);
		(new TenantContext())->getTenantId();
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
		$ctx->bind(['uuid' => 'aaa']);
		$ctx->reset();
		$this->assertFalse($ctx->isBound());
	}

	public function testFallsBackToIdWhenUuidMissing(): void {
		$ctx = new TenantContext();
		$ctx->bind(['id' => 'abc-123', 'slug' => 's']);
		$this->assertSame('abc-123', $ctx->getTenantId());
	}
}
