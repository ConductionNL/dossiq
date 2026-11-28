<?php

/**
 * Dossiq ConfiguredSchemaStore
 *
 * The records of one configured schema in the app's register: found, listed,
 * created and updated through OpenRegister's object service.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use Closure;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use RuntimeException;

/**
 * The records of one configured schema, read and written as the caller.
 *
 * A read answers nothing (null or an empty list) when OpenRegister or the
 * schema is not there; a write refuses instead, naming what is missing.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class ConfiguredSchemaStore {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The object service and the configured register and schema.
	 * @param string $schemaKey The config key that names the schema.
	 * @param string $notConfigured The refusal when a create finds no register or schema.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly string $schemaKey,
		private readonly string $notConfigured,
	) {
	}//end __construct()

	/**
	 * One record by id, or null when it, OpenRegister or the schema is absent.
	 *
	 * @param string $id The record's UUID.
	 *
	 * @return array<string, mixed>|null The record.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function find(string $id): ?array {
		$target = $this->target();
		if ($target === null) {
			return null;
		}

		return $this->findObjectAsArray(
			objectService: $target[0],
			register: $target[1],
			schema: $target[2],
			id: $id
		);
	}//end find()

	/**
	 * The records matching the filters, or none when OpenRegister or the schema is absent.
	 *
	 * @param array<string, mixed> $filters Object-field filters and pagination keys.
	 *
	 * @return array<int, array<string, mixed>> The records.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function search(array $filters): array {
		$target = $this->target();
		if ($target === null) {
			return [];
		}

		return $this->searchObjectsAsArrays(
			objectService: $target[0],
			register: $target[1],
			schema: $target[2],
			filters: $filters
		);
	}//end search()

	/**
	 * Create a record; refuses when OpenRegister or the schema is absent.
	 *
	 * The refusal comes before `$prepare` runs, so a missing store is reported
	 * before anything about the record itself is worked out. The record never
	 * carries the keys that name a stored object ({@see CallerIdentity}), so a
	 * create cannot be pointed at an existing object.
	 *
	 * @param array<string, mixed> $object The record to create.
	 * @param Closure|null $prepare Turns the record into what is saved, after the checks.
	 *
	 * @return mixed What the object service answered.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or the schema is not configured.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function create(array $object, ?Closure $prepare = null): mixed {
		$objectService = $this->objectService();
		$register      = $this->settingsService->getConfigValue('register');
		$schema        = $this->settingsService->getConfigValue($this->schemaKey);

		if (empty($register) === true || empty($schema) === true) {
			throw new RuntimeException($this->notConfigured);
		}

		$object = array_diff_key($object, CallerIdentity::KEYS);
		if ($prepare !== null) {
			$object = $prepare($object);
		}

		return $objectService->saveObject(
			object: $object,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema))
		);
	}//end create()

	/**
	 * Update a record; refuses only when OpenRegister is absent.
	 *
	 * The register and schema are passed as configured, as the update always did.
	 *
	 * @param string $id The record's UUID.
	 * @param array<string, mixed> $object The fields to write.
	 *
	 * @return mixed What the object service answered.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function update(string $id, array $object): mixed {
		$objectService = $this->objectService();
		$register      = $this->settingsService->getConfigValue('register');
		$schema        = $this->settingsService->getConfigValue($this->schemaKey);

		return $objectService->saveObject(
			object: $object,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema), uuid: $id)
		);
	}//end update()

	/**
	 * The object service, or a refusal when OpenRegister is not there.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function objectService(): object {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		return $objectService;
	}//end objectService()

	/**
	 * The object service, register and schema, or null when any is absent.
	 *
	 * @return array{0: object, 1: string, 2: string}|null The read target.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function target(): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema   = $this->settingsService->getConfigValue($this->schemaKey);

		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		return [$objectService, $register, $schema];
	}//end target()
}//end class
