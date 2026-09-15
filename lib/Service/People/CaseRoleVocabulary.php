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
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
 */
class CaseRoleVocabulary {

	use SearchesObjects;

	/**
	 * How many role types one read takes.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * OpenRegister's schema mapper, resolved the way every other OpenRegister
	 * class in this app is: through SettingsService, which answers null rather
	 * than throwing when the app is not installed (ADR-083).
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * @param SettingsService $settingsService OpenRegister access and the configured register and schemas.
	 * @param PartyVocabulary $parties The kinds of party a case takes and the roles they may hold.
	 * @param LoggerInterface $logger Says why a sync did nothing.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly PartyVocabulary $parties,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the role types onto the case schema as its link vocabulary.
	 *
	 * @return int How many roles the vocabulary now holds, -1 when it could not be written.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-the-generic-party-roles-req-role-012
	 */
	public function sync(): int {
		try {
			$roles = $this->vocabulary();
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

		$kinds = $this->parties->kinds();
		if (($configuration['linkRoles'] ?? null) === $roles && ($configuration['partyKinds'] ?? null) === $kinds) {
			return count($roles);
		}

		$configuration['linkRoles'] = $roles;
		$configuration['partyKinds'] = $kinds;
		$schema->setConfiguration($configuration);

		return $this->verdictOn(
			stored: $this->configurationOf(stored: $this->schemaMapper()->update($schema)),
			roles: $roles
		);
	}//end sync()

	/**
	 * The configuration a stored schema came back with, [] when it answers none.
	 *
	 * Read back rather than trusted: OpenRegister drops a configuration key its
	 * own vocabulary does not know, in silence, which is what made the
	 * documented `x-contactRoles` a no-op for a year.
	 *
	 * @param mixed $stored Whatever the mapper answered.
	 *
	 * @return array<string, mixed> The configuration as stored.
	 */
	private function configurationOf(mixed $stored): array {
		if (is_object($stored) === false || is_callable([$stored, 'getConfiguration']) === false) {
			return [];
		}

		return (array)call_user_func([$stored, 'getConfiguration']);
	}//end configurationOf()

	/**
	 * What the write actually achieved.
	 *
	 * Losing the link roles is fatal to this sync: people can be linked in no
	 * role at all until that OpenRegister carries the people-on-objects
	 * vocabulary. Losing the party kinds is not: the case then accepts a party
	 * of any kind, which is exactly what it did before they were declared.
	 *
	 * @param array<string, mixed> $stored The configuration as stored.
	 * @param array<int, array<string, string>> $roles The vocabulary that was written.
	 *
	 * @return int How many roles the vocabulary holds, -1 when the roles were dropped.
	 */
	private function verdictOn(array $stored, array $roles): int {
		if ((array)($stored['linkRoles'] ?? []) === [] && $roles !== []) {
			$this->logger->warning(
				'Dossiq people: the case schema did not keep its link roles. OpenRegister drops a '
				. 'configuration key it does not know, so people can be linked in no role until it '
				. 'carries the people-on-objects vocabulary.'
			);
			return -1;
		}

		if ((array)($stored['partyKinds'] ?? []) === []) {
			$this->logger->warning(
				'Dossiq people: the case schema did not keep its party kinds. This OpenRegister does '
				. 'not carry the party model yet, so a party of any kind is accepted and the Roles '
				. 'widget falls back to the role keys.'
			);
		}

		return count($roles);
	}//end verdictOn()

	/**
	 * The whole link vocabulary: this instance's role types first, then the
	 * generic party roles every case type offers.
	 *
	 * The order is what the picker shows, and an organisation's own seats
	 * come before the ones the law names, because the first question on a
	 * case is who is handling it. A role type that already claims a generic
	 * key wins: the generic entry is not listed twice.
	 *
	 * @return array<int, array<string, string>> The entries.
	 *
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-the-generic-party-roles-req-role-012
	 */
	public function vocabulary(): array {
		$entries = $this->roleEntries();
		$taken = array_column($entries, 'key');

		foreach ($this->parties->roles() as $role) {
			if (in_array($role['key'], $taken, true) === false) {
				$entries[] = $role;
			}
		}

		return $entries;
	}//end vocabulary()

	/**
	 * The published role types as vocabulary entries, keyed by uuid.
	 *
	 * @return array<int, array<string, string>> The entries, ordered by label.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
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
		$mapper = $this->settingsService->getOpenRegisterClass(class: self::SCHEMA_MAPPER);
		if ($mapper === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		return $mapper;
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
