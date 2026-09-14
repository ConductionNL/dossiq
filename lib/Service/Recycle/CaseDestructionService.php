<?php

/**
 * Destroying a case, as a second act with a name attached.
 *
 * Deleting a case puts it in OpenRegister's recycle state, where it can still
 * come back. Destroying it ends it. They are two acts and they need two
 * decisions, because the question an auditor asks in 2029 is not whether the
 * case was destroyed but who decided that it should be.
 *
 * This class is the decision layer and not the act. It reads the destroying
 * role off the case type, reads the two retention clocks apart, and reads the
 * recovery window. When all three allow it, OpenRegister carries out the
 * destruction and writes the record that outlives the object
 * (openregister#3724). dossiq destroys nothing itself and stores no second
 * trash, per decision D10.
 *
 * Which role may destroy is declared per case type rather than per instance,
 * because a melding and a bezwaar do not need the same seniority behind a
 * destruction. An admin passes, matching OpenRegister's own
 * `DestroyRightService`: a stricter rule here would only send an operator to
 * the endpoint underneath, which is worse than stating the rule once.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Recycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Recycle;

use DateTime;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Decides whether a deleted case may be destroyed, then has it destroyed.
 *
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */
class CaseDestructionService {

	/**
	 * The case-type property naming the group that may destroy.
	 *
	 * @var string
	 */
	public const CASE_TYPE_ROLE = 'destructionRole';

	/**
	 * The case-type property stating how long a delete can be undone.
	 *
	 * @var string
	 */
	public const CASE_TYPE_WINDOW = 'recoveryWindowDays';

	/**
	 * The audit action a destruction is recorded under.
	 *
	 * Equal to OpenRegister's `DestructionScope::DESTRUCTION_ACTION`, so the
	 * fallback record and the one OpenRegister writes are read back by the
	 * same query.
	 *
	 * @var string
	 */
	public const DESTROY_ACTION = 'object.destroyed';

	/**
	 * The refusals this service raises, and nothing else does.
	 *
	 * @var array<int, string>
	 */
	public const REFUSALS = [
		'case_not_deleted',
		'destroy_role_undeclared',
		'destroy_role_missing',
		'retention_clocks_disagree',
		'recovery_window_open',
	];

