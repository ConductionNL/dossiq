<?php

/**
 * TenantService makes a tenant's audit anchor once, and refuses honestly.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Tests\Support\MakesTenantAnchors;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\TenantService
 * @uses \OCA\Dossiq\Service\TenantOrganisationResolver
 */
class TenantServiceTest extends TestCase {
	use MakesTenantAnchors;

	/**
	 * An Organisation.
	 */
	private const ORG = '0f9e8d7c-6b5a-4321-8fed-cba987654321';

	/**
	 * Fresh store per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->startAnchorStore();
	}//end setUp()

	/**
	 * An Organisation without an anchor gets one, and true.
	 *
	 * @return void
	 */
	public function testAnOrganisationWithoutAnAnchorGetsOne(): void {
		$this->givenOrganisation(uuid: self::ORG, slug: 'westdorp', name: 'Gemeente Westdorp');

		$this->assertTrue($this->realTenantService()->ensureAuditAnchor(self::ORG));
		$this->assertSame(['westdorp'], array_column($this->storedAnchors(), 'slug'));
	}//end testAnOrganisationWithoutAnAnchorGetsOne()

	/**
	 * An existing anchor answers true and is not written again.
	 *
	 * @return void
	 */
	public function testAnExistingAnchorIsLeftAlone(): void {
		$this->anchorStore->seed(schema: 'tenant', uuid: self::ORG, row: ['slug' => 'oud', 'displayName' => 'Oud']);

		$this->assertTrue($this->realTenantService()->ensureAuditAnchor(self::ORG));
		$this->assertSame(0, $this->anchorStore->writes);
	}//end testAnExistingAnchorIsLeftAlone()

	/**
	 * An empty id, or no OpenRegister, answers false and writes nothing.
	 *
	 * @return void
	 */
	public function testNoIdOrNoOpenRegisterAnswersFalse(): void {
		$this->givenOrganisation(uuid: self::ORG, slug: 'westdorp', name: 'Gemeente Westdorp');

		$this->assertFalse($this->realTenantService()->ensureAuditAnchor('  '));
		$this->assertFalse($this->realTenantService(openRegister: false)->ensureAuditAnchor(self::ORG));
		$this->assertSame(0, $this->anchorStore->writes);
	}//end testNoIdOrNoOpenRegisterAnswersFalse()

	/**
	 * A refused write answers false and is logged.
	 *
	 * @return void
	 */
	public function testARefusedWriteAnswersFalseAndIsLogged(): void {
		$this->givenOrganisation(uuid: self::ORG, slug: 'westdorp', name: 'Gemeente Westdorp');
		$this->anchorStore->refuseSaves = true;

		$this->assertFalse($this->realTenantService()->ensureAuditAnchor(self::ORG));
		$this->assertSame([], $this->storedAnchors());
		$this->assertSame('Dossiq: the tenant audit anchor could not be written', $this->anchorErrors[0][0] ?? '');
	}//end testARefusedWriteAnswersFalseAndIsLogged()
}//end class
