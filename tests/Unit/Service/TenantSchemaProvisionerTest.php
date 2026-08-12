<?php

/**
 * TenantSchemaProvisioner Unit Tests
 *
 * Focused on the identifier-validation guard that protects the DDL embed.
 * Live DDL paths require a Postgres connection and are covered by the
 * integration suite in chain member 12.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-03-schema-provisioning/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Procest\Service\TenantSchemaProvisioner;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\TenantSchemaProvisioner
 */
class TenantSchemaProvisionerTest extends TestCase {
	private TenantSchemaProvisioner $provisioner;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->provisioner = new TenantSchemaProvisioner(
			db: $db,
			logger: $logger,
		);
	}//end setUp()

	/**
	 * Valid identifier passes (lowercase alphanumeric + underscore).
	 *
	 * @return void
	 */
	public function testAssertSafeIdentifierAcceptsValid(): void {
		$this->provisioner->assertSafeIdentifier('tenant_a1b2c3d4_amsterdam');
		$this->expectNotToPerformAssertions();
	}//end testAssertSafeIdentifierAcceptsValid()

	/**
	 * Identifier with uppercase rejected.
	 *
	 * @return void
	 */
	public function testAssertSafeIdentifierRejectsUppercase(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->provisioner->assertSafeIdentifier('Tenant_Amsterdam');
	}//end testAssertSafeIdentifierRejectsUppercase()

	/**
	 * Identifier with a quote rejected (SQL-injection guard).
	 *
	 * @return void
	 */
	public function testAssertSafeIdentifierRejectsQuote(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->provisioner->assertSafeIdentifier('tenant"; DROP SCHEMA public; --');
	}//end testAssertSafeIdentifierRejectsQuote()

	/**
	 * Identifier with hyphen rejected (use underscore).
	 *
	 * @return void
	 */
	public function testAssertSafeIdentifierRejectsHyphen(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->provisioner->assertSafeIdentifier('tenant-amsterdam');
	}//end testAssertSafeIdentifierRejectsHyphen()

	/**
	 * Identifier exceeding 63 chars rejected.
	 *
	 * @return void
	 */
	public function testAssertSafeIdentifierRejectsOversize(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->provisioner->assertSafeIdentifier('tenant_' . str_repeat('x', 60));
	}//end testAssertSafeIdentifierRejectsOversize()

	/**
	 * Empty identifier rejected.
	 *
	 * @return void
	 */
	public function testAssertSafeIdentifierRejectsEmpty(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->provisioner->assertSafeIdentifier('');
	}//end testAssertSafeIdentifierRejectsEmpty()

	/**
	 * Identifier starting with digit rejected (PG identifier must start with letter).
	 *
	 * @return void
	 */
	public function testAssertSafeIdentifierRejectsLeadingDigit(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->provisioner->assertSafeIdentifier('1tenant');
	}//end testAssertSafeIdentifierRejectsLeadingDigit()
}//end class
