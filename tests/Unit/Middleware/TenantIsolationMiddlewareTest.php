<?php

/**
 * TenantIsolationMiddleware Unit Tests
 *
 * Verifies that the middleware sets the Postgres search_path only when a
 * tenant context is bound, refuses unsafe identifiers, and resets the
 * search_path after the response or on exception.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Middleware
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

namespace OCA\Procest\Tests\Unit\Middleware;

use InvalidArgumentException;
use OCA\Procest\Middleware\TenantIsolationMiddleware;
use OCA\Procest\Service\TenantContext;
use OCA\Procest\Service\TenantSchemaProvisioner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Procest\Middleware\TenantIsolationMiddleware
 *
 * @uses \OCA\Procest\Service\TenantContext
 */
class TenantIsolationMiddlewareTest extends TestCase {
	/**
	 * Without a bound context the middleware MUST NOT touch the DB.
	 */
	public function testBeforeControllerIsNoOpWithoutBoundContext(): void {
		$context = new TenantContext();
		$provisioner = $this->createMock(TenantSchemaProvisioner::class);
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);

		$db->expects($this->never())->method('executeStatement');

		$mw = new TenantIsolationMiddleware(
			context: $context,
			provisioner: $provisioner,
			db: $db,
			logger: $logger,
		);

		$controller = $this->createMock(Controller::class);
		$mw->beforeController($controller, 'index');
	}

	/**
	 * Bound context triggers a `SET search_path` with the validated schema.
	 */
	public function testBeforeControllerSetsSearchPathWhenBound(): void {
		$context = new TenantContext();
		$provisioner = $this->createMock(TenantSchemaProvisioner::class);
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);

		$context->bind(['uuid' => 'aaa', 'slug' => 's'], 'tenant_aaa_s');

		$provisioner->expects($this->once())
			->method('assertSafeIdentifier')
			->with('tenant_aaa_s');

		$db->expects($this->once())
			->method('executeStatement')
			->with($this->stringContains('SET search_path TO "tenant_aaa_s"'));

		$mw = new TenantIsolationMiddleware(
			context: $context,
			provisioner: $provisioner,
			db: $db,
			logger: $logger,
		);

		$controller = $this->createMock(Controller::class);
		$mw->beforeController($controller, 'index');
	}

	/**
	 * Refuses to SET search_path when the schema name fails the identifier guard.
	 */
	public function testRefusesToSetUnsafeSearchPath(): void {
		$context = new TenantContext();
		$provisioner = $this->createMock(TenantSchemaProvisioner::class);
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);

		$provisioner->method('assertSafeIdentifier')
			->willThrowException(new InvalidArgumentException('bad ident'));

		$db->expects($this->never())->method('executeStatement');

		$mw = new TenantIsolationMiddleware(
			context: $context,
			provisioner: $provisioner,
			db: $db,
			logger: $logger,
		);

		$mw->applySearchPath('Bad-Identifier');
	}

	/**
	 * After the response the search_path is reset to `public`.
	 */
	public function testAfterControllerResetsSearchPath(): void {
		$context = new TenantContext();
		$provisioner = $this->createMock(TenantSchemaProvisioner::class);
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);

		$db->expects($this->once())
			->method('executeStatement')
			->with('SET search_path TO public');

		$mw = new TenantIsolationMiddleware(
			context: $context,
			provisioner: $provisioner,
			db: $db,
			logger: $logger,
		);

		$controller = $this->createMock(Controller::class);
		$response = $this->createMock(Response::class);
		$mw->afterController($controller, 'index', $response);
	}

	/**
	 * On exception the search_path is reset AND the exception is re-thrown.
	 */
	public function testAfterExceptionResetsAndRethrows(): void {
		$context = new TenantContext();
		$provisioner = $this->createMock(TenantSchemaProvisioner::class);
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);

		$db->expects($this->once())
			->method('executeStatement')
			->with('SET search_path TO public');

		$mw = new TenantIsolationMiddleware(
			context: $context,
			provisioner: $provisioner,
			db: $db,
			logger: $logger,
		);

		$controller = $this->createMock(Controller::class);
		$this->expectException(RuntimeException::class);
		$mw->afterException($controller, 'index', new RuntimeException('boom'));
	}
}
