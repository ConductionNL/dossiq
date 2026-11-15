<?php

/**
 * Resolves a conflict record: keeps the device's text or the office's.
 *
 * Every check runs before the first write, in the order the contract in
 * openspec/changes/inspection-conflict-card/design.md gives:
 *
 *  1. the record is read as the signed-in user; unreadable or absent is 404;
 *  2. a record that already has a resolution is 409;
 *  3. for `client_wins` only: the record must name its target (422), the
 *     target must be readable (404) and updatable (403) by the user, and the
 *     office value must still be the one the record holds (409, after which
 *     the record carries the newest office value and stays open). Then the
 *     device's values are written onto the target as the user.
 *
 * After that the record is marked resolved by the session user, and the
 * outbox row it names is marked synced.
 *
 * All reads and writes go through OpenRegister as the signed-in user, so its
 * RBAC applies and its audit trail names the user.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sync;

use OCA\Dossiq\Kernel\Contracts\ConflictResolutionServiceInterface;
use OCA\Dossiq\Service\Access\ObjectUpdateRight;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Support\StoredObjectAddress;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Keeps one of the two versions a conflict record holds.
 *
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The total is the sum of
 *  small private steps; each method stays under the method thresholds.
 */
class ConflictResolutionService implements ConflictResolutionServiceInterface {
	use SearchesObjects;

	/**
	 * The schema conflict records live in.
	 *
	 * @var string
	 */
	public const CONFLICT_SCHEMA = 'conflictRecord';

	/**
	 * The schema of the outbox rows a record refers to.
	 *
	 * @var string
	 */
	public const QUEUE_SCHEMA = 'syncQueue';

	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settings    Supplies OpenRegister's object service and the register.
	 * @param ObjectUpdateRight $updateRight Asks OpenRegister whether the user may update the target.
	 * @param ITimeFactory      $time        The moment of resolution.
	 * @param LoggerInterface   $logger      Records writes that could not be made.
	 *
	 * @spec openspec/changes/inspection-conflict-card/tasks.md
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly ObjectUpdateRight $updateRight,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve one conflict record for the signed-in user.
	 *
	 * @param string $id         The conflict record's id.
	 * @param string $resolution `client_wins` or `server_wins`.
	 * @param string $userId     The session user's uid.
	 *
	 * @return ConflictResolutionOutcome The status and body to answer with.
	 *
	 * @spec openspec/changes/inspection-conflict-card/tasks.md
	 */
	public function resolve(string $id, string $resolution, string $userId): ConflictResolutionOutcome {
		return $this->openAndResolve(id: $id, choice: ['resolution' => $resolution, 'userId' => $userId]);
	}//end resolve()

	/**
	 * Open the conflict on the configured store and apply the user's resolution.
	 *
	 * @param string                                    $id     The conflict record's id.
	 * @param array{resolution: string, userId: string} $choice `resolution` `client_wins` or `server_wins`; `userId` the session user's uid.
	 *
	 * @return ConflictResolutionOutcome The status and body to answer with.
	 */
	private function openAndResolve(string $id, array $choice): ConflictResolutionOutcome {
		[$resolution, $userId] = [$choice['resolution'], $choice['userId']];
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue(key: 'register');
		if ($objectService === null || $register === '') {
			return ConflictResolutionOutcome::refused(status: Http::STATUS_SERVICE_UNAVAILABLE, message: 'OpenRegister is niet beschikbaar.');
		}

		$request = new ConflictResolutionRequest(
			objectService: $objectService,
			register: $register,
			id: $id,
			resolution: $resolution,
			userId: $userId
		);
		return $this->resolveConflict(request: $request);
	}//end openAndResolve()

