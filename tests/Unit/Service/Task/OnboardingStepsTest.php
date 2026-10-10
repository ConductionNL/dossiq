<?php

/**
 * A tenant's onboarding steps live in OpenRegister's task engine.
 *
 * Built on the real OnboardingSteps and the real TenantOnboardingService over
 * an in-memory engine with the real seams' parameter order.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
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
 * @spec openspec/specs/tenant-onboarding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\OnboardingSteps;
use OCA\Dossiq\Service\TenantBillingService;
use OCA\Dossiq\Service\TenantOnboardingService;
use OCA\Dossiq\Tests\Support\MakesTenantAnchors;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Task\OnboardingSteps
 * @covers \OCA\Dossiq\Service\TenantOnboardingService
 */
class OnboardingStepsTest extends TestCase {
	use MakesTenantAnchors;

	/**
	 * The tenant.
	 */
	private const ORG = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

	/**
	 * Fresh engine and store, with the tenant's Organisation.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->startAnchorStore();
		$this->givenOrganisation(uuid: self::ORG, slug: 'zuiddrecht', name: 'Gemeente Zuiddrecht');
	}//end setUp()

	/**
	 * The onboarding service over the engine, for a signed-in admin.
	 *
	 * @return TenantOnboardingService The service.
	 */
	private function onboarding(): TenantOnboardingService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new TenantOnboardingService(
			appManager: $this->anchorApps(),
			container: $this->anchorContainer(),
			logger: $this->anchorLogger(),
			billingService: $this->createMock(TenantBillingService::class),
			tenantService: $this->realTenantService(),
			steps: $this->realOnboardingSteps(),
			userSession: $session,
		);
	}//end onboarding()

	/**
	 * The seven steps open as available engine tasks on the tenant, named by step.
	 *
	 * @return void
	 */
	public function testInitialisingOpensSevenEngineTasksOnTheTenant(): void {
		$opened = $this->onboarding()->createOnboarding(self::ORG);

		$this->assertCount(7, $opened);
		$this->assertCount(7, $this->engine->imports);
		$first = $this->engine->imports[0];
		$this->assertSame('beheerder', $first['actor']);
		$this->assertSame(
			['key' => 'contract', 'title' => 'contract', 'kind' => OnboardingSteps::KIND, 'appId' => 'dossiq', 'organisation' => self::ORG, 'objectUuid' => self::ORG, 'state' => 'available'],
			$first['data']
		);
	}//end testInitialisingOpensSevenEngineTasksOnTheTenant()

	/**
	 * Progress reads the engine: completed steps count, the rest are pending.
	 *
	 * @return void
	 */
	public function testProgressCountsTheCompletedEngineTasks(): void {
		$service = $this->onboarding();
		$service->createOnboarding(self::ORG);
		$service->markStepComplete(self::ORG, 'contract', 'beheerder');
		$service->markStepComplete(self::ORG, 'branding', 'beheerder');

		$progress = $service->getProgress(self::ORG);

		$this->assertSame(2, $progress['completed']);
		$this->assertSame(7, $progress['total']);
		$this->assertSame(0.29, $progress['fraction']);
		$this->assertSame(TenantOnboardingService::STEPS, array_column($progress['steps'], 'step'));
		$byStep = array_column($progress['steps'], 'status', 'step');
		$this->assertSame('completed', $byStep['contract']);
		$this->assertSame('pending', $byStep['sso_setup']);
	}//end testProgressCountsTheCompletedEngineTasks()

	/**
	 * Completing a step goes through the engine verb and records who.
	 *
	 * @return void
	 */
	public function testCompletingAStepUsesTheEngineVerb(): void {
		$service = $this->onboarding();
		$service->createOnboarding(self::ORG);

		$step = $service->markStepComplete(self::ORG, 'branding', 'beheerder');

		$this->assertSame('completed', $step['status']);
		$this->assertSame('beheerder', $step['completedBy']);
	}//end testCompletingAStepUsesTheEngineVerb()

	/**
	 * A step that was never opened, or that the engine refuses, answers null.
	 *
	 * @return void
	 */
	public function testAMissingOrRefusedStepAnswersNull(): void {
		$service = $this->onboarding();
		$this->assertNull($service->markStepComplete(self::ORG, 'branding', 'beheerder'), 'no step opened yet');

		$service->createOnboarding(self::ORG);
		$service->markStepComplete(self::ORG, 'branding', 'beheerder');
		$this->assertNull($service->markStepComplete(self::ORG, 'branding', 'beheerder'), 'the engine refuses a terminal task');
	}//end testAMissingOrRefusedStepAnswersNull()

	/**
	 * A skipped step is terminated with the outcome skipped, and reads back as skipped (decision 144).
	 *
	 * @return void
	 */
	public function testASkippedStepIsTerminatedWithTheOutcomeSkipped(): void {
		$steps = $this->realOnboardingSteps();

		$uuid = $steps->open(tenantId: self::ORG, step: 'sso_setup', title: 'sso_setup', status: 'skipped', actor: 'beheerder');

		$this->assertSame('terminated', $this->engine->tasks[$uuid]['state']);
		$this->assertSame('skipped', $this->engine->tasks[$uuid]['outcome']);
		$this->assertSame('skipped', $steps->forTenant(tenantId: self::ORG, actor: 'beheerder')['sso_setup']['status']);
	}//end testASkippedStepIsTerminatedWithTheOutcomeSkipped()

	/**
	 * A tenant whose steps are still legacy rows has them moved onto the engine, once (decision 144).
	 *
	 * @return void
	 */
	public function testLegacyRowsMoveOntoTheEngineOnceWithTheirStatusMapped(): void {
		$rows = ['contract' => 'completed', 'mandate_import' => 'in_progress', 'sso_setup' => 'skipped', 'branding' => 'pending'];
		foreach ($rows as $step => $status) {
			$row = ['tenantRef' => self::ORG, 'step' => $step, 'status' => $status];
			if ($status === 'completed') {
				$row['completedBy'] = 'marieke';
				$row['completedAt'] = '2026-09-30T10:00:00+00:00';
			}

			$this->anchorStore->seed(schema: 'tenantOnboardingTask', uuid: 'row-'.$step, row: $row);
		}

		$this->anchorStore->seed(schema: 'tenantOnboardingTask', uuid: 'row-other', row: ['tenantRef' => 'another-tenant', 'step' => 'contract', 'status' => 'pending']);

		$service = $this->onboarding();
		$progress = $service->getProgress(self::ORG);

		$byStep = array_column($progress['steps'], 'status', 'step');
		$this->assertSame(['contract' => 'completed', 'mandate_import' => 'in_progress', 'sso_setup' => 'skipped', 'branding' => 'pending'], $byStep);
		$states = array_column($this->engine->tasks, 'state', 'taskKey');
		$this->assertSame(['contract' => 'completed', 'mandate_import' => 'active', 'sso_setup' => 'terminated', 'branding' => 'available'], $states);
		$contract = array_values(array_filter($this->engine->tasks, static fn (array $t): bool => $t['taskKey'] === 'contract'))[0];
		$this->assertSame(['dossiq' => ['onboardingRow' => 'row-contract', 'completedBy' => 'marieke', 'completedAt' => '2026-09-30T10:00:00+00:00']], $contract['metadata']);

		$service->getProgress(self::ORG);
		$service->createOnboarding(self::ORG);
		$this->assertCount(7, $this->engine->tasks, 'carried once; initialise then adds only the three missing steps');
		$this->assertSame('pending', $this->anchorStore->row(schema: 'tenantOnboardingTask', uuid: 'row-branding')['status'], 'the legacy rows stay as written');
	}//end testLegacyRowsMoveOntoTheEngineOnceWithTheirStatusMapped()

	/**
	 * Each onboarding status maps onto the engine state and back.
	 *
	 * @return void
	 */
	public function testTheStatusMapRoundTrips(): void {
		foreach (OnboardingSteps::STATE_FOR_STATUS as $status => $state) {
			$outcome = '';
			if ($status === 'skipped') {
				$outcome = OnboardingSteps::OUTCOME_SKIPPED;
			}

			$this->assertSame($status, OnboardingSteps::statusFor(state: $state, outcome: $outcome));
		}

		$this->assertSame('terminated', OnboardingSteps::statusFor(state: 'terminated', outcome: 'cancelled'), 'a terminated step that was not skipped keeps the engine word');
	}//end testTheStatusMapRoundTrips()

	/**
	 * Without OpenRegister, or with the engine refusing, nothing is opened and the reason is kept.
	 *
	 * @return void
	 */
	public function testWithoutTheEngineNothingIsOpened(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(false);
		$absent = new OnboardingSteps(settings: $settings, container: $this->createMock(ContainerInterface::class), logger: $this->createMock(LoggerInterface::class));

		$this->assertSame('', $absent->open(tenantId: self::ORG, step: 'contract', title: 'contract', status: 'pending', actor: 'a'));
		$this->assertSame([], $absent->forTenant(tenantId: self::ORG, actor: 'a'));
		$this->assertFalse($absent->complete(taskId: 'task-1', actor: 'a'));
		$this->assertSame('OpenRegister is not available.', $absent->lastError());

		$this->engine->refuseImports = true;
		$steps = $this->realOnboardingSteps();
		$this->assertSame('', $steps->open(tenantId: self::ORG, step: 'contract', title: 'contract', status: 'pending', actor: 'a'));
		$this->assertSame('the engine refused the task', $steps->lastError());
		$this->assertSame('', $steps->open(tenantId: self::ORG, step: 'contract', title: 'contract', status: 'unknown', actor: 'a'), 'an unknown status is not written');
	}//end testWithoutTheEngineNothingIsOpened()
}//end class
