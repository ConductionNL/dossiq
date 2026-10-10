<?php

/**
 * The middleware registrar registers no tenant isolation middleware.
 *
 * `TenantIsolationMiddleware` set a Postgres search_path to a schema nothing
 * created (dossiq#2470). It is deleted, and this test reads the registrar the
 * way the app framework does, through `IRegistrationContext`, so a stale
 * registration cannot come back unnoticed.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/tenant-isolation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\MiddlewareRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\AppInfo\Registrar\MiddlewareRegistrar
 */
class MiddlewareRegistrarTest extends TestCase {
	/**
	 * No isolation middleware is registered.
	 *
	 * @return void
	 */
	public function testTheIsolationMiddlewareIsNotRegistered(): void {
		$registered = $this->registrations();

		$this->assertNotEmpty($registered, 'the registrar registered nothing, so the assertion below proves nothing');
		$this->assertNotContains('OCA\Dossiq\Middleware\TenantIsolationMiddleware', $registered);
		foreach ($registered as $class) {
			$this->assertStringNotContainsString('Isolation', $class, $class.' looks like an isolation middleware');
		}
	}//end testTheIsolationMiddlewareIsNotRegistered()

	/**
	 * No tenant claim middleware is registered (REQ-TAO-001).
	 *
	 * @return void
	 */
	public function testNoClaimMiddlewareIsRegistered(): void {
		$registered = $this->registrations();

		$this->assertNotEmpty($registered, 'the registrar registered nothing, so the assertion below proves nothing');
		$this->assertNotContains('OCA\Dossiq\Middleware\TenantClaimValidationMiddleware', $registered);
	}//end testNoClaimMiddlewareIsRegistered()

	/**
	 * No tenant middleware is registered: the active organisation is OpenRegister's (REQ-TAO-002).
	 *
	 * The refusal of an organisation that is not active lives in the mandate
	 * middleware, which stays registered.
	 *
	 * @return void
	 */
	public function testNoTenantMiddlewareIsRegistered(): void {
		$registered = $this->registrations();

		$this->assertContains('OCA\Dossiq\Middleware\MandateValidationMiddleware', $registered);
		foreach ($registered as $class) {
			$short = substr($class, (int) strrpos($class, '\\') + 1);
			$this->assertStringStartsNotWith('Tenant', $short, $class.' is a tenant middleware');
		}
	}//end testNoTenantMiddlewareIsRegistered()

	/**
	 * Every middleware class the registrar registers, in order.
	 *
	 * @return array<int, string>
	 */
	private function registrations(): array {
		$registered = [];
		$context    = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerMiddleware')->willReturnCallback(
			static function (string $class) use (&$registered): void {
				$registered[] = ltrim($class, '\\');
			}
		);

		(new MiddlewareRegistrar())->register(context: $context);

		return $registered;
	}//end registrations()
}//end class
