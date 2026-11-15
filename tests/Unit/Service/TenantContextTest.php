<?php

/**
 * TenantContext Unit Tests
 *
 * The context resolves the request's tenant itself, on first read, from
 * OpenRegister's active organisation through `TenantSessionService`. No
 * middleware binds it (Q3, Ruben 2026-10-08).
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
 * @spec openspec/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\TenantContext;
use OCA\Dossiq\Service\TenantSessionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\TenantContext
 */
class TenantContextTest extends TestCase {
	/**
	 * A context over a session service answering the given tenant.
	 *
	 * @param array<string, mixed>|null $tenant The tenant, or null.
	 * @param int                       $reads  How often activeTenant() may be asked.
	 *
	 * @return TenantContext The context.
	 */
	private function contextAnswering(?array $tenant, int $reads = 1): TenantContext {
		$session = $this->createMock(TenantSessionService::class);
		$session->expects($this->exactly($reads))->method('activeTenant')->willReturn($tenant);

		return new TenantContext(session: $session);
	}

	/**
	 * The active organisation is read once and carried for the request.
	 *
	 * @return void
	 */
	public function testTheTenantIsResolvedOnceOnFirstRead(): void {
		$ctx = $this->contextAnswering(['uuid' => 'aaa', 'id' => 'aaa', 'slug' => 'amsterdam', 'status' => 'active', 'displayName' => 'Amsterdam']);

		$this->assertTrue($ctx->isBound());
		$this->assertSame('aaa', $ctx->getTenantId());
		$this->assertSame('amsterdam', $ctx->getSlug());
		$this->assertSame('active', $ctx->getStatus());
		$this->assertSame('Amsterdam', $ctx->getTenant()['displayName']);
	}

	/**
	 * No bind(): nothing outside OpenRegister's answer can set the tenant.
	 *
	 * @return void
	 */
	public function testThereIsNoBind(): void {
		$this->assertFalse(method_exists(TenantContext::class, 'bind'));
	}

	/**
	 * No active organisation, an unbound context.
	 *
	 * @return void
	 */
	public function testNoActiveOrganisationLeavesTheContextUnbound(): void {
		$ctx = $this->contextAnswering(null);

		$this->assertFalse($ctx->isBound());
		$this->expectException(RuntimeException::class);
		$ctx->getTenantId();
	}

	public function testGetTenantThrowsWhenUnbound(): void {
		$this->expectException(RuntimeException::class);
		$this->contextAnswering(null)->getTenant();
	}

	public function testGetSlugThrowsWhenUnbound(): void {
		$this->expectException(RuntimeException::class);
		$this->contextAnswering(null)->getSlug();
	}

	public function testGetStatusThrowsWhenUnbound(): void {
		$this->expectException(RuntimeException::class);
		$this->contextAnswering(null)->getStatus();
	}

	/**
	 * After reset() the next read asks again.
	 *
	 * @return void
	 */
	public function testResetResolvesAgain(): void {
		$ctx = $this->contextAnswering(['uuid' => 'aaa'], reads: 2);
		$this->assertTrue($ctx->isBound());
		$ctx->reset();
		$this->assertTrue($ctx->isBound());
	}

	public function testFallsBackToIdWhenUuidMissing(): void {
		$ctx = $this->contextAnswering(['id' => 'abc-123', 'slug' => 's']);
		$this->assertSame('abc-123', $ctx->getTenantId());
	}
}