	/**
	 * Constructor.
	 *
	 * @param CaseRecycleService $recycle Reads the deleted case and its window.
	 * @param RetentionClocks $clocks Reads the two clocks apart.
	 * @param CaseTypeResolver $caseTypes Resolves a case type with its parents merged in.
	 * @param SettingsService $settingsService Bridge to OpenRegister plus app config.
	 * @param IUserSession $userSession The session, for the actor on the act.
	 * @param IGroupManager $groupManager Reads the caller's groups.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly CaseRecycleService $recycle,
		private readonly RetentionClocks $clocks,
		private readonly CaseTypeResolver $caseTypes,
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What a destruction would take with the case, before it runs.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The scope, the window and both clocks.
	 *
	 * @throws RuntimeException One of {@see self::REFUSALS}.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function preview(string $caseId): array {
		$entity = $this->requireDeleted(caseId: $caseId);
		$payload = $this->payload(entity: $entity);
		$this->assertMayDestroy(case: $payload);

		return [
			'caseId' => $caseId,
			'scope' => $this->scopePreview(entity: $entity),
			'deletionWindow' => $this->recycle->window(entity: $entity),
			'clocks' => $this->clocks->clocksFor(case: $payload),
			'destroyingRole' => $this->declaredRole(case: $payload),
		];
	}//end preview()

	/**
	 * Destroy a deleted case, and record who decided it.
	 *
	 * @param string $caseId The case UUID.
	 * @param bool $waiveWindow Whether the caller waived the open recovery window.
	 *
	 * @return array<string, mixed> What went, and the record of the act.
	 *
	 * @throws RuntimeException One of {@see self::REFUSALS}.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function destroy(string $caseId, bool $waiveWindow = false): array {
		$entity = $this->requireDeleted(caseId: $caseId);
		$payload = $this->payload(entity: $entity);
		$this->assertMayDestroy(case: $payload);

		$clocks = $this->clocks->clocksFor(case: $payload);
		if ($clocks['disagree'] === true) {
			throw new RuntimeException('retention_clocks_disagree');
		}

		$window = $this->recycle->window(entity: $entity);
		if ($window !== null && ($window['lapsed'] ?? false) === false && $waiveWindow === false) {
			throw new RuntimeException('recovery_window_open');
		}

		$scope = $this->destroyScope(entity: $entity);
		$record = $this->record(entity: $entity, scope: $scope, window: $window, waived: $waiveWindow);
		$this->purge(caseId: $caseId, entity: $entity);

		return [
			'success' => true,
			'caseId' => $caseId,
			'scope' => $scope,
			'destruction' => $record,
		];
	}//end destroy()

	/**
	 * Whether the caller holds the role this case type declares.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return bool True when the caller may destroy.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function mayDestroy(array $case): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		try {
			if ($this->groupManager->isAdmin($user->getUID()) === true) {
				return true;
			}
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the admin check failed on a destroy: ' . $e->getMessage());
		}

		$role = $this->declaredRole(case: $case);
		if ($role === '') {
			return false;
		}

		try {
			return $this->groupManager->isInGroup(userId: $user->getUID(), group: $role);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the role check failed on a destroy: ' . $e->getMessage());
			return false;
		}
	}//end mayDestroy()

	/**
	 * The group this case type says may destroy its cases.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return string The group id, or an empty string when none is declared.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function declaredRole(array $case): string {
		return trim((string)($this->caseType(case: $case)[self::CASE_TYPE_ROLE] ?? ''));
	}//end declaredRole()

	/**
	 * How many days this case type gives a delete to be undone.
	 *
	 * OpenRegister sets the window from the schema, which is one schema for
	 * every case type, so this is the number dossiq publishes on the case and
	 * refuses a premature destruction against. It never writes a second
	 * deletion marker.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return int|null The window in days, or null when the case type states none.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function declaredWindowDays(array $case): ?int {
		$days = ($this->caseType(case: $case)[self::CASE_TYPE_WINDOW] ?? null);
		if (is_numeric($days) === false || (int)$days < 1) {
			return null;
		}

		return (int)$days;
	}//end declaredWindowDays()

	/**
	 * Refuse unless the caller may destroy this case.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return void
	 *
	 * @throws RuntimeException `destroy_role_undeclared` or `destroy_role_missing`.
	 */
	private function assertMayDestroy(array $case): void {
		if ($this->mayDestroy(case: $case) === true) {
			return;
		}

		if ($this->declaredRole(case: $case) === '') {
			throw new RuntimeException('destroy_role_undeclared');
		}

		throw new RuntimeException('destroy_role_missing');
	}//end assertMayDestroy()

	/**
	 * The deleted case behind an id, or a refusal.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return object OpenRegister's object entity.
	 *
	 * @throws RuntimeException `case_not_deleted` when the case is not in the recycle state.
	 */
	private function requireDeleted(string $caseId): object {
		$entity = $this->recycle->findDeleted(caseId: $caseId);
		if ($entity === null) {
			throw new RuntimeException('case_not_deleted');
		}

		return $entity;
	}//end requireDeleted()

	/**
	 * The counted scope of a destruction, before it runs.
	 *
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return array<string, mixed> The preview, or an empty scope.
	 */
	private function scopePreview(object $entity): array {
		$service = $this->scopeService();
		if ($service === null || method_exists($service, 'preview') === false) {
			return $this->noScope();
		}

		try {
			return $service->preview(object: $entity, schema: null);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: could not preview the destruction scope: ' . $e->getMessage());
			return $this->noScope();
		}
	}//end scopePreview()

	/**
	 * Destroy everything the schema declares goes with the case.
	 *
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return array<string, mixed> What went.
	 */
	private function destroyScope(object $entity): array {
		$service = $this->scopeService();
		if ($service === null || method_exists($service, 'destroy') === false) {
			return $this->noScope();
		}

		return $service->destroy(object: $entity, schema: null);
	}//end destroyScope()

