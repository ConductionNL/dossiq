<?php

/**
 * Dossiq Tenant Onboarding Service
 *
 * Owns the per-tenant onboarding checklist — fork the 7-step template,
 * track progress, mark steps complete, validate go-live readiness.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Service\Task\OnboardingSteps;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Onboarding workflow service.
 *
 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
 */
class TenantOnboardingService {
	/**
	 * The seven canonical onboarding steps.
	 */
	public const STEPS = [
		'contract',
		'mandate_import',
		'sso_setup',
		'branding',
		'zaaktype_selection',
		'first_user',
		'go_live',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager App manager.
	 * @param ContainerInterface $container Service container.
	 * @param LoggerInterface $logger Logger.
	 * @param TenantBillingService $billingService Billing-event emitter.
	 * @param TenantService $tenantService Creates the tenant's audit anchor before onboarding starts.
	 * @param OnboardingSteps $steps The onboarding steps, as engine tasks.
	 * @param IUserSession $userSession The acting user.
	 * @param OpenRegisterRowNormaliser $rowNormaliser Reads a findAll() row, entity or array, as an array.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly TenantBillingService $billingService,
		private readonly TenantService $tenantService,
		private readonly OnboardingSteps $steps,
		private readonly IUserSession $userSession,
		private readonly OpenRegisterRowNormaliser $rowNormaliser = new OpenRegisterRowNormaliser(),
	) {
	}//end __construct()

	/**
	 * Fork the default 7-step template into the tenant's onboarding list.
	 *
	 * The tenant's audit anchor is made first (decision Q6), and without it no
	 * step is written: a tenant whose audit entries have nowhere to land must
	 * not look onboarded. Each step is then one task in OpenRegister's task
	 * engine (remove-casetask 7.1). A step that is already there is not opened
	 * twice, so initialising again only fills in what is missing.
	 *
	 * @param string $tenantId Tenant UUID, which is the Organisation's uuid.
	 *
	 * @return array<int, array<string, mixed>> The steps opened by this call.
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function createOnboarding(string $tenantId): array {
		if ($this->getObjectService() === null) {
			$this->logger->info('Dossiq: createOnboarding skipped — OR unavailable');
			return [];
		}

		if ($this->tenantService->ensureAuditAnchor(organisationUuid: $tenantId) === false) {
			$this->logger->error(
				'Dossiq: onboarding not started, the tenant has no audit anchor',
				['tenantId' => $tenantId]
			);
			return [];
		}

		$actor = $this->actor();
		$existing = $this->stepsCarryingLegacyRows(tenantId: $tenantId, actor: $actor);
		$created = [];
		foreach (self::STEPS as $step) {
			if (array_key_exists($step, $existing) === true) {
				continue;
			}

			$uuid = $this->steps->open(tenantId: $tenantId, step: $step, title: $step, status: 'pending', actor: $actor);
			if ($uuid !== '') {
				$created[] = ['id' => $uuid, 'tenantRef' => $tenantId, 'step' => $step, 'status' => 'pending'];
			}
		}

		return $created;
	}//end createOnboarding()

	/**
	 * Get the per-step progress and overall completion fraction.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array{steps: array<int, array<string, mixed>>, completed: int, total: int, fraction: float}
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
	 */
	public function getProgress(string $tenantId): array {
		$steps = array_values($this->stepsCarryingLegacyRows(tenantId: $tenantId, actor: $this->actor()));

		$completed = 0;
		foreach ($steps as $step) {
			if ($step['status'] === 'completed') {
				$completed++;
			}
		}

		// STEPS is non-empty, so $total is always >= 1 and the division is safe.
		$total = max(count(self::STEPS), count($steps));
		$fraction = ($completed / $total);

		return [
			'steps' => $steps,
			'completed' => $completed,
			'total' => $total,
			'fraction' => round($fraction, 2),
		];
	}//end getProgress()