	/**
	 * Apply the resolution to the open conflict and mark it resolved, unless a step refuses.
	 *
	 * @param ConflictResolutionRequest $request The store, the conflict id, the resolution and the user.
	 *
	 * @return ConflictResolutionOutcome The outcome.
	 */
	private function resolveConflict(ConflictResolutionRequest $request): ConflictResolutionOutcome {
		$data = $this->openConflict(request: $request);
		if ($data instanceof ConflictResolutionOutcome) {
			return $data;
		}

		$refusal = $this->applyResolution(request: $request, data: $data);

		return ($refusal ?? $this->markResolved(request: $request, data: $data));
	}//end resolveConflict()

	/**
	 * Read the conflict record, refusing when it is missing or already resolved.
	 *
	 * @param ConflictResolutionRequest $request The conflict record and the user's choice.
	 *
	 * @return array<string, mixed>|ConflictResolutionOutcome The conflict record, or the refusal.
	 *
	 * @spec openspec/changes/inspection-conflict-card/tasks.md
	 */
	private function openConflict(ConflictResolutionRequest $request): array|ConflictResolutionOutcome {
		try {
			$record = $this->read(
				address: new StoredObjectAddress(
					objectService: $request->objectService,
					register: $request->register,
					schema: self::CONFLICT_SCHEMA,
					id: $request->id
				)
			);
		} catch (Throwable $e) {
			return ConflictResolutionOutcome::refused(status: Http::STATUS_NOT_FOUND, message: 'Dit conflict bestaat niet.');
		}

		$data = $this->dataOf(object: $record);
		if (is_string($data['resolution'] ?? null) === true && $data['resolution'] !== '') {
			return ConflictResolutionOutcome::refused(status: Http::STATUS_CONFLICT, message: 'Dit conflict is al opgelost.');
		}

		return $data;
	}//end openConflict()

	/**
	 * Apply the chosen side: for `client_wins`, write the device's values.
	 *
	 * @param ConflictResolutionRequest $request The conflict record and the user's choice.
	 * @param array<string, mixed>      $data    The conflict record.
	 *
	 * @return ConflictResolutionOutcome|null The refusal, or null once applied.
	 *
	 * @spec openspec/changes/inspection-conflict-card/tasks.md
	 */
	private function applyResolution(ConflictResolutionRequest $request, array $data): ?ConflictResolutionOutcome {
		if ($request->resolution === self::CLIENT_WINS) {
			$refusal = $this->writeDeviceValues(request: $request, data: $data);
			if ($refusal !== null) {
				return $refusal;
			}
		}

		return null;
	}//end applyResolution()

	/**
	 * Mark the conflict record resolved and its queue row synced.
	 *
	 * @param ConflictResolutionRequest $request The conflict record and the user's choice.
	 * @param array<string, mixed>      $data    The conflict record.
	 *
	 * @return ConflictResolutionOutcome The status and body to answer with.
	 *
	 * @spec openspec/changes/inspection-conflict-card/tasks.md
	 */
	private function markResolved(ConflictResolutionRequest $request, array $data): ConflictResolutionOutcome {
		$id = $request->id;
		$resolution = $request->resolution;
		$userId = $request->userId;
		$resolvedAt = $this->time->now()->format(DATE_ATOM);
		try {
			$this->patchObjectAsArray(
				objectService: $request->objectService,
				register: $request->register,
				schema: self::CONFLICT_SCHEMA,
				id: $id,
				changes: ['resolution' => $resolution, 'resolvedBy' => $userId, 'resolvedAt' => $resolvedAt]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the conflict record could not be marked resolved',
				['exception' => $e->getMessage(), 'id' => $id]
			);
			return ConflictResolutionOutcome::refused(
				status: Http::STATUS_INTERNAL_SERVER_ERROR,
				message: 'Het conflict kon niet als opgelost worden opgeslagen.'
			);
		}

		$this->markQueueRowSynced(request: $request, data: $data);

		return new ConflictResolutionOutcome(
			status: Http::STATUS_OK,
			body: ['id' => $id, 'resolution' => $resolution, 'resolvedBy' => $userId, 'resolvedAt' => $resolvedAt]
		);
	}//end markResolved()

