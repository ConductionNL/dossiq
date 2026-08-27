<?php

/**
 * MandateValidationMiddleware Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-06-mandate-validation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Middleware;

use OCA\Dossiq\Middleware\MandateValidationMiddleware;
use OCA\Dossiq\Service\TenantAuthenticationService;
use OCA\Dossiq\Service\TenantContext;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Middleware\MandateValidationMiddleware
 */
class MandateValidationMiddlewareTest extends TestCase {
	private function newMiddleware(): MandateValidationMiddleware {
		return new MandateValidationMiddleware(
			request: $this->createMock(IRequest::class),
			userSession: $this->createMock(IUserSession::class),
			context: new TenantContext(),
			authService: $this->createMock(TenantAuthenticationService::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	public function testResolveActionMapsPostToCreate(): void {
		$mw = $this->newMiddleware();
		$this->assertSame('create', $mw->resolveAction('POST', '/api/cases'));
	}

	public function testResolveActionMapsPatchToEdit(): void {
		$mw = $this->newMiddleware();
		$this->assertSame('edit', $mw->resolveAction('PATCH', '/api/cases/abc'));
	}

	public function testResolveActionMapsDeleteToDelete(): void {
		$mw = $this->newMiddleware();
		$this->assertSame('delete', $mw->resolveAction('DELETE', '/api/cases/abc'));
	}

	public function testResolveActionDetectsStatusUpdateFromUrl(): void {
		$mw = $this->newMiddleware();
		$this->assertSame('status_update', $mw->resolveAction('POST', '/api/case/abc/transition'));
		$this->assertSame('status_update', $mw->resolveAction('PATCH', '/api/cases/abc/status'));
	}

	public function testResolveActionReturnsNullForGet(): void {
		$mw = $this->newMiddleware();
		$this->assertNull($mw->resolveAction('GET', '/api/cases/abc'));
	}
}
