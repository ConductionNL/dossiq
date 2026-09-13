<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The instance's role types, written onto the case schema as the roles a
 * person can hold on a case.
 *
 * OpenRegister refuses a link whose role is not in the schema's
 * `configuration.linkRoles`, and the picker offers exactly what is there.
 * Keys are role type uuids, so the projection resolves the role type
 * without guessing and a renamed role type keeps its links.
 *
 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
 */
class CaseRoleVocabulary {

	use SearchesObjects;

	/**
	 * How many role types one read takes.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * @param SettingsService $settingsService OpenRegister access and the configured register and schemas.
	 * @param ContainerInterface $container Resolves OpenRegister's schema mapper.
	 * @param LoggerInterface $logger Says why a sync did nothing.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the role types onto the case schema as its link vocabulary.
	 *
	 * @return int How many roles the vocabulary now holds, -1 when it could not be written.
	 *
	 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
	 */
	public function sync(): int {
		try {
			$roles = $this->roleEntries();
			$schema = $this->caseSchema();
		} catch (Throwable $e) {
			$this->logger->info('Dossiq people: the case role vocabulary was not written: ' . $e->getMessage());
			return -1;
		}

		if ($schema === null) {
			return -1;
		}

		$configuration = $schema->getConfiguration();
		if (is_array($configuration) === false) {
			$configuration = [];
		}

		if (($configuration['linkRoles'] ?? null) === $roles) {
			return count($roles);
		}

		$configuration['linkRoles'] = $roles;
		$schema->setConfiguration($configuration);
		$stored = $this->schemaMapper()->update($schema);

		// Read back rather than trust the write: OpenRegister drops a
		// configuration key its own vocabulary does not know, in silence, which
		// is what made the documented `x-contactRoles` a no-op for a year. An
		// instance whose OpenRegister predates people-on-objects lands here.
		$kept = [];
		if (is_object($stored) === true && is_callable([$stored, 'getConfiguration']) === true) {
			$kept = (array)(call_user_func([$stored, 'getConfiguration'])['linkRoles'] ?? []);
		}

		if ($kept === [] && $roles !== []) {
			$this->logger->warning(
				'Dossiq people: the case schema did not keep its link roles. OpenRegister drops a '
				. 'configuration key it does not know, so people can be linked in no role until it '
				. 'carries the people-on-objects vocabulary.'
			);
			return -1;
		}

		return count($roles);
	}//end sync()

	/**
	 * The published role types as vocabulary entries, keyed by uuid.
	 *
	 * @return array<int, array<string, string>> The entries, ordered by label.
	 *
	 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
	 */
	public function roleEntries(): array {
		[$objectService, $register] = $this->requireRegister();
		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $this->schema(key: 'role_type_schema'),
			filters: ['_limit' => self::PAGE_SIZE],
		);

		$entries = [];
		foreach ($rows as $row) {
			$entry = $this->entryOf(row: $row);
			if ($entry !== []) {
				$entries[$entry['key']] = $entry;
			}
		}

		$entries = array_values($entries);
		usort($entries, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

		return $entries;
	}//end roleEntries()

	/**
	 * One role type as a vocabulary entry.
	 *
	 * @param array<string, mixed> $row The role type row.
	 *
	 * @return array<string, string> The entry, [] when the row has no uuid.
	 */
	private function entryOf(array $row): array {
		$self = (array)($row['@self'] ?? []);
		$key = trim((string)($row['id'] ?? ($self['id'] ?? '')));
		if ($key === '') {
			return [];
		}

		$label = trim((string)($row['name'] ?? ''));
		if ($label === '') {
			$label = $key;
		}

		$entry = ['key' => $key, 'label' => $label];
		$description = trim((string)($row['description'] ?? ''));
		if ($description !== '') {
			$entry['description'] = $description;
		}

		return $entry;
	}//end entryOf()

	/**
	 * The case schema entity, or null when it cannot be read.
	 *
	 * @return object|null The schema.
	 */
	private function caseSchema(): ?object {
		try {
			return $this->schemaMapper()->find($this->schema(key: 'case_schema'));
		} catch (Throwable $e) {
			$this->logger->info('Dossiq people: the case schema could not be read: ' . $e->getMessage());
			return null;
		}
	}//end caseSchema()

	/**
	 * OpenRegister's schema mapper.
	 *
	 * @return object The mapper.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function schemaMapper(): object {
		try {
			return $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister is not available: ' . $e->getMessage(), 0, $e);
		}
	}//end schemaMapper()

	/**
	 * The object service and register, or an exception.
	 *
	 * @return array{0: object, 1: string} The object service and the register.
	 *
	 * @throws RuntimeException When OpenRegister or the register is not configured.
	 */
	private function requireRegister(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('Dossier register not configured');
		}

		return [$objectService, $register];
	}//end requireRegister()

	/**
	 * A configured schema slug or id, or an exception when unset.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The schema.
	 *
	 * @throws RuntimeException When the key is not configured.
	 */
	private function schema(string $key): string {
		$schema = $this->settingsService->getConfigValue($key);
		if ($schema === '') {
			throw new RuntimeException('Dossiq schema ' . $key . ' not configured');
		}

		return $schema;
	}//end schema()
}//end class
