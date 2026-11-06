<?php

/**
 * The one OpenRegister seam the starter-content services read and write through.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Starter
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
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes rows of a configured schema, and says plainly when it cannot.
 *
 * Nine services in this change touch OpenRegister, and ADR-011 says search
 * before implementing a utility. This is that one utility: every one of them
 * resolves its register and schema the same way and fails the same way, so a
 * misconfigured schema reads as `false` once rather than as nine different
 * silent no-ops.
 *
 * 🔑 IT ANSWERS `null`, NEVER AN EMPTY LIST, WHEN THE STORE IS UNREACHABLE.
 * An empty list and "OpenRegister is not configured" are different facts, and
 * conflating them is how an adoption screen reads "nothing shipped" on an
 * instance where the schema key was simply never written. ADR-102: absence
 * fails closed with a status.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
class StarterStore {
	use SearchesObjects;

	/**
	 * Whether rows are read and written past the caller's access check; see asSystem().
	 *
	 * @var boolean
	 */
	private bool $pastAccessCheck = false;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings The register and schema resolver.
	 * @param LoggerInterface $logger   Logger.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * This store, reading and writing rows past the caller's access check.
	 *
	 * Only for a package install, which an administrator starts from the admin
	 * settings or `occ`: a CLI run has no user, and a schema with authorization
	 * rules (a case) answers nothing to it.
	 *
	 * @return self The store as the system.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function asSystem(): self {
		$system = clone $this;
		$system->pastAccessCheck = true;

		return $system;
	}//end asSystem()

	/**
	 * The schema id the app config key names here, or the empty string.
	 *
	 * @param string $configKey The app config key naming the schema.
	 *
	 * @return string The schema id.
	 *
	 * @spec openspec/specs/case-type-seed-data/spec.md
	 */
	public function schemaIdOf(string $configKey): string {
		return (string)$this->settings->getConfigValue(key: $configKey);
	}//end schemaIdOf()

	/**
	 * Whether the store can be reached and the schema is configured.
	 *
	 * @param string $configKey The app config key naming the schema.
	 *
	 * @return boolean True when reads and writes will be attempted.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function available(string $configKey): bool {
		return ($this->context(configKey: $configKey) !== null);
	}//end available()

	/**
	 * Every row of a schema matching the filters.
	 *
	 * @param string               $configKey The app config key naming the schema.
	 * @param array<string, mixed> $filters   Equality filters, plus `_limit`.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null when unreachable.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function rows(string $configKey, array $filters = []): ?array {
		$context = $this->context(configKey: $configKey);
		if ($context === null) {
			return null;
		}

		if (isset($filters['_limit']) === false) {
			$filters['_limit'] = 500;
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $context['service'],
				register: $context['register'],
				schema: $context['schema'],
				filters: $filters,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq starter: could not list rows',
				['schema' => $configKey, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end rows()

	/**
	 * One row by id.
	 *
	 * @param string $configKey The app config key naming the schema.
	 * @param string $id        The row's id.
	 *
	 * @return array<string, mixed>|null The row, or null when it is not there.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function row(string $configKey, string $id): ?array {
		$context = $this->context(configKey: $configKey);
		if ($context === null || $id === '') {
			return null;
		}

		try {
			if ($this->pastAccessCheck === true) {
				return $this->systemFind(context: $context, id: $id);
			}

			return $this->findObjectAsArray(
				objectService: $context['service'],
				register: $context['register'],
				schema: $context['schema'],
				id: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq starter: could not read a row',
				['schema' => $configKey, 'id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end row()

	/**
	 * Write a row, creating it when no id is given.
	 *
	 * @param string               $configKey The app config key naming the schema.
	 * @param array<string, mixed> $payload   The row to write.
	 * @param string|null          $id        The id to update, or null to create.
	 *
	 * @return array<string, mixed>|null The stored row, or null when the write failed.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function save(string $configKey, array $payload, ?string $id = null): ?array {
		$context = $this->context(configKey: $configKey);
		if ($context === null) {
			return null;
		}

		try {
			if ($this->pastAccessCheck === true) {
				return $this->asArray(
					object: $context['service']->saveObject(
						object: $payload,
						register: $context['register'],
						schema: $context['schema'],
						uuid: $id,
						_rbac: false,
					)
				);
			}

			return $this->saveObjectAsArray(
				objectService: $context['service'],
				register: $context['register'],
				schema: $context['schema'],
				object: $payload,
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq starter: could not write a row',
				['schema' => $configKey, 'id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end save()

	/**
	 * Remove a row.
	 *
	 * @param string $configKey The app config key naming the schema.
	 * @param string $id        The row's id.
	 *
	 * @return boolean True when the row is gone.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function delete(string $configKey, string $id): bool {
		$context = $this->context(configKey: $configKey);
		if ($context === null || $id === '') {
			return false;
		}

		try {
			return ($context['service']->deleteObject(
				uuid: $id,
				register: $context['register'],
				schema: $context['schema'],
			) === true);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq starter: could not delete a row',
				['schema' => $configKey, 'id' => $id, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end delete()

	/**
	 * An id off a row, whichever shape the store answered in.
	 *
	 * @param array<string, mixed>|null $row The row.
	 *
	 * @return string The id, or the empty string.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function idOf(?array $row): string {
		if ($row === null) {
			return '';
		}

		$id = (string)($row['id'] ?? ($row['uuid'] ?? ''));
		if ($id === '' && is_array(($row['@self'] ?? null)) === true) {
			$id = (string)($row['@self']['id'] ?? ($row['@self']['uuid'] ?? ''));
		}

		return $id;
	}//end idOf()

	/**
	 * The object service, register and schema, or null when any is missing.
	 *
	 * @param string $configKey The app config key naming the schema.
	 *
	 * @return array{service: object, register: string, schema: string}|null The context.
	 */
	private function context(string $configKey): ?array {
		$service = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue(key: 'register');
		$schema = $this->settings->getConfigValue(key: $configKey);

		if ($service === null || $register === '' || $schema === '') {
			return null;
		}

		return ['service' => $service, 'register' => $register, 'schema' => $schema];
	}//end context()
	/**
	 * One row by id, past the caller's access check.
	 *
	 * @param array{service: object, register: string, schema: string} $context The store context.
	 * @param string                                                     $id      The row's id.
	 *
	 * @return array<string, mixed>|null The row, or null when it is not there.
	 */
	private function systemFind(array $context, string $id): ?array {
		try {
			$object = $context['service']->find(
				id: $id,
				register: $context['register'],
				schema: $context['schema'],
				_rbac: false,
			);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		}

		return $this->asArray(object: $object);
	}//end systemFind()

	/**
	 * An object the store answered, as an array.
	 *
	 * @param mixed $object An array, or an entity that serialises to one.
	 *
	 * @return array<string, mixed>|null The array, or null.
	 */
	private function asArray(mixed $object): ?array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return null;
	}//end asArray()
}//end class