	/**
	 * Write the device's values onto the target, after every check.
	 *
	 * @param ConflictResolutionRequest $request The conflict record and the user's choice.
	 * @param array<string, mixed>      $data    The conflict record.
	 *
	 * @return ConflictResolutionOutcome|null The refusal, or null once written.
	 */
	private function writeDeviceValues(ConflictResolutionRequest $request, array $data): ?ConflictResolutionOutcome {
		$target = $this->writableTarget(objectService: $request->objectService, data: $data);
		if ($target instanceof ConflictResolutionOutcome) {
			return $target;
		}

		$fields = $this->fieldsOf(data: $data);
		$current = $this->dataOf(object: $target);
		$refreshed = $this->refreshedServerVersion(data: $data, view: ['fields' => $fields, 'current' => $current]);
		if ($refreshed !== null) {
			$this->storeServerVersion(request: $request, refreshed: $refreshed);
			return ConflictResolutionOutcome::refused(
				status: Http::STATUS_CONFLICT,
				message: 'Op kantoor is de tekst intussen opnieuw gewijzigd. Kies opnieuw.'
			);
		}

		$changes = $this->deviceChanges(data: $data, view: ['fields' => $fields, 'current' => $current]);
		if ($changes === []) {
			return null;
		}

		return $this->writeOntoTarget(request: $request, write: ['target' => $this->targetOf(data: $data), 'changes' => $changes]);
	}//end writeDeviceValues()

	/**
	 * The conflict's target register, schema and id ('' when absent).
	 *
	 * @param array<string, mixed> $data The conflict record.
	 *
	 * @return array{0: string, 1: string, 2: string} Register, schema and id.
	 */
	private function targetOf(array $data): array {
		return [
			$this->stringOf(value: ($data['targetRegister'] ?? null)),
			$this->stringOf(value: ($data['targetSchema'] ?? null)),
			$this->stringOf(value: ($data['targetId'] ?? null)),
		];
	}//end targetOf()

	/**
	 * The conflict's target, read and checked for the update right.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param array<string, mixed> $data          The conflict record.
	 *
	 * @return object|ConflictResolutionOutcome The target, or the refusal.
	 */
	private function writableTarget(object $objectService, array $data): object {
		[$targetRegister, $targetSchema, $targetId] = $this->targetOf(data: $data);
		if ($targetId === '' || $targetRegister === '' || $targetSchema === '') {
			return ConflictResolutionOutcome::refused(
				status: Http::STATUS_UNPROCESSABLE_ENTITY,
				message: 'Dit conflict noemt geen object om de tekst op te schrijven.'
			);
		}

		try {
			$target = $this->read(
				address: new StoredObjectAddress(objectService: $objectService, register: $targetRegister, schema: $targetSchema, id: $targetId)
			);
		} catch (Throwable $e) {
			return ConflictResolutionOutcome::refused(
				status: Http::STATUS_NOT_FOUND,
				message: 'Het object van dit conflict bestaat niet.'
			);
		}

		if ($this->updateRight->allows(object: $target, schema: $targetSchema) === false) {
			return ConflictResolutionOutcome::refused(
				status: Http::STATUS_FORBIDDEN,
				message: 'U mag dit object niet wijzigen.'
			);
		}

		return $target;
	}//end writableTarget()

	/**
	 * The server version with the office's current values, or null when the
	 * office did not change any of the fields since the conflict was recorded.
	 *
	 * @param array<string, mixed>                                          $data The conflict record.
	 * @param array{fields: array<int, string>, current: array<string, mixed>} $view The conflicting field paths and the target's current data.
	 *
	 * @return array<string, mixed>|null The refreshed server version, or null.
	 */
	private function refreshedServerVersion(array $data, array $view): ?array {
		[$fields, $current] = [$view['fields'], $view['current']];
		$serverVersion = $this->arrayOf(value: ($data['serverVersion'] ?? null));
		$officeMoved = false;
		$refreshed = $serverVersion;
		foreach ($fields as $path) {
			$now = $this->valueAt(data: $current, path: $path);
			if ($now !== $this->valueAt(data: $serverVersion, path: $path)) {
				$officeMoved = true;
			}

			$refreshed = $this->withValueAt(data: $refreshed, placed: ['path' => $path, 'value' => $now]);
		}

		if ($officeMoved === false) {
			return null;
		}

		return $refreshed;
	}//end refreshedServerVersion()

