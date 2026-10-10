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

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCP\App\IAppManager;
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
	 * @param OpenRegisterRowNormaliser $rowNormaliser Reads a findAll() row, entity or array, as an array.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly TenantBillingService $billingService,
		private readonly TenantService $tenantService,
		private readonly OpenRegisterRowNormaliser $rowNormaliser = new OpenRegisterRowNormaliser(),
	) {
	}//end __construct()

	/**
	 * Fork the default 7-step template into the tenant's onboarding list.
	 *
	 * The tenant's audit anchor is made first (decision Q6), and without it no
	 * step is written: a tenant whose audit entries have nowhere to land must
	 * not look onboarded.
	 *
	 * @param string $tenantId Tenant UUID, which is the Organisation's uuid.
	 *
	 * @return array<int, array<string, mixed>> Created task rows.
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
	 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
	 */
	public function createOnboarding(string $tenantId): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			$this->logger->info('Dossiq: createOnboarding skipped — OR unavailable');
			return [];
		}

		// When remove-casetask task 7.1 moves the onboarding steps onto the
		// engine Task, this call moves with them: the anchor comes first.
		if ($this->tenantService->ensureAuditAnchor(organisationUuid: $tenantId) === false) {
			$this->logger->error(
				'Dossiq: onboarding not started, the tenant has no audit anchor',
				['tenantId' => $tenantId]
			);
			return [];
		}

		$created = [];
		foreach (self::STEPS as $step) {
			try {
				$row = $objectService->saveObject(
					object: ['tenantRef' => $tenantId, 'step' => $step, 'status' => 'pending'],
					register: Application::REGISTER_SLUG,
					schema: 'tenantOnboardingTask',
					uuid: null
				);
				if (is_array($row) === true) {
					$created[] = $row;
				}
			} catch (Throwable $e) {
				$this->logger->error(
					'Dossiq: createOnboarding step write failed',
					['tenantId' => $tenantId, 'step' => $step, 'exception' => $e->getMessage()]
				);
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
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['steps' => [], 'completed' => 0, 'total' => count(self::STEPS), 'fraction' => 0.0];
		}

		try {
			// ObjectService::findAll() takes a single $config array — the previous
			// named-argument form threw "Unknown named parameter $register" and
			// was swallowed by the catch below. Register/schema are read from
			// inside `filters`.
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
			$rows = [];
		}

		if (is_array($rows) === false) {
			$rows = [];
		}

		// `findAll()` returns ObjectEntity objects. Indexing one as an array is
		// an Error, and this loop sits outside the catch above, so the progress
		// endpoint answered 500 for every tenant that had any step at all.
		// Each row is read as an array first; a row that carries nothing
		// readable is dropped rather than counted as a step with no status.
		$steps = [];
		foreach ($rows as $row) {
			$step = $this->rowNormaliser->normalise(row: $row)['data'];
			if ($step === []) {
				continue;
			}

			$steps[] = $step;
		}

		$completed = 0;
		foreach ($steps as $step) {
			if ((string)($step['status'] ?? '') === 'completed') {
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
	 * Mark a step as completed.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $step Step name.
	 * @param string $completedBy NC user ID who completed it.
	 *
	 * @return array<string,mixed>|null Updated task row.
	 *
	 * @throws InvalidArgumentException On invalid step.
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
	 */
	public function markStepComplete(string $tenantId, string $step, string $completedBy): ?array {
		if (in_array($step, self::STEPS, true) === false) {
			throw new InvalidArgumentException('Unknown onboarding step: ' . $step);
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			// ObjectService::findAll() takes a single $config array — see the
			// note in getProgress(); register/schema live inside `filters`.
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => Application::REGISTER_SLUG,
						'schema' => 'tenantOnboardingTask',
						'tenantRef' => $tenantId,
						'step' => $step,
					],
					'limit' => 1,
					'offset' => 0,
				]
			);
			if (is_array($rows) === false || count($rows) === 0) {
				return null;
			}

			// `findAll()` returns ObjectEntity objects, so this used to assign
			// into an object, throw, and be caught below as "step not found" —
			// no step could ever be completed. The row is read as an array
			// first, and its uuid comes off the row itself, so the step is
			// written back in place instead of creating a second task.
			$row = $this->rowNormaliser->normalise(row: $rows[0]);
			$task = $row['data'];
			if ($task === []) {
				return null;
			}

			$task['status'] = 'completed';
			$task['completedBy'] = $completedBy;
			$task['completedAt'] = (new DateTimeImmutable('now'))->format(DATE_ATOM);

			$uuid = $row['uuid'];
			$uuidArg = null;
			if ($uuid !== '') {
				$uuidArg = $uuid;
			}

			$row = $objectService->saveObject(
				object: $task,
				register: Application::REGISTER_SLUG,
				schema: 'tenantOnboardingTask',
				uuid: $uuidArg
			);
			if (is_array($row) === true) {
				return $row;
			}

			return $task;
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: markStepComplete failed', ['exception' => $e->getMessage()]);
			return null;
		}//end try
	}//end markStepComplete()

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
