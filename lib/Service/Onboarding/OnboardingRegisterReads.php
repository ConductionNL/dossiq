<?php

/**
 * Dossiq onboarding register reads.
 *
 * What a tenant's onboarding reads from the registers without writing
 * anything: whether the mandate register confirms the mandates step, the
 * subscription tier on the tenant's audit anchor, and how many rows a
 * schema holds for the tenant. Split out of
 * {@see \OCA\Dossiq\Service\TenantOnboardingService}, which keeps the
 * steps, their completion, the go-live decision and the billing line.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Onboarding
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Onboarding;

use DateTimeImmutable;
use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\MandaatCheckService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Throwable;

/**
 * The register reads behind a tenant's onboarding checklist and go-live.
 *
 * @spec openspec/specs/tenant-onboarding/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class OnboardingRegisterReads {

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterRowNormaliser $rowNormaliser Reads a stored row, entity or array, as an array.
	 * @param MandaatCheckService|null  $mandates      Answers whether the tenant has an active mandate.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly OpenRegisterRowNormaliser $rowNormaliser,
		private readonly ?MandaatCheckService $mandates = null,
	) {
	}//end __construct()

	/**
	 * The steps with "Mandates set" done once the tenant has an active mandate.
	 *
	 * Read when the checklist is shown, so the step follows the mandate
	 * register without a write on a read. A step already completed or skipped
	 * keeps its own state, and so does one the register cannot confirm.
	 *
	 * @param string                              $tenantId The tenant (organisation) UUID.
	 * @param array<string, array<string, mixed>> $steps    The steps, keyed by step.
	 *
	 * @return array<string, array<string, mixed>> The steps.
	 *
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md#requirement-the-overview-reads-decisions-from-the-mandate-register
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function withMandatesSet(string $tenantId, array $steps): array {
		$step = ($steps['mandate_import'] ?? null);
		if ($step === null || $this->mandates === null) {
			return $steps;
		}

		if (in_array((string) ($step['status'] ?? ''), ['completed', 'skipped'], true) === true) {
			return $steps;
		}

		if ($this->mandates->hasActiveMandateFor(organisation: $tenantId, date: new DateTimeImmutable()) === false) {
			return $steps;
		}

		$steps['mandate_import']['status'] = 'completed';
		return $steps;
	}//end withMandatesSet()

	/**
	 * The tenant's subscription tier, read, never written.
	 *
	 * A migrated tenant's tier is on its stored tenant object, the read-only
	 * audit anchor. A tenant onboarded after the migration has an anchor
	 * without one and bills at `basic` until its tier is set (decision 2a puts
	 * the tier on `tenantConfiguration`).
	 *
	 * @param object|null $objectService The OpenRegister object service, or null when it is not installed.
	 * @param string      $tenantId      Tenant UUID.
	 *
	 * @return string The tier.
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function tierOf(?object $objectService, string $tenantId): string {
		if ($objectService === null) {
			return 'basic';
		}

		try {
			$anchor = $objectService->find(
				$tenantId,
				new ObjectFindOptions(register: AppIdentity::REGISTER_SLUG, schema: 'tenant'),
				_rbac: false,
				_multitenancy: false
			);
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
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function countSchemaRows($objectService, string $schema, array $filters): int {
		try {
			// ObjectService::findAll() takes a single $config array — see the
			// note in getProgress(); register/schema live inside `filters`.
			$rows = $objectService->findAll(
				[
					'filters' => array_merge(
						[
							'register' => AppIdentity::REGISTER_SLUG,
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
}//end class
