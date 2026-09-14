<?php

/**
 * The recovery window of a deleted case, read rather than rebuilt.
 *
 * OpenRegister soft-deletes an object and states the window it can come back
 * in: a `destroyableFrom` date, the days left, and the rule that set the
 * retention (openregister#3724, `delete-window-and-recorded-destruction`).
 * Decision D10 says dossiq exposes that window on the case and builds no
 * second recycle state, so nothing here writes a deletion marker, a trash
 * table or a purge job.
 *
 * What dossiq adds is the reading a handler needs. A case that vanishes from
 * the list and reappears nowhere is indistinguishable from a case that was
 * destroyed, so somebody who deleted the wrong bezwaar cannot tell whether to
 * panic. This service answers with the cases that are still recoverable and
 * the date each window ends.
 *
 * The delete guard stays where it is. `CaseDeleteGuardListener` subscribes to
 * OpenRegister's pre-persist `ObjectDeletingEvent`, so it runs before the
 * object reaches the recycle state whichever door the delete came through.
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
use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Lists deleted cases with their window, and restores one as a recorded act.
 *
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */
class CaseRecycleService {

	/**
	 * OpenRegister's mapper over the per-register magic tables.
	 *
	 * Referenced by name rather than imported: dossiq runs on instances where
	 * OpenRegister is absent, and a `use` of a missing class turns every read
	 * of this file into a fatal rather than a graceful refusal.
	 *
	 * @var string
	 */
	public const MAGIC_MAPPER = 'OCA\\OpenRegister\\Db\\MagicMapper';

	/**
	 * OpenRegister's audit trail mapper, which records the restore.
	 *
	 * @var string
	 */
	public const AUDIT_MAPPER = 'OCA\\OpenRegister\\Db\\AuditTrailMapper';

	/**
	 * The audit action a restore is recorded under.
	 *
	 * @var string
	 */
	public const RESTORE_ACTION = 'object.restored';

