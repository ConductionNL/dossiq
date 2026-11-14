<?php

/**
 * Dossiq MandaatEscalatieService.
 *
 * Escalation engine for mandate-denied decisions: creates an escalation
 * row pointing at the next-higher mandate holder, supports approval and
 * rejection, and auto-reroutes open escalations when the resolved
 * mandate holder changes.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-03-escalation-engine/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\Contracts\MandaatEscalatieServiceInterface;
use OCA\Dossiq\Service\Support\ConfiguredSchemaWriter;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Mandate escalation lifecycle.
 *
 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-03-escalation-engine/tasks.md
 */
class MandaatEscalatieService implements MandaatEscalatieServiceInterface {
	use SearchesObjects;

	/**
	 * The answer when no escalation path exists.
	 *
	 * @var array{mandaatId:string, userId:string}
	 */
	private const NO_PATH = ['mandaatId' => '', 'userId' => ''];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a new escalation.
	 *
	 * @param string $caseId Case id.
	 * @param string $decisionType Decision type.
	 * @param string $initiatorId Initiating user id.
	 * @param string $escalationReason Escalation reason.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function createEscalatie(string $caseId, string $decisionType, string $initiatorId, string $escalationReason): array {
		$path = $this->resolveEscalatiePath(decisionType: $decisionType, escalationReason: $escalationReason);
		$row = [
			'caseId' => $caseId,
			'decisionType' => $decisionType,
			'initiatorId' => $initiatorId,
			'escalationReason' => $escalationReason,
			// Key = schema property (renamed). Value = an INTERNAL array key
			// returned by resolveEscalationPath() in this same class, which is
			// not a schema property and does not move here.
			'targetMandateId' => $path['mandaatId'],
			'targetUserId' => $path['userId'],
			'status' => 'open',
			'createdAt' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
		];

		$saved = $this->save(schemaConfigKey: 'mandaat_escalatie_schema', object: $row);
		$this->logger->info(
			'Mandaat escalation created',
			[
				'caseId' => $caseId,
				'reason' => $escalationReason,
				'target' => $row['targetUserId'],
			]
		);
		return $saved;
	}//end createEscalatie()

	/**
	 * Resolve the next-higher mandate holder for a decision type.
	 *
	 * Walks Mandaat rows in descending plafond order; returns the first
	 * holder whose mandaat applies. Returns ['mandaatId'=>'', 'userId'=>'']
	 * when none is found.
	 *
	 * @param string $decisionType Decision type.
	 * @param string $escalationReason Reason, carried into the unresolved-path
	 *                                 warning so a dead-ended escalation is
	 *                                 traceable.
	 *
	 * @return array{mandaatId:string, userId:string}
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function resolveEscalatiePath(string $decisionType, string $escalationReason = ''): array {
		$search = $this->escalationSearch();
		if ($search === null) {
			return self::NO_PATH;
		}

		$mandaten = $this->activeMandaten(search: $search);
		if ($mandaten === null) {
			return self::NO_PATH;
		}

		foreach ($this->rankMandatenForDecisionType(mandaten: $mandaten, decisionType: $decisionType) as $m) {
			$path = $this->holderPath(search: $search, mandate: $m);
			if ($path !== null) {
				return $path;
			}
		}

		// No higher mandate holder exists for this decision type — surface it
		// with the reason so an escalation that silently lands nowhere is
		// traceable in the log rather than only visible as an empty target.
		$this->logger->warning(
			'Mandaat escalation path unresolved',
			['decisionType' => $decisionType, 'reason' => $escalationReason]
		);

		return self::NO_PATH;
	}//end resolveEscalatiePath()

	/**
	 * Where the path is looked up, or null when the store is not configured.
	 *
	 * @return EscalationSearch|null
	 */
	private function escalationSearch(): ?EscalationSearch {
		$objectService = $this->settingsService->getObjectService();
		$search = new EscalationSearch();
		$search->register = (string)$this->settingsService->getConfigValue('register');
		$search->mandateSchema = (string)$this->settingsService->getConfigValue('mandaat_schema');
		$search->assignmentSchema = (string)$this->settingsService->getConfigValue('medewerker_rol_toewijzing_schema');
		$hasBlank = in_array('', [$search->register, $search->mandateSchema, $search->assignmentSchema], true);
		if ($objectService === null || $hasBlank === true) {
			return null;
		}

		$search->objectService = $objectService;

		return $search;
	}//end escalationSearch()