	/**
	 * Mark a step as completed, through the engine's completion verb.
	 *
	 * @param string $tenantId    Tenant UUID.
	 * @param string $step        Step name.
	 * @param string $completedBy NC user ID who completed it.
	 *
	 * @return array<string,mixed>|null The step after completion, or null when there is no such step or the engine refused.
	 *
	 * @throws InvalidArgumentException On invalid step.
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
	 */
	public function markStepComplete(string $tenantId, string $step, string $completedBy): ?array {
		if (in_array($step, self::STEPS, true) === false) {
			throw new InvalidArgumentException('Unknown onboarding step: ' . $step);
		}

		$current = ($this->stepsCarryingLegacyRows(tenantId: $tenantId, actor: $completedBy)[$step] ?? null);
		if ($current === null) {
			return null;
		}

		if ($this->steps->complete(taskId: $current['id'], actor: $completedBy) === false) {
			return null;
		}

		return ($this->steps->forTenant(tenantId: $tenantId, actor: $completedBy)[$step] ?? null);
	}//end markStepComplete()

	/**
	 * The tenant's steps on the engine, moving its legacy rows there first.
	 *
	 * Before remove-casetask 7.1 a step was a `tenantOnboardingTask` object.
	 * A tenant whose steps are still only those rows gets them moved onto the
	 * engine the first time an admin reads or changes its onboarding, with the
	 * status mapped by decision 144 (`skipped` becomes terminated with the
	 * outcome skipped) and the row's uuid, completedBy, completedAt and
	 * blockedReason kept in the task's metadata, because the engine sets
	 * those through its verbs and does not accept them as assertions. The
	 * rows stay as they were written. A tenant with engine steps is never
	 * touched again, so this runs once per tenant.
	 *
	 * @param string $tenantId The Organisation's uuid.
	 * @param string $actor    The admin acting.
	 *
	 * @return array<string, array<string, string>> The steps, keyed by step.
	 */
	private function stepsCarryingLegacyRows(string $tenantId, string $actor): array {
		$steps = $this->steps->forTenant(tenantId: $tenantId, actor: $actor);
		if ($steps !== []) {
			return $steps;
		}

		$carried = 0;
		foreach ($this->legacyRows(tenantId: $tenantId) as $row) {
			$step = (string) ($row['data']['step'] ?? '');
			$status = (string) ($row['data']['status'] ?? 'pending');
			if (in_array($step, self::STEPS, true) === false) {
				continue;
			}

			$metadata = ['onboardingRow' => $row['uuid']];
			foreach (['completedBy', 'completedAt', 'blockedReason'] as $field) {
				if ((string) ($row['data'][$field] ?? '') !== '') {
					$metadata[$field] = (string) $row['data'][$field];
				}
			}

			if ($this->steps->open(tenantId: $tenantId, step: $step, title: $step, status: $status, actor: $actor, metadata: $metadata) !== '') {
				$carried++;
			}
		}

		if ($carried === 0) {
			return [];
		}

		return $this->steps->forTenant(tenantId: $tenantId, actor: $actor);
	}//end stepsCarryingLegacyRows()