	/**
	 * How many deleted rows one page of the lens holds.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 50;

	/**
	 * The retention OpenRegister falls back to when nothing states one.
	 *
	 * Mirrors `DeletionWindowService::DEFAULT_RETENTION_DAYS`. It is the
	 * fallback for a row deleted before that service shipped, which carries a
	 * `purgeDate` and no `destroyableFrom`.
	 *
	 * @var int
	 */
	public const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister plus app config.
	 * @param IUserSession $userSession The session, for the actor on a restore.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The deleted cases that can still be recovered, newest first.
	 *
	 * @param int $limit How many rows to return.
	 * @param int $offset How many rows to skip.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int}
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function deletedCases(int $limit = self::PAGE_SIZE, int $offset = 0): array {
		$mapper = $this->settingsService->getOpenRegisterClass(class: self::MAGIC_MAPPER);
		if ($mapper === null || method_exists($mapper, 'findDeletedAcrossAllMagicTables') === false) {
			return [
				'results' => [],
				'total' => 0,
			];
		}

		try {
			$rows = $mapper->findDeletedAcrossAllMagicTables(limit: $limit, offset: $offset);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: could not read the deleted cases: ' . $e->getMessage());
			return [
				'results' => [],
				'total' => 0,
			];
		}

		$cases = [];
		foreach ($rows as $row) {
			if ($this->isCase(entity: $row) === false) {
				continue;
			}

			$cases[] = $this->row(entity: $row);
		}

		return [
			'results' => $cases,
			'total' => count($cases),
		];
	}//end deletedCases()

	/**
	 * One deleted case with its window, or null when the id is not one.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function deletedCase(string $caseId): ?array {
		$entity = $this->findDeleted(caseId: $caseId);
		if ($entity === null) {
			return null;
		}

		return $this->row(entity: $entity);
	}//end deletedCase()

	/**
	 * Restore a deleted case, and record who did it and when.
	 *
	 * The record is written first and the failure to write one is logged
	 * rather than thrown: a restore that worked must not report itself failed
	 * because its record could not be saved. That is the opposite trade-off to
	 * a destruction, and the difference is that a restore can be undone.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> What was restored, and inside which window.
	 *
	 * @throws RuntimeException `case_not_deleted` when the case is not in the recycle state.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function restore(string $caseId): array {
		$entity = $this->findDeleted(caseId: $caseId);
		if ($entity === null) {
			throw new RuntimeException('case_not_deleted');
		}

		$window = $this->window(entity: $entity);
		$mapper = $this->requireMapper();
		$mapper->restoreObject(uuid: $caseId);
		$this->recordRestore(entity: $entity, window: $window);

		return [
			'success' => true,
			'caseId' => $caseId,
			'restoredWithin' => $window,
		];
	}//end restore()

	/**
	 * The soft-deleted case entity behind an id, or null.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return object|null OpenRegister's object entity, or null.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function findDeleted(string $caseId): ?object {
		$mapper = $this->settingsService->getOpenRegisterClass(class: self::MAGIC_MAPPER);
		if ($mapper === null || $caseId === '') {
			return null;
		}

		try {
			$entity = $mapper->find(identifier: $caseId, register: null, schema: null, includeDeleted: true);
		} catch (Throwable $e) {
			$this->logger->info('Dossiq: no deleted case behind ' . $caseId . ': ' . $e->getMessage());
			return null;
		}

		if ($this->isCase(entity: $entity) === false || $this->isSoftDeleted(entity: $entity) === false) {
			return null;
		}

		return $entity;
	}//end findDeleted()

	/**
	 * The published window of one deleted entity.
	 *
	 * OpenRegister's `DeletionWindowService` is asked first, because it is the
	 * single definition of the window and it knows which rule set the
	 * retention. A row deleted before that service shipped carries only a
	 * `purgeDate`, so the fallback reads that and says the default applied.
	 *
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return array<string, mixed>|null The window, or null when the row carries none.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function window(object $entity): ?array {
		$service = $this->settingsService->getOpenRegisterClass(
			class: 'OCA\\OpenRegister\\Service\\Deletion\\DeletionWindowService'
		);

		if ($service !== null && method_exists($service, 'windowFor') === true) {
			try {
				$window = $service->windowFor(object: $entity, schema: null);
				if ($window !== null && method_exists($window, 'toArray') === true) {
					return $window->toArray();
				}
			} catch (Throwable $e) {
				$this->logger->info('Dossiq: OpenRegister could not state the window: ' . $e->getMessage());
			}
		}

		return $this->windowFromMarker(entity: $entity);
	}//end window()

	/**
	 * The window read straight off the deletion marker.
	 *
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return array<string, mixed>|null The window, or null.
	 */
	private function windowFromMarker(object $entity): ?array {
		$marker = $this->marker(entity: $entity);
		$endsOn = trim((string)($marker['destroyableFrom'] ?? ($marker['purgeDate'] ?? '')));
		if ($endsOn === '') {
			return null;
		}

		try {
			$ends = new DateTimeImmutable(substr($endsOn, 0, 10));
		} catch (Throwable $e) {
			return null;
		}

		$today = new DateTimeImmutable('today');
		$remaining = 0;
		if ($ends > $today) {
			$remaining = (int)$today->diff($ends)->days;
		}

		$retention = ($marker['retentionPeriod'] ?? null);
		if (is_numeric($retention) === false || (int)$retention < 1) {
			$retention = self::DEFAULT_RETENTION_DAYS;
		}

		return [
			'deletedAt' => trim((string)($marker['deletedAt'] ?? ($marker['deleted'] ?? ''))),
			'destroyableFrom' => $ends->format(DATE_ATOM),
			'daysRemaining' => $remaining,
			'retentionDays' => (int)$retention,
			'retentionSource' => 'default',
			'lapsed' => ($remaining === 0),
		];
	}//end windowFromMarker()

	/**
	 * One row of the deleted lens.
	 *
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(object $entity): array {
		$payload = $this->payload(entity: $entity);
		$marker = $this->marker(entity: $entity);
		$window = $this->window(entity: $entity);

		return [
			'id' => (string)$entity->getUuid(),
			'identifier' => (string)($payload['identifier'] ?? ''),
			'title' => (string)($payload['title'] ?? ''),
			'deletedAt' => trim((string)($marker['deletedAt'] ?? ($marker['deleted'] ?? ''))),
			'deletedBy' => trim((string)($marker['deletedBy'] ?? '')),
			'windowEndsOn' => substr((string)($window['destroyableFrom'] ?? ''), 0, 10),
			'daysRemaining' => ($window['daysRemaining'] ?? null),
			'lapsed' => ($window['lapsed'] ?? null),
			'deletionWindow' => $window,
		];
	}//end row()

	/**
	 * Record the restore with the actor who made it.
	 *
	 * @param object $entity The case restored.
	 * @param array<string, mixed>|null $window The window it came back inside.
	 *
	 * @return void
	 */
	private function recordRestore(object $entity, ?array $window): void {
		$mapper = $this->settingsService->getOpenRegisterClass(class: self::AUDIT_MAPPER);
		if ($mapper === null || method_exists($mapper, 'createAuditTrailEntry') === false) {
			return;
		}

		$user = $this->userSession->getUser();
		$actor = 'system';
		$actorName = 'System';
		if ($user !== null) {
			$actor = $user->getUID();
			$actorName = $user->getDisplayName();
		}

		try {
			$mapper->createAuditTrailEntry(
				object: $entity,
				action: self::RESTORE_ACTION,
				context: [
					'restoredBy' => $actor,
					'restoredAt' => (new DateTime())->format(DateTime::ATOM),
					'objectUuid' => (string)$entity->getUuid(),
					'restoredWithin' => $window,
				],
				actorId: $actor,
				actorName: $actorName
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the case was restored but the record could not be written: ' . $e->getMessage()
			);
		}
	}//end recordRestore()

	/**
	 * OpenRegister's mapper, or an exception.
	 *
	 * @return object The mapper.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function requireMapper(): object {
		$mapper = $this->settingsService->getOpenRegisterClass(class: self::MAGIC_MAPPER);
		if ($mapper === null) {
			throw new RuntimeException('openregister_unavailable');
		}

		return $mapper;
	}//end requireMapper()

	/**
	 * Whether an entity belongs to the configured case schema.
	 *
	 * @param mixed $entity OpenRegister's object entity.
	 *
	 * @return bool True when this is a case.
	 */
	private function isCase(mixed $entity): bool {
		if (is_object($entity) === false || method_exists($entity, 'getSchema') === false) {
			return false;
		}

		$expected = $this->settingsService->getConfigValue('case_schema');
		if ($expected === '') {
			return false;
		}

		return ((string)$entity->getSchema() === $expected);
	}//end isCase()

	/**
	 * Whether the entity is in the recycle state.
	 *
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return bool True when soft-deleted.
	 */
	private function isSoftDeleted(object $entity): bool {
		if (method_exists($entity, 'isSoftDeleted') === true) {
			return ($entity->isSoftDeleted() === true);
		}

		return ($this->marker(entity: $entity) !== []);
	}//end isSoftDeleted()

	/**
	 * The deletion marker of an entity.
	 *
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return array<string, mixed> The marker, or an empty array.
	 */
	private function marker(object $entity): array {
		if (method_exists($entity, 'getDeleted') === false) {
			return [];
		}

		$marker = $entity->getDeleted();

		return (is_array($marker) === true ? $marker : []);
	}//end marker()

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