	/**
	 * The active mandates, or null when they could not be read.
	 *
	 * @param EscalationSearch $search Where to look.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function activeMandaten(EscalationSearch $search): ?array {
		try {
			$mandaten = $this->searchObjectsAsArrays(
				objectService: $search->objectService,
				register: $search->register,
				schema: $search->mandateSchema,
				filters: ['status' => 'active']
			);
		} catch (\Throwable $e) {
			$mandaten = null;
		}

		return $mandaten;
	}//end activeMandaten()

	/**
	 * The path to the first holder of the mandate's role, primair first, or null.
	 *
	 * @param EscalationSearch     $search  Where to look.
	 * @param array<string, mixed> $mandate One applicable mandate.
	 *
	 * @return array{mandaatId:string, userId:string}|null
	 */
	private function holderPath(EscalationSearch $search, array $mandate): ?array {
		$assigns = $this->roleAssignments(search: $search, roleId: (string)($mandate['mandateeRole'] ?? ''));
		foreach ($assigns as $a) {
			$userId = (string)($a['userId'] ?? '');
			if ($userId !== '') {
				return ['mandaatId' => (string)($mandate['id'] ?? ''), 'userId' => $userId];
			}
		}

		return null;
	}//end holderPath()

	/**
	 * The assignments of one role, primair first; none for no role or a failed read.
	 *
	 * @param EscalationSearch $search Where to look.
	 * @param string           $roleId The role.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function roleAssignments(EscalationSearch $search, string $roleId): array {
		$assigns = [];
		if ($roleId === '') {
			return $assigns;
		}

		$assigns = $this->searchRoleAssignments(search: $search, roleId: $roleId);

		// Prefer primair toewijzing.
		return $this->primairFirst(assigns: $assigns);
	}//end roleAssignments()

	/**
	 * The assignments of one role as stored; none for a failed read.
	 *
	 * @param EscalationSearch $search Where to look.
	 * @param string $roleId The role.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function searchRoleAssignments(EscalationSearch $search, string $roleId): array {
		try {
			$assigns = $this->searchObjectsAsArrays(
				objectService: $search->objectService,
				register: $search->register,
				schema: $search->assignmentSchema,
				filters: ['roleId' => $roleId]
			);
		} catch (\Throwable $e) {
			$assigns = [];
		}

		return $assigns;
	}//end searchRoleAssignments()

	/**
	 * The assignments ordered primair, observer, tijdelijk, then the rest.
	 *
	 * @param array<int, array<string, mixed>> $assigns The assignments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function primairFirst(array $assigns): array {
		usort(
			$assigns,
			static function (array $a, array $b): int {
				$rank = static fn (array $r): int => match ((string)($r['allocationType'] ?? 'primair')) {
					'primair' => 0,
					'observer' => 1,
					'tijdelijk' => 2,
					default => 99,
				};

				return $rank($a) <=> $rank($b);
			}
		);

		return $assigns;
	}//end primairFirst()

	/**
	 * Keep the mandaten that apply to a decision type, highest plafond first.
	 *
	 * @param array<int, array<string, mixed>> $mandaten Active mandaat rows.
	 * @param string $decisionType Decision type.
	 *
	 * @return array<int, array<string, mixed>> The applicable mandaten, ranked.
	 */
	private function rankMandatenForDecisionType(array $mandaten, string $decisionType): array {
		$matching = [];
		foreach ($mandaten as $m) {
			$decTypes = (array)(($m['terms'] ?? [])['decisionTypes'] ?? []);
			if (count($decTypes) > 0 && in_array($decisionType, $decTypes, true) === false) {
				continue;
			}

			$matching[] = $m;
		}//end foreach

		// Sort by plafondCents descending (null/missing → 0).
		usort(
			$matching,
			static fn (array $a, array $b): int
				=> ((int)(($b['terms'] ?? [])['plafondCents'] ?? 0)) <=> ((int)(($a['terms'] ?? [])['plafondCents'] ?? 0))
		);

		return $matching;
	}//end rankMandatenForDecisionType()