	/**
	 * Store the newest office values on the conflict record; a failure is logged.
	 *
	 * @param ConflictResolutionRequest $request   The conflict record and the user's choice.
	 * @param array<string, mixed>      $refreshed The refreshed server version.
	 *
	 * @return void
	 */
	private function storeServerVersion(ConflictResolutionRequest $request, array $refreshed): void {
		try {
			$this->patchObjectAsArray(
				objectService: $request->objectService,
				register: $request->register,
				schema: self::CONFLICT_SCHEMA,
				id: $request->id,
				changes: ['serverVersion' => $refreshed]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the newest office value could not be stored on the conflict record',
				['exception' => $e->getMessage(), 'id' => $request->id]
			);
		}
	}//end storeServerVersion()

	/**
	 * The device's values per top-level field, merged into the current data.
	 *
	 * @param array<string, mixed>                                          $data The conflict record.
	 * @param array{fields: array<int, string>, current: array<string, mixed>} $view The conflicting field paths and the target's current data.
	 *
	 * @return array<string, mixed> The changes to write.
	 */
	private function deviceChanges(array $data, array $view): array {
		[$fields, $current] = [$view['fields'], $view['current']];
		$clientVersion = $this->arrayOf(value: ($data['clientVersion'] ?? null));
		$changes = [];
		foreach ($fields as $path) {
			$top = explode('.', $path)[0];
			$base = ($changes[$top] ?? ($current[$top] ?? null));
			$changes[$top] = $this->withValueAt(
				data: [$top => $base],
				placed: [
					'path' => $path,
					'value' => $this->valueAt(data: $clientVersion, path: $path),
				]
			)[$top];
		}

		return $changes;
	}//end deviceChanges()

	/**
	 * Write the changes onto the conflict's target.
	 *
	 * @param ConflictResolutionRequest $request The conflict record, for the object service and the log.
	 * @param array<string, mixed>      $write   Where the target lives (`target`: register, schema and id, as
	 *                                           targetOf() answers them) and the changes to write (`changes`).
	 *
	 * @return ConflictResolutionOutcome|null The refusal, or null once written.
	 */
	private function writeOntoTarget(ConflictResolutionRequest $request, array $write): ?ConflictResolutionOutcome {
		[$targetRegister, $targetSchema, $targetId] = $write['target'];
		$changes = $write['changes'];
		try {
			$this->patchObjectAsArray(
				objectService: $request->objectService,
				register: $targetRegister,
				schema: $targetSchema,
				id: $targetId,
				changes: $changes
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the device values could not be written onto the target',
				['exception' => $e->getMessage(), 'id' => $request->id]
			);
			return ConflictResolutionOutcome::refused(
				status: Http::STATUS_INTERNAL_SERVER_ERROR,
				message: 'De tekst kon niet worden opgeslagen.'
			);
		}

		return null;
	}//end writeOntoTarget()

