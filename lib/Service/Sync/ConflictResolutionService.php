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

use OCA\Dossiq\Service\Access\ObjectUpdateRight;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Keeps one of the two versions a conflict record holds.
 *
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */
class ConflictResolutionService {
	use SearchesObjects;

	/**
	 * The device's version wins.
	 *
	 * @var string
	 */
	public const CLIENT_WINS = 'client_wins';

	/**
	 * The office's version wins.
	 *
	 * @var string
	 */
	public const SERVER_WINS = 'server_wins';

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
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue(key: 'register');
		if ($objectService === null || $register === '') {
			return ConflictResolutionOutcome::refused(
				status: Http::STATUS_SERVICE_UNAVAILABLE,
				message: 'OpenRegister is niet beschikbaar.'
			);
		}

		try {
			$record = $this->read(objectService: $objectService, register: $register, schema: self::CONFLICT_SCHEMA, id: $id);
		} catch (Throwable $e) {
			return ConflictResolutionOutcome::refused(status: Http::STATUS_NOT_FOUND, message: 'Dit conflict bestaat niet.');
		}

		$data = $this->dataOf(object: $record);
		if (is_string($data['resolution'] ?? null) === true && $data['resolution'] !== '') {
			return ConflictResolutionOutcome::refused(status: Http::STATUS_CONFLICT, message: 'Dit conflict is al opgelost.');
		}

		if ($resolution === self::CLIENT_WINS) {
			$refusal = $this->writeDeviceValues(objectService: $objectService, register: $register, id: $id, data: $data);
			if ($refusal !== null) {
				return $refusal;
			}
		}

		$resolvedAt = $this->time->now()->format(DATE_ATOM);
		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
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

		$this->markQueueRowSynced(objectService: $objectService, register: $register, data: $data);

		return new ConflictResolutionOutcome(
			status: Http::STATUS_OK,
			body: ['id' => $id, 'resolution' => $resolution, 'resolvedBy' => $userId, 'resolvedAt' => $resolvedAt]
		);
	}//end resolve()

	/**
	 * Write the device's values onto the target, after every check.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register of the conflict record.
	 * @param string               $id            The conflict record's id.
	 * @param array<string, mixed> $data          The conflict record.
	 *
	 * @return ConflictResolutionOutcome|null The refusal, or null once written.
	 */
	private function writeDeviceValues(
		object $objectService,
		string $register,
		string $id,
		array $data,
	): ?ConflictResolutionOutcome {
		$targetId = $this->stringOf(value: ($data['targetId'] ?? null));
		$targetRegister = $this->stringOf(value: ($data['targetRegister'] ?? null));
		$targetSchema = $this->stringOf(value: ($data['targetSchema'] ?? null));
		if ($targetId === '' || $targetRegister === '' || $targetSchema === '') {
			return ConflictResolutionOutcome::refused(
				status: Http::STATUS_UNPROCESSABLE_ENTITY,
				message: 'Dit conflict noemt geen object om de tekst op te schrijven.'
			);
		}

		try {
			$target = $this->read(objectService: $objectService, register: $targetRegister, schema: $targetSchema, id: $targetId);
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

		$fields = $this->fieldsOf(data: $data);
		$current = $this->dataOf(object: $target);
		$serverVersion = $this->arrayOf(value: ($data['serverVersion'] ?? null));
		$clientVersion = $this->arrayOf(value: ($data['clientVersion'] ?? null));

		$officeMoved = false;
		$refreshed = $serverVersion;
		foreach ($fields as $path) {
			$now = $this->valueAt(data: $current, path: $path);
			if ($now !== $this->valueAt(data: $serverVersion, path: $path)) {
				$officeMoved = true;
			}

			$refreshed = $this->withValueAt(data: $refreshed, path: $path, value: $now);
		}

		if ($officeMoved === true) {
			try {
				$this->patchObjectAsArray(
					objectService: $objectService,
					register: $register,
					schema: self::CONFLICT_SCHEMA,
					id: $id,
					changes: ['serverVersion' => $refreshed]
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: the newest office value could not be stored on the conflict record',
					['exception' => $e->getMessage(), 'id' => $id]
				);
			}

			return ConflictResolutionOutcome::refused(
				status: Http::STATUS_CONFLICT,
				message: 'Op kantoor is de tekst intussen opnieuw gewijzigd. Kies opnieuw.'
			);
		}

		$changes = [];
		foreach ($fields as $path) {
			$top = explode('.', $path)[0];
			$base = ($changes[$top] ?? ($current[$top] ?? null));
			$changes[$top] = $this->withValueAt(
				data: [$top => $base],
				path: $path,
				value: $this->valueAt(data: $clientVersion, path: $path)
			)[$top];
		}

		if ($changes === []) {
			return null;
		}

		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $targetRegister,
				schema: $targetSchema,
				id: $targetId,
				changes: $changes
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the device values could not be written onto the target',
				['exception' => $e->getMessage(), 'id' => $id]
			);
			return ConflictResolutionOutcome::refused(
				status: Http::STATUS_INTERNAL_SERVER_ERROR,
				message: 'De tekst kon niet worden opgeslagen.'
			);
		}

		return null;
	}//end writeDeviceValues()

	/**
	 * Mark the outbox row the record names as synced, when it exists.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param array<string, mixed> $data          The conflict record.
	 *
	 * @return void
	 */
	private function markQueueRowSynced(object $objectService, string $register, array $data): void {
		$ref = $this->stringOf(value: ($data['syncQueueRef'] ?? null));
		if ($ref === '') {
			return;
		}

		try {
			$this->read(objectService: $objectService, register: $register, schema: self::QUEUE_SCHEMA, id: $ref);
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
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 * @param string $schema        The schema.
	 * @param string $id            The object id.
	 *
	 * @return object The object as OpenRegister returned it.
	 *
	 * @throws RuntimeException When the object is absent or not readable by the user.
	 */
	private function read(object $objectService, string $register, string $schema, string $id): object {
		$object = $objectService->find($id, register: $register, schema: $schema);
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
	 * @param array<string, mixed> $data  The data.
	 * @param string               $path  The dot path.
	 * @param mixed                $value The value.
	 *
	 * @return array<string, mixed> The data with the value in place.
	 */
	private function withValueAt(array $data, string $path, mixed $value): array {
		$segments = explode('.', $path);
		$key = $this->keyOf(segment: array_shift($segments));
		if ($segments === []) {
			$data[$key] = $value;
			return $data;
		}

		$child = $this->arrayOf(value: ($data[$key] ?? null));
		$data[$key] = $this->withValueAt(data: $child, path: implode('.', $segments), value: $value);

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