	/**
	 * The record of the act, which outlives the case.
	 *
	 * @param object $entity OpenRegister's object entity.
	 * @param array<string, mixed> $scope What the destruction took.
	 * @param array<string, mixed>|null $window The window at the moment of the act.
	 * @param bool $waived Whether an open window was waived.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function record(object $entity, array $scope, ?array $window, bool $waived): array {
		$user = $this->userSession->getUser();
		$actor = ($user?->getUID() ?? 'system');
		$context = [
			'destroyedBy' => $actor,
			'destroyedAt' => (new DateTime())->format(DateTime::ATOM),
			'objectUuid' => (string)$entity->getUuid(),
			'rule' => 'case-type-destroying-role',
			'deletionWindow' => $window,
			'windowWaived' => $waived,
			'scope' => $scope,
		];

		$recorder = $this->settingsService->getOpenRegisterClass(
			class: 'OCA\\OpenRegister\\Service\\Deletion\\DestructionRecorder'
		);
		if ($recorder !== null && method_exists($recorder, 'record') === true) {
			try {
				return $recorder->record(
					object: $entity,
					scope: $scope,
					rule: 'case-type-destroying-role',
					context: $context
				);
			} catch (Throwable $e) {
				$this->logger->warning('Dossiq: OpenRegister could not record the destruction: ' . $e->getMessage());
			}
		}

		$this->fallbackRecord(entity: $entity, context: $context, actor: $actor);

		return $context;
	}//end record()

	/**
	 * Write the destruction record ourselves when OpenRegister has no recorder.
	 *
	 * @param object $entity OpenRegister's object entity.
	 * @param array<string, mixed> $context The record.
	 * @param string $actor The user id that decided.
	 *
	 * @return void
	 */
	private function fallbackRecord(object $entity, array $context, string $actor): void {
		$mapper = $this->settingsService->getOpenRegisterClass(class: CaseRecycleService::AUDIT_MAPPER);
		if ($mapper === null || method_exists($mapper, 'createAuditTrailEntry') === false) {
			$this->logger->error(
				'Dossiq: a case was destroyed with no record, because no audit trail is available',
				['uuid' => (string)$entity->getUuid()]
			);
			return;
		}

		try {
			$mapper->createAuditTrailEntry(
				object: $entity,
				action: self::DESTROY_ACTION,
				context: $context,
				actorId: $actor,
				actorName: ($this->userSession->getUser()?->getDisplayName() ?? 'System')
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: could not record the destruction: ' . $e->getMessage());
		}
	}//end fallbackRecord()

	/**
	 * Permanently delete the case itself.
	 *
	 * The record above is already written, so a failure here leaves an
	 * over-recorded destruction rather than an unrecorded one.
	 *
	 * @param string $caseId The case UUID.
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function purge(string $caseId, object $entity): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('openregister_unavailable');
		}

		$objectService->deleteObject(
			uuid: $caseId,
			register: $this->settingsService->getConfigValue('register'),
			schema: $this->settingsService->getConfigValue('case_schema'),
			permanent: true
		);
	}//end purge()

	/**
	 * OpenRegister's destruction scope service, or null.
	 *
	 * @return object|null The service.
	 */
	private function scopeService(): ?object {
		return $this->settingsService->getOpenRegisterClass(
			class: 'OCA\\OpenRegister\\Service\\Deletion\\DestructionScopeService'
		);
	}//end scopeService()

	/**
	 * The answer for an instance that declares no destruction scope.
	 *
	 * An empty scope is a valid answer and not an oversight: such an instance
	 * destroys exactly what it destroyed before this change.
	 *
	 * @return array<string, mixed> The empty scope.
	 */
	private function noScope(): array {
		return [
			'scope' => [],
			'counts' => [],
			'unknown' => [],
			'destroyable' => true,
		];
	}//end noScope()

	/**
	 * The effective case type of a case, parents merged in.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return array<string, mixed> The case type, or an empty array.
	 */
	private function caseType(array $case): array {
		$value = ($case['caseType'] ?? '');
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['@self']['id'] ?? ''));
		}

		$caseTypeId = trim((string)$value);
		if ($caseTypeId === '') {
			return [];
		}

		try {
			return $this->caseTypes->effectiveCaseType(caseTypeId: $caseTypeId);
		} catch (Throwable $e) {
			$this->logger->info('Dossiq: could not read the case type of a deleted case: ' . $e->getMessage());
			return [];
		}
	}//end caseType()

	/**
	 * The payload of an entity.
	 *
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function payload(object $entity): array {
		if (method_exists($entity, 'getObject') === true) {
			$payload = $entity->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}//end payload()
}//end class