	/**
	 * Mark the outbox row the record names as synced, when it exists.
	 *
	 * @param ConflictResolutionRequest $request The conflict record, for the object service and the register.
	 * @param array<string, mixed>      $data    The conflict record.
	 *
	 * @return void
	 */
	private function markQueueRowSynced(ConflictResolutionRequest $request, array $data): void {
		$objectService = $request->objectService;
		$register = $request->register;
		$ref = $this->stringOf(value: ($data['syncQueueRef'] ?? null));
		if ($ref === '') {
			return;
		}

		try {
			$this->read(address: new StoredObjectAddress(objectService: $objectService, register: $register, schema: self::QUEUE_SCHEMA, id: $ref));
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: self::QUEUE_SCHEMA,
				id: $ref,
				changes: ['status' => 'synced', 'lastError' => '']
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'Dossiq: the outbox row of a resolved conflict is gone or could not be marked synced',
				['exception' => $e->getMessage(), 'syncQueueRef' => $ref]
			);
		}
	}//end markQueueRowSynced()

	/**
	 * Read one object as the signed-in user.
	 *
	 * @param StoredObjectAddress $address OpenRegister's object service, and the register, schema and id of the object.
	 *
	 * @return object The object as OpenRegister returned it.
	 *
	 * @throws RuntimeException When the object is absent or not readable by the user.
	 */
	private function read(StoredObjectAddress $address): object {
		$object = $address->objectService->find($address->id, register: $address->register, schema: $address->schema);
		if (is_object($object) === false) {
			throw new RuntimeException('object_not_readable');
		}

		return $object;
	}//end read()

	/**
	 * The object's own data, without OpenRegister's metadata.
	 *
	 * @param object $object The object as OpenRegister returned it.
	 *
	 * @return array<string, mixed> The data.
	 */
	private function dataOf(object $object): array {
		if (method_exists($object, 'jsonSerialize') === false) {
			return [];
		}

		$data = $object->jsonSerialize();
		if (is_array($data) === false) {
			return [];
		}

		unset($data['@self'], $data['id']);

		return $data;
	}//end dataOf()

	/**
	 * The dot paths the record says both sides changed.
	 *
	 * @param array<string, mixed> $data The conflict record.
	 *
	 * @return array<int, string> The paths.
	 */
	private function fieldsOf(array $data): array {
		$fields = ($data['conflictFields'] ?? []);
		if (is_array($fields) === false) {
			return [];
		}

		return array_values(
			array_filter(
				$fields,
				static fn ($path): bool => (is_string($path) === true && $path !== '')
			)
		);
	}//end fieldsOf()

	/**
	 * The value at a dot path, or null when the path does not exist.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param string               $path The dot path.
	 *
	 * @return mixed The value.
	 */
	private function valueAt(array $data, string $path): mixed {
		$node = $data;
		foreach (explode('.', $path) as $segment) {
			if (is_array($node) === false || array_key_exists($this->keyOf(segment: $segment), $node) === false) {
				return null;
			}

			$node = $node[$this->keyOf(segment: $segment)];
		}

		return $node;
	}//end valueAt()

	/**
	 * The data with the value set at a dot path.
	 *
	 * @param array<string, mixed>              $data   The data.
	 * @param array{path: string, value: mixed} $placed `path` the dot path; `value` the value.
	 *
	 * @return array<string, mixed> The data with the value in place.
	 */
	private function withValueAt(array $data, array $placed): array {
		[$path, $value] = [$placed['path'], $placed['value']];
		$segments = explode('.', $path);
		$key = $this->keyOf(segment: array_shift($segments));
		if ($segments === []) {
			$data[$key] = $value;
			return $data;
		}

		$child = $this->arrayOf(value: ($data[$key] ?? null));
		$data[$key] = $this->withValueAt(data: $child, placed: ['path' => implode('.', $segments), 'value' => $value]);

		return $data;
	}//end withValueAt()

	/**
	 * A path segment as an array key: digits index a list.
	 *
	 * @param string $segment The segment.
	 *
	 * @return int|string The key.
	 */
	private function keyOf(string $segment): int|string {
		if (ctype_digit($segment) === true) {
			return (int)$segment;
		}

		return $segment;
	}//end keyOf()

	/**
	 * A value as an array, or an empty one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<int|string, mixed> The array.
	 */
	private function arrayOf(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayOf()

	/**
	 * A scalar value as a string, or an empty one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The string.
	 */
	private function stringOf(mixed $value): string {
		if (is_scalar($value) === true) {
			return trim((string)$value);
		}

		return '';
	}//end stringOf()
}//end class