	/**
	 * The tenant's legacy `tenantOnboardingTask` rows, each as uuid and data.
	 *
	 * @param string $tenantId The Organisation's uuid.
	 *
	 * @return array<int, array{uuid: string, data: array<string, mixed>}> The rows.
	 */
	private function legacyRows(string $tenantId): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => Application::REGISTER_SLUG,
						'schema' => 'tenantOnboardingTask',
						'tenantRef' => $tenantId,
					],
					'limit' => 100,
					'offset' => 0,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: could not read the legacy onboarding rows', ['tenantId' => $tenantId, 'exception' => $e->getMessage()]);
			return [];
		}

		$out = [];
		foreach ((array) $rows as $row) {
			$normalised = $this->rowNormaliser->normalise(row: $row);
			if ($normalised['data'] !== [] && (string) ($normalised['data']['tenantRef'] ?? '') === $tenantId) {
				$out[] = $normalised;
			}
		}

		return $out;
	}//end legacyRows()

	/**
	 * The signed-in user, or `admin` for a call without a session.
	 *
	 * @return string The acting uid.
	 */
	private function actor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'admin';
		}

		return $user->getUID();
	}//end actor()

	/**
	 * Validate that the tenant is ready to go live.
	 *
	 * Acceptance criteria: ≥1 zaaktype, ≥1 mandate, ≥1 tenant_admin user.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array{ready: bool, missing: array<int, string>}
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
	 */
	public function validateGoLive(string $tenantId): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['ready' => false, 'missing' => ['openregister_unavailable']];
		}

		$missing = [];
		if ($this->countSchemaRows(objectService: $objectService, schema: 'caseType', filters: ['tenantRef' => $tenantId]) === 0) {
			$missing[] = 'caseType';
		}

		if ($this->countSchemaRows(objectService: $objectService, schema: 'tenantMandate', filters: ['tenantRef' => $tenantId]) === 0) {
			$missing[] = 'mandate';
		}

		if ($this->countSchemaRows(
			objectService: $objectService,
			schema: 'tenantUser',
			filters: ['tenantRef' => $tenantId, 'role' => 'tenant_admin']
		) === 0
		) {
			$missing[] = 'tenant_admin';
		}

		return ['ready' => count($missing) === 0, 'missing' => $missing];
	}//end validateGoLive()

	/**
	 * Complete go-live when it validates.
	 *
	 * Writes no tenant status (task 6.8). The Organisation is `active` from the
	 * start (decision 2f) and OpenRegister's TenantLifecycleService governs its
	 * status; the onboarding steps are dossiq's own state. What go-live still
	 * does here is emit the first billing line.
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array{activated: bool, missing?: array<int, string>}
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function activate(string $tenantId): array {
		$check = $this->validateGoLive(tenantId: $tenantId);
		if ($check['ready'] === false) {
			return ['activated' => false, 'missing' => $check['missing']];
		}

		// Go-live emits the first billing line (the tier subscription). Without
		// a real usage event no invoice ever has a non-zero amount — this is
		// the wiring the metered-billing pipeline lacked (procest#223 finding 2).
		$tier = $this->tierOf(tenantId: $tenantId);
		$unitPrice = $this->billingService->tierMonthlyPrice(tier: $tier);
		$this->billingService->emitEvent(
			tenantId: $tenantId,
			eventType: 'user_activated',
			quantity: 1.0,
			unitPrice: $unitPrice,
			currency: 'EUR',
		);

		return ['activated' => true];
	}//end activate()

	/**
	 * The tenant's subscription tier, read, never written.
	 *
	 * A migrated tenant's tier is on its stored tenant object, the read-only
	 * audit anchor. A tenant onboarded after the migration has an anchor
	 * without one and bills at `basic` until its tier is set (decision 2a puts
	 * the tier on `tenantConfiguration`).
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string The tier.
	 */
	private function tierOf(string $tenantId): string {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return 'basic';
		}

		try {
			$anchor = $objectService->find($tenantId, register: Application::REGISTER_SLUG, schema: 'tenant', _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return 'basic';
		}

		$tier = (string)($this->rowNormaliser->normalise(row: $anchor)['data']['tier'] ?? '');
		if ($tier === '') {
			return 'basic';
		}

		return $tier;
	}//end tierOf()

	/**
	 * Count rows in a schema with a filter.
	 *
	 * @param mixed $objectService Object service.
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $filters Filters.
	 *
	 * @return int
	 */
	private function countSchemaRows($objectService, string $schema, array $filters): int {
		try {
			// ObjectService::findAll() takes a single $config array — see the
			// note in getProgress(); register/schema live inside `filters`.
			$rows = $objectService->findAll(
				[
					'filters' => array_merge(
						[
							'register' => Application::REGISTER_SLUG,
							'schema' => $schema,
						],
						$filters
					),
					'limit' => 1,
					'offset' => 0,
				]
			);
			if (is_array($rows) === true) {
				return count($rows);
			}

			return 0;
		} catch (Throwable $e) {
			return 0;
		}//end try
	}//end countSchemaRows()

	/**
	 * Resolve the OpenRegister object service, or null when unavailable.
	 *
	 * @return mixed|null
	 */
	private function getObjectService() {
		// IAppManager::getInstalledApps() declares its array return in PHPDoc
		// only, so normalise defensively before the membership test.
		$installed = (array)$this->appManager->getInstalledApps();
		if (in_array('openregister', $installed, true) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}
	}//end getObjectService()
}//end class
