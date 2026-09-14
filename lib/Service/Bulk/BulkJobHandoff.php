<?php

/**
 * Dossiq: hand a bulk act on cases to OpenRegister's job.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bulk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bulk;

use InvalidArgumentException;
use OCA\Dossiq\BulkAction\LifecycleCasesAction;
use OCA\Dossiq\BulkAction\ReassignCasesAction;
use OCA\Dossiq\BulkAction\SetCaseAttributeAction;
use OCA\Dossiq\BulkAction\TransitionCasesAction;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The whole of dossiq's side of a bulk act.
 *
 * It does four things and no more: it names dossiq's register and schema so
 * the job knows what it is walking, it refuses what dossiq's case policy
 * refuses, it hands the act over, and it answers the previewed job. There is
 * no loop here, no queue, no progress and no retry: those belong to
 * OpenRegister, and a copy of them here would be a second job model (D-1).
 *
 * The justification is required on this side rather than the other, because
 * OpenRegister's job does not know that reassigning four hundred statutory
 * cases is a thing a coordinator has to explain (D-3). The action declares it
 * too, so an act handed over by any other caller is refused just the same.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
class BulkJobHandoff {

	/**
	 * OpenRegister's job service, resolved by name so dossiq stays installable
	 * without it.
	 *
	 * @var string
	 */
	private const JOB_SERVICE = 'OCA\OpenRegister\Service\BulkJob\BulkJobService';

	/**
	 * The actions dossiq offers over cases, and whether each needs a reason.
	 *
	 * Read by the catalogue endpoint so a caller sees dossiq's four without
	 * having to know OpenRegister's whole registry.
	 *
	 * @var array<int, string>
	 */
	public const ACTIONS = [
		TransitionCasesAction::ID,
		LifecycleCasesAction::ID,
		ReassignCasesAction::ID,
		SetCaseAttributeAction::ID,
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService     $settingsService Register/schema configuration.
	 * @param CaseTypeVersionGuard $versionGuard   The selection-time version refusal.
	 * @param LoggerInterface     $logger          The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeVersionGuard $versionGuard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a previewed bulk job over cases.
	 *
	 * @param string               $actionId      The dossiq action to run.
	 * @param array<string, mixed> $parameters    The action's parameters.
	 * @param array<string, mixed> $selection     Either `{"ids": [...]}` or `{"query": {...}}`.
	 * @param string|null          $justification The reason the coordinator typed.
	 * @param string               $actorUid      Who is doing it.
	 *
	 * @return array<string, mixed> The previewed job, as the job serialises itself.
	 *
	 * @throws InvalidArgumentException When the action is not one of dossiq's.
	 * @throws MixedCaseTypeVersionsException When the selection spans case type versions.
	 * @throws RuntimeException When OpenRegister is not available.
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function create(
		string $actionId,
		array $parameters,
		array $selection,
		?string $justification,
		string $actorUid,
	): array {
		if (in_array($actionId, self::ACTIONS, true) === false) {
			throw new InvalidArgumentException('Unknown dossiq bulk action: ' . $actionId);
		}

		$this->guardSelection(actionId: $actionId, selection: $selection);

		$service = $this->jobService();
		[$registerId, $schemaId] = $this->scope();

		$job = $service->create(
			actionId: $actionId,
			parameters: $parameters,
			selection: $selection,
			justification: $justification,
			actorUid: $actorUid,
			registerId: $registerId,
			schemaId: $schemaId,
		);

		return $this->asArray(job: $job);
	}//end create()

	/**
	 * Run dossiq's own refusals over the selection, before anything is
	 * rehearsed.
	 *
	 * Only the attribute write is guarded: a transition, a lifecycle gesture
	 * and a redistribution all mean the same thing on every version of a case
	 * type, so refusing them would refuse a selection there is no reason to
	 * refuse.
	 *
	 * A selection given as a QUERY is not resolved here. OpenRegister's own
	 * `homogeneity` guard still refuses it at creation, before a single object
	 * is touched; what it cannot do is name the case type versions, because it
	 * reads schema versions. That is the cost of not resolving the query twice.
	 *
	 * @param string               $actionId  The action being handed over.
	 * @param array<string, mixed> $selection The caller's selection.
	 *
	 * @return void
	 *
	 * @throws MixedCaseTypeVersionsException When the selection spans case type versions.
	 */
	private function guardSelection(string $actionId, array $selection): void {
		if ($actionId !== SetCaseAttributeAction::ID) {
			return;
		}

		$ids = ($selection['ids'] ?? null);
		if (is_array($ids) === false || $ids === []) {
			return;
		}

		$this->versionGuard->assertOneVersion(caseIds: array_map('strval', $ids));
	}//end guardSelection()

	/**
	 * The register and schema the job walks, as numeric ids.
	 *
	 * The job's scope is required and numeric: a query naming neither returns
	 * an empty result rather than an error, which would present as a finished
	 * preview over nothing.
	 *
	 * @return array{0: int, 1: int} The register id and the schema id.
	 *
	 * @throws RuntimeException When either cannot be resolved.
	 */
	private function scope(): array {
		$registerId = $this->registerId(slug: (string)$this->settingsService->getConfigValue('register'));
		$schemaId = (int)$this->settingsService->getConfigValue('case_schema');

		if ($registerId === 0 || $schemaId === 0) {
			throw new RuntimeException('The dossiq register and case schema are not configured');
		}

		return [$registerId, $schemaId];
	}//end scope()

	/**
	 * Resolve a register slug to its numeric id.
	 *
	 * A slug that is already numeric is its own id, which is what the setting
	 * holds on an instance provisioned by id rather than by slug.
	 *
	 * @param string $slug The register slug or id.
	 *
	 * @return int The numeric id, or 0 when it cannot be resolved.
	 */
	private function registerId(string $slug): int {
		$slug = trim($slug);
		if ($slug === '') {
			return 0;
		}

		if (ctype_digit($slug) === true) {
			return (int)$slug;
		}

		try {
			$mapper = $this->settingsService->getOpenRegisterClass('OCA\OpenRegister\Db\RegisterMapper');
			if ($mapper === null) {
				return 0;
			}

			$register = $mapper->find($slug, _rbac: false, _multitenancy: false);

			return (int)$register->getId();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not resolve the register for a bulk job',
				['slug' => $slug, 'exception' => $e->getMessage()],
			);

			return 0;
		}//end try
	}//end registerId()

	/**
	 * OpenRegister's job service, or a refusal saying it is absent.
	 *
	 * @return object The job service.
	 *
	 * @throws RuntimeException When OpenRegister is not installed or enabled.
	 */
	private function jobService(): object {
		$service = $this->settingsService->getOpenRegisterClass(self::JOB_SERVICE);
		if ($service === null) {
			throw new RuntimeException('OpenRegister is not available, so a bulk act cannot be handed over');
		}

		return $service;
	}//end jobService()

	/**
	 * Read the job the service answered as an array.
	 *
	 * @param mixed $job Whatever the job service returned.
	 *
	 * @return array<string, mixed> The job.
	 */
	private function asArray(mixed $job): array {
		if (is_array($job) === true) {
			return $job;
		}

		if (is_object($job) === true && method_exists($job, 'jsonSerialize') === true) {
			$serialised = $job->jsonSerialize();

			return (is_array($serialised) === true) ? $serialised : [];
		}

		return [];
	}//end asArray()
}//end class