	/**
	 * Approve an open escalation.
	 *
	 * @param string $escalationId Escalation id.
	 * @param string $mandaathouderUserId Approving user id (must match targetUserId).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When unauthorized or escalation missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function approveEscalatie(string $escalationId, string $mandaathouderUserId): array {
		$escalation = $this->findEscalation(escalationId: $escalationId);
		if ($escalation === null) {
			throw new RuntimeException('Escalation not found: ' . $escalationId);
		}

		if ((string)($escalation['targetUserId'] ?? '') !== $mandaathouderUserId) {
			throw new RuntimeException('Caller is not the resolved mandate holder');
		}

		if (($escalation['status'] ?? '') !== 'open') {
			throw new RuntimeException('Escalation not in open status');
		}

		$escalation['status'] = 'approved';
		$escalation['resolvedAt'] = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');
		return $this->save(schemaConfigKey: 'mandaat_escalatie_schema', object: $escalation);
	}//end approveEscalatie()

	/**
	 * Reject an open escalation.
	 *
	 * @param string $escalationId Escalation id.
	 * @param string $reason Rejection reason.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the escalation is missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function rejectEscalatie(string $escalationId, string $reason): array {
		$escalation = $this->findEscalation(escalationId: $escalationId);
		if ($escalation === null) {
			throw new RuntimeException('Escalation not found: ' . $escalationId);
		}

		if (($escalation['status'] ?? '') !== 'open') {
			throw new RuntimeException('Escalation not in open status');
		}

		$escalation['status'] = 'rejected';
		$escalation['rejectedReason'] = $reason;
		$escalation['resolvedAt'] = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');
		return $this->save(schemaConfigKey: 'mandaat_escalatie_schema', object: $escalation);
	}//end rejectEscalatie()

	/**
	 * Reroute all open escalations targeting `oldUserId` to `newUserId`.
	 *
	 * @param string $oldUserId Old user id.
	 * @param string $newUserId New user id.
	 *
	 * @return int Number of rerouted escalations.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-03-escalation-engine/tasks.md
	 */
	public function autoRerouteOnPersonnelChange(string $oldUserId, string $newUserId): int {
		$store = $this->escalationStore();
		if ($store === null) {
			return 0;
		}

		[$objectService, $register, $schema] = $store;
		try {
			$filters = ['status' => 'open', 'targetUserId' => $oldUserId];
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: $filters);
		} catch (\Throwable $e) {
			return 0;
		}

		return $this->rerouteRows(store: $store, rows: $rows, newUserId: $newUserId);
	}//end autoRerouteOnPersonnelChange()

	/**
	 * The object service, register and escalation schema, or null when any is missing.
	 *
	 * @return array{0: object, 1: string, 2: string}|null The escalation store, or null.
	 */
	private function escalationStore(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('mandaat_escalatie_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		return [$objectService, $register, $schema];
	}//end escalationStore()

	/**
	 * Point each escalation at the new user, counting the ones that saved.
	 *
	 * @param array{0: object, 1: string, 2: string} $store The escalation store.
	 * @param array<int, array<string, mixed>> $rows The open escalations.
	 * @param string $newUserId New user id.
	 *
	 * @return int Number of rerouted escalations.
	 */
	private function rerouteRows(array $store, array $rows, string $newUserId): int {
		[$objectService, $register, $schema] = $store;
		$count = 0;
		foreach ($rows as $row) {
			$row['targetUserId'] = $newUserId;
			try {
				$this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $row);
				$count++;
			} catch (\Throwable $e) {
				$this->logger->warning('Mandaat reroute failed', ['id' => $row['id'] ?? '', 'error' => $e->getMessage()]);
			}
		}

		return $count;
	}//end rerouteRows()

	/**
	 * Fetch a single escalation row by id.
	 *
	 * @param string $escalationId Id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findEscalation(string $escalationId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('mandaat_escalatie_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $escalationId
			);
		} catch (\Throwable $e) {
			return null;
		}
	}//end findEscalatie()

	/**
	 * Persist a payload to the configured schema.
	 *
	 * @param string $schemaConfigKey Config key.
	 * @param array<string, mixed> $object Payload.
	 *
	 * @return array<string, mixed>
	 */
	private function save(string $schemaConfigKey, array $object): array {
		$writer = new ConfiguredSchemaWriter(
			settingsService: $this->settingsService,
			onFailure: function (\Throwable $e) use ($schemaConfigKey): void {
				$this->logger->error('Mandaat persist failed', ['key' => $schemaConfigKey, 'error' => $e->getMessage()]);
			}
		);

		return $writer->save(schemaConfigKey: $schemaConfigKey, object: $object);
	}//end save()
}//end class
