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
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
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

	use SearchesObjects;

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
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister plus app config.
	 * @param DeletionWindowReader $windowReader Reads the marker and the window off a row.
	 * @param IUserSession $userSession The session, for the actor on a restore.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly DeletionWindowReader $windowReader,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The deleted cases this caller may see, newest first.
	 *
	 * SCOPED, NOT LISTED WHOLE. The trash holds every deleted case on the
	 * instance, so an unscoped lens would hand any signed-in user the title
	 * and number of every bezwaar anybody ever deleted. A row is shown to an
	 * administrator, to the handler the case was assigned to, and to whoever
	 * deleted it. That mirrors `CaseAccessGuard::hasCaseMutationAccess()`,
	 * read off the stored payload because the live case is gone.
	 *
	 * @param int $limit How many rows to return.
	 * @param int $offset How many rows to skip.
	 * @param string $forUser The caller's user id; an empty id sees nothing.
	 * @param bool $isAdmin Whether the caller is an administrator.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the caller's
	 * authority, resolved once by the controller and handed down. Reading it
	 * here instead would put an IGroupManager in a class whose whole job is to
	 * read OpenRegister, and would make the scoping untestable without one.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function deletedCases(
		int $limit = self::PAGE_SIZE,
		int $offset = 0,
		string $forUser = '',
		bool $isAdmin = false,
	): array {
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

			if ($this->maySee(entity: $row, userId: $forUser, isAdmin: $isAdmin) === false) {
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
	 * One live case, as an associative array, or null.
	 *
	 * The clocks are read off a case that still exists, so this read goes
	 * through the ordinary object path rather than the deleted one.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed>|null The case payload, or null.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function liveCase(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '' || $caseId === '') {
			return null;
		}

		// The catch LOGS and does not answer: a store that could not be read is
		// a different fact from a case that is not there, and a bare `return
		// null` in the catch makes the two indistinguishable downstream.
		$case = null;
		try {
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the case behind the retention clocks could not be read',
				['caseId' => $caseId, 'error' => $e->getMessage()]
			);
		}

		return $case;
	}//end liveCase()

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

		$window = $this->windowReader->windowFor(entity: $entity);
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

		// A miss is what the mapper RAISES rather than returns, so the catch is
		// the read-miss path. It logs and answers nothing here; the single
		// answer below covers a miss, a row of another schema and a row that
		// is not in the recycle state, which are the same answer to a caller.
		$entity = null;
		try {
			$entity = $mapper->find(identifier: $caseId, register: null, schema: null, includeDeleted: true);
		} catch (Throwable $e) {
			$this->logger->info(
				'Dossiq: no deleted case behind this id',
				['caseId' => $caseId, 'error' => $e->getMessage()]
			);
		}

		if ($entity === null
			|| $this->isCase(entity: $entity) === false
			|| $this->windowReader->isSoftDeleted(entity: $entity) === false
		) {
			return null;
		}

		return $entity;
	}//end findDeleted()

	/**
	 * One row of the deleted lens.
	 *
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(object $entity): array {
		$payload = $this->windowReader->payload(entity: $entity);
		$marker = $this->windowReader->marker(entity: $entity);
		$window = $this->windowReader->windowFor(entity: $entity);

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
	 * The published recovery window of one deleted entity.
	 *
	 * A pass-through to {@see DeletionWindowReader}, kept on this class
	 * because it is the one every caller already holds.
	 *
	 * @param object $entity OpenRegister's object entity.
	 *
	 * @return array<string, mixed>|null The window, or null when the row carries none.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function window(object $entity): ?array {
		return $this->windowReader->windowFor(entity: $entity);
	}//end window()

	/**
	 * Whether this caller may see one deleted case in the lens.
	 *
	 * Fails closed at every branch: an unresolved caller, a row with no
	 * assignee and no deleter, and an unreadable payload all deny.
	 *
	 * @param object $entity OpenRegister's object entity.
	 * @param string $userId The caller's user id.
	 * @param bool $isAdmin Whether the caller is an administrator.
	 *
	 * @return bool True when the row belongs in this caller's lens.
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function maySee(object $entity, string $userId, bool $isAdmin): bool {
		if ($isAdmin === true) {
			return true;
		}

		if ($userId === '') {
			return false;
		}

		$marker = $this->windowReader->marker(entity: $entity);
		if (trim((string)($marker['deletedBy'] ?? '')) === $userId) {
			return true;
		}

		$payload = $this->windowReader->payload(entity: $entity);

		return (trim((string)($payload['assignee'] ?? '')) === $userId);
	}//end maySee()

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

}//end class
