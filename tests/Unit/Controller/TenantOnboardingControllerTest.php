<?php

/**
 * Initialising onboarding gives a new Organisation its tenant audit anchor, once.
 *
 * Built through the route, on the real TenantOnboardingService and the real
 * TenantService, with OpenRegister doubled at its three seams.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\TenantOnboardingController;
use OCA\Dossiq\Service\TenantBillingService;
use OCA\Dossiq\Service\TenantOnboardingService;
use OCA\Dossiq\Tests\Support\MakesTenantAnchors;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\TenantOnboardingController
 * @covers \OCA\Dossiq\Service\TenantOnboardingService
 * @covers \OCA\Dossiq\Service\TenantService
 * @uses \OCA\Dossiq\Service\TenantOrganisationResolver
 */
class TenantOnboardingControllerTest extends TestCase {
	use MakesTenantAnchors;

	/**
	 * An Organisation created in OpenRegister after the migration.
	 */
	private const ORG = '5e7c1d2a-8b3f-4c6d-9e0a-1b2c3d4e5f60';

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
	 * The controller over the real onboarding and tenant services.
	 *
	 * @param bool                      $openRegister Whether OpenRegister is installed.
	 * @param TenantBillingService|null $billing      The billing emitter, a double when null.
	 *
	 * @return TenantOnboardingController The controller.
	 */
	private function controller(bool $openRegister = true, ?TenantBillingService $billing = null): TenantOnboardingController {
		$onboarding = new TenantOnboardingService(
			appManager: $this->anchorApps(openRegister: $openRegister),
			container: $this->anchorContainer(),
			logger: $this->anchorLogger(),
			billingService: ($billing ?? $this->createMock(TenantBillingService::class)),
			tenantService: $this->realTenantService(openRegister: $openRegister),
		);

		return new TenantOnboardingController(
			request: $this->createMock(IRequest::class),
			onboarding: $onboarding,
			userSession: $this->createMock(IUserSession::class),
		);
	}//end controller()

	/**
	 * A new Organisation gets exactly one anchor with its uuid, slug and name, and then its steps (REQ-TOO-006).
	 *
	 * @return void
	 */
	public function testInitialisingOnboardingForANewOrganisationCreatesItsAnchor(): void {
		$this->givenOrganisation(uuid: self::ORG, slug: 'zuiddrecht', name: 'Gemeente Zuiddrecht');

		$response = $this->controller()->initialise(self::ORG);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$anchors = $this->storedAnchors();
		$this->assertCount(1, $anchors);
		$this->assertSame(self::ORG, $anchors[0]['id']);
		$this->assertSame('zuiddrecht', $anchors[0]['slug']);
		$this->assertSame('Gemeente Zuiddrecht', $anchors[0]['displayName']);
		$this->assertSame('2026-10-01T09:00:00+00:00', $anchors[0]['createdAt']);

		$steps = $this->anchorStore->all(schema: 'tenantOnboardingTask');
		$this->assertCount(count(TenantOnboardingService::STEPS), $steps, 'The steps are written after the anchor.');
	}//end testInitialisingOnboardingForANewOrganisationCreatesItsAnchor()

	/**
	 * A second initialise leaves the one anchor exactly as it was (REQ-TOO-006).
	 *
	 * @return void
	 */
	public function testASecondInitialiseCreatesNoSecondAnchor(): void {
		$this->givenOrganisation(uuid: self::ORG, slug: 'zuiddrecht', name: 'Gemeente Zuiddrecht');
		$controller = $this->controller();
		$controller->initialise(self::ORG);
		$writesAfterFirst = $this->anchorStore->writes;

		$this->organisations[self::ORG]->setName('Renamed afterwards');
		$controller->initialise(self::ORG);

		$anchors = $this->storedAnchors();
		$this->assertCount(1, $anchors);
		$this->assertSame('Gemeente Zuiddrecht', $anchors[0]['displayName'], 'An existing anchor is never updated.');
		$this->assertSame(
			$writesAfterFirst + count(TenantOnboardingService::STEPS),
			$this->anchorStore->writes,
			'The second initialise wrote its steps and no anchor.'
		);
	}//end testASecondInitialiseCreatesNoSecondAnchor()

	/**
	 * With no Organisation behind the id, no anchor and no step is written, and the route says so.
	 *
	 * @return void
	 */
	public function testAnIdWithNoOrganisationWritesNothingAndAnswersConflict(): void {
		$response = $this->controller()->initialise(self::ORG);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame([], $this->storedAnchors());
		$this->assertSame([], $this->anchorStore->all(schema: 'tenantOnboardingTask'));
		$this->assertNotSame([], $this->anchorErrors, 'The refusal is logged.');
	}//end testAnIdWithNoOrganisationWritesNothingAndAnswersConflict()

	/**
	 * Go-live answers OK and writes no tenant status (REQ-TOO-004, task 6.8).
	 *
	 * The tenant is ready (a case type, a mandate and a tenant admin exist),
	 * so activation succeeds. The only thing it may write is the first billing
	 * line; the stored tenant object keeps the status it had.
	 *
	 * @return void
	 */
	public function testActivatingAfterGoLiveAnswersOkWithoutAStatusWrite(): void {
		$this->anchorStore->seed(schema: 'tenant', uuid: self::ORG, row: ['slug' => 'zuiddrecht', 'displayName' => 'Gemeente Zuiddrecht', 'status' => 'onboarding', 'tier' => 'premium']);
		$this->anchorStore->seed(schema: 'caseType', uuid: 'ct-1', row: ['tenantRef' => self::ORG]);
		$this->anchorStore->seed(schema: 'tenantMandate', uuid: 'm-1', row: ['tenantRef' => self::ORG]);
		$this->anchorStore->seed(schema: 'tenantUser', uuid: 'u-1', row: ['tenantRef' => self::ORG, 'role' => 'tenant_admin']);

		$billing = $this->createMock(TenantBillingService::class);
		$billing->expects($this->once())->method('tierMonthlyPrice')->with('premium')->willReturn(99.0);
		$billing->expects($this->once())->method('emitEvent');

		$response = $this->controller(billing: $billing)->activate(self::ORG);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(0, $this->anchorStore->writes, 'Go-live writes nothing to the register.');
		$this->assertSame('onboarding', $this->anchorStore->row(schema: 'tenant', uuid: self::ORG)['status']);
	}//end testActivatingAfterGoLiveAnswersOkWithoutAStatusWrite()

	/**
	 * Without OpenRegister the route answers conflict rather than an empty success.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheRouteAnswersConflict(): void {
		$response = $this->controller(openRegister: false)->initialise(self::ORG);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(false, $response->getData()['success']);
	}//end testWithoutOpenRegisterTheRouteAnswersConflict()
}//end class
