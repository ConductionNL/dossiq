<?php

/**
 * TenantSchemaProvisioner Unit Tests
 *
 * Focused on the identifier-validation guard that protects the DDL embed.
 * Live DDL paths require a Postgres connection and are covered by the
 * integration suite in chain member 12.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-03-schema-provisioning/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Dossiq\Service\TenantSchemaProvisioner;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\Dossiq\Service\TenantSchemaProvisioner
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

	/**
	 * Build a provisioner whose register lookup returns the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Register rows.
	 *
	 * @return TenantSchemaProvisioner
	 */
	private function provisionerReturning(array $rows): TenantSchemaProvisioner {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);

		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturn($result);

		return new TenantSchemaProvisioner(db: $db, logger: $this->createMock(LoggerInterface::class));
	}//end provisionerReturning()

	/**
	 * Call the private marker resolver.
	 *
	 * @param TenantSchemaProvisioner $provisioner Subject.
	 *
	 * @return array<int, string>
	 */
	private function markersOf(TenantSchemaProvisioner $provisioner): array {
		$method = new ReflectionMethod(TenantSchemaProvisioner::class, 'shardTableMarkers');
		$method->setAccessible(true);

		return $method->invoke($provisioner);
	}//end markersOf()

	/**
	 * The shard-table marker is built from the register's NUMERIC id.
	 *
	 * This is the whole point of the fix it replaces: OpenRegister names a
	 * shard table `<prefix>openregister_table_<registerId>_<schemaId>` and never
	 * uses the slug, so a slug-shaped prefix matched zero tables and every
	 * tenant was provisioned with an empty schema — reported as success.
	 *
	 * @return void
	 */
	public function testShardTableMarkersAreBuiltFromNumericRegisterIds(): void {
		$markers = $this->markersOf($this->provisionerReturning([['id' => 17], ['id' => 2424]]));

		$this->assertSame(['openregister_table_17_', 'openregister_table_2424_'], $markers);
	}//end testShardTableMarkersAreBuiltFromNumericRegisterIds()

	/**
	 * The marker carries no `oc_` prefix, which is configurable per install.
	 *
	 * @return void
	 */
	public function testShardTableMarkerIsNotAnchoredOnTheTablePrefix(): void {
		$markers = $this->markersOf($this->provisionerReturning([['id' => 17]]));

		$this->assertStringStartsNotWith('oc_', $markers[0]);
		$this->assertStringContainsString('openregister_table_', $markers[0]);
	}//end testShardTableMarkerIsNotAnchoredOnTheTablePrefix()

	/**
	 * No register resolved yields no markers rather than a match-everything.
	 *
	 * An empty marker list must NOT degrade into cloning every table in the
	 * public schema; the caller logs and clones nothing instead.
	 *
	 * @return void
	 */
	public function testNoRegisterResolvedYieldsNoMarkers(): void {
		$this->assertSame([], $this->markersOf($this->provisionerReturning([])));
	}//end testNoRegisterResolvedYieldsNoMarkers()

	/**
	 * A row with a missing or unusable id is skipped, not turned into `_0_`.
	 *
	 * @return void
	 */
	public function testUnusableRegisterIdsAreSkipped(): void {
		$markers = $this->markersOf($this->provisionerReturning([['id' => 0], [], ['id' => 17]]));

		$this->assertSame(['openregister_table_17_'], $markers);
	}//end testUnusableRegisterIdsAreSkipped()

	/**
	 * A failing register lookup yields no markers rather than throwing.
	 *
	 * @return void
	 */
	public function testAFailingRegisterLookupYieldsNoMarkers(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willThrowException(new \RuntimeException('read failed'));

		$provisioner = new TenantSchemaProvisioner(db: $db, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame([], $this->markersOf($provisioner));
	}//end testAFailingRegisterLookupYieldsNoMarkers()

}//end class
