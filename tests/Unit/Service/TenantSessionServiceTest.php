<?php

/**
 * TenantSessionService Unit Tests
 *
 * The tenant a request acts as is OpenRegister's active organisation, and only
 * when the user's `tenantUser` memberships list it (Q3, Ruben 2026-10-08).
 * dossiq keeps no tenant choice of its own in the session.
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

use OCA\Dossiq\Service\TenantSessionService;
use OCA\Dossiq\Tests\Support\MakesActiveOrganisationContext;
use OCP\ISession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\Dossiq\Service\TenantSessionService
 *
 * @uses \OCA\Dossiq\Service\TenantOrganisationResolver
 */
class TenantSessionServiceTest extends TestCase {
	use MakesActiveOrganisationContext;

	/**
	 * The organisation set active in OpenRegister is the tenant (REQ-TAO-002).
	 *
	 * The user belongs to A and B. B is active, so B is the answer, not the
	 * first membership.
	 *
	 * @return void
	 */
	public function testTheActiveTenantIsOpenRegistersActiveOrganisation(): void {
		$service = $this->activeOrganisationSession(
			active: 'org-b',
			stored: ['org-a' => $this->organisationRow(uuid: 'org-a'), 'org-b' => $this->organisationRow(uuid: 'org-b')],
			memberships: ['org-a', 'org-b'],
		);

		$this->assertSame('org-b', $service->activeTenantId());
		$this->assertSame('slug-org-b', $service->activeTenant()['slug']);
	}

	/**
	 * An active organisation without a tenantUser row is no tenant (REQ-TAO-002).
	 *
	 * @return void
	 */
	public function testAnActiveOrganisationTheUserHasNoMembershipOfIsNotTheTenant(): void {
		$service = $this->activeOrganisationSession(
			active: 'org-c',
			stored: ['org-c' => $this->organisationRow(uuid: 'org-c')],
			memberships: ['org-a'],
		);

		$this->assertNull($service->activeTenantId());
		$this->assertNull($service->activeTenant());
	}

	/**
	 * The service keeps no session key, no switch and no clear of its own.
	 *
	 * @return void
	 */
	public function testTheServiceKeepsNoSessionKeyOfItsOwn(): void {
		$class = new ReflectionClass(TenantSessionService::class);

		$this->assertFalse($class->hasConstant('SESSION_KEY'));
		$this->assertFalse($class->hasMethod('switchTo'));
		$this->assertFalse($class->hasMethod('clear'));
		foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
			$this->assertNotSame(ISession::class, (string) $parameter->getType(), 'the service must not take the PHP session');
		}
	}

	/**
	 * The status is the stored row's, not the session cache's default.
	 *
	 * OpenRegister rebuilds the cached active organisation without a status,
	 * so it reads `active`. A suspended organisation must still read suspended.
	 *
	 * @return void
	 */
	public function testTheStatusIsReadFromTheStoredOrganisation(): void {
		$service = $this->activeOrganisationSession(
			active: 'org-a',
			stored: ['org-a' => $this->organisationRow(uuid: 'org-a', status: 'suspended')],
			memberships: ['org-a'],
		);

		$this->assertSame('suspended', $service->activeTenant()['status']);
	}

	/**
	 * No active organisation, no tenant.
	 *
	 * @return void
	 */
	public function testNoActiveOrganisationIsNoTenant(): void {
		$service = $this->activeOrganisationSession(active: null, stored: [], memberships: ['org-a']);

		$this->assertNull($service->activeTenantId());
	}

	/**
	 * An active organisation whose stored row cannot be read is no tenant.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoredRowIsNoTenant(): void {
		$service = $this->activeOrganisationSession(active: 'org-a', stored: [], memberships: ['org-a']);

		$this->assertNull($service->activeTenantId());
	}

	/**
	 * A failing OpenRegister, or none at all, is no tenant.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterThereIsNoTenant(): void {
		$stored = ['org-a' => $this->organisationRow(uuid: 'org-a')];

		$this->assertNull($this->activeOrganisationSession(active: 'org-a', stored: $stored, memberships: ['org-a'], openRegister: false)->activeTenantId());
		$this->assertNull($this->activeOrganisationSession(active: 'org-a', stored: $stored, memberships: ['org-a'], activeThrows: true)->activeTenantId());
	}

	/**
	 * An anonymous caller has no tenant.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerHasNoTenant(): void {
		$service = $this->activeOrganisationSession(
			active: 'org-a',
			stored: ['org-a' => $this->organisationRow(uuid: 'org-a')],
			memberships: ['org-a'],
			uid: null,
		);

		$this->assertNull($service->activeTenantId());
	}

	/**
	 * A failed membership lookup fails closed to no tenant.
	 *
	 * @return void
	 */
	public function testAFailedMembershipLookupResolvesToNothing(): void {
		$service = $this->activeOrganisationSession(
			active: 'org-a',
			stored: ['org-a' => $this->organisationRow(uuid: 'org-a')],
			memberships: null,
		);

		$this->assertNull($service->activeTenantId());
	}
}
