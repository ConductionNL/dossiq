<?php

/**
 * Dossiq StoredDecisionTables.
 *
 * Dossiq's door to OpenRegister's decision-tables register (openregister#4572):
 * names a table by a stored key, evaluates it through OpenRegister's
 * DecisionTableStore, and writes the tables dossiq owns.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Dmn
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
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Dmn;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads, evaluates and writes dossiq's tables in OpenRegister's register.
 *
 * The store is resolved lazily by class name: constructor-injecting a class
 * from another app makes every consumer fatal while that app is absent or
 * mid-upgrade. Absent means null, never an empty decision.
 *
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
 */
class StoredDecisionTables {
	use SearchesObjects;

	/**
	 * OpenRegister's store of named decision tables.
	 *
	 * @var string
	 */
	public const STORE = '\\OCA\\OpenRegister\\Service\\Dmn\\DecisionTableStore';

	/**
	 * The register and schema the tables live in (OpenRegister's, frozen there).
	 *
	 * @var string
	 */
	public const REGISTER = 'decision-tables';
	public const SCHEMA = 'decision-table';

	/**
	 * Every table dossiq ships or moved carries this key prefix and owner.
	 *
	 * @var string
	 */
	public const KEY_PREFIX = 'dossiq.';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Reaches OpenRegister's object service.
	 * @param ContainerInterface $container Resolves OpenRegister's store at call time.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The stored key of a table dossiq knew by another key.
	 *
	 * OpenRegister keys read `^[a-z0-9][a-z0-9._-]*$`; dossiq's keys were free
	 * text. Lower case, every other character a hyphen, and the dossiq prefix,
	 * so `LHS Matrix 2` is stored as `dossiq.lhs-matrix-2`. A key that already
	 * carries the prefix is returned as is.
	 *
	 * @param string $key The table's dossiq key.
	 *
	 * @return string The stored key, or empty for an empty key.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
	 */
	public function keyFor(string $key): string {
		$key = strtolower(trim($key));
		if ($key === '') {
			return '';
		}

		if (str_starts_with($key, self::KEY_PREFIX) === true) {
			return $key;
		}

		$slug = trim((string)preg_replace('/[^a-z0-9._-]+/', '-', $key), '-._');
		if ($slug === '') {
			return '';
		}

		return self::KEY_PREFIX . $slug;
	}//end keyFor()

	/**
	 * Evaluate a stored table.
	 *
	 * @param string $reference The stored key.
	 * @param array<string, mixed> $inputs Input values keyed by input name.
	 *
	 * @return array<string, mixed>|null The answer (outputs, matchedRuleIds, hitPolicy, tableKey), or null when OpenRegister's store is absent.
	 *
	 * @throws Throwable When no table carries the reference, or the evaluation fails, including when no rule matches.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
	 */
	public function evaluate(string $reference, array $inputs): ?array {
		$store = $this->store();
		if ($store === null) {
			return null;
		}

		$answer = $store->evaluate($reference, $inputs);
		if (is_array($answer) === false) {
			throw new RuntimeException('OpenRegister answered the decision table "' . $reference . '" without a result.');
		}

		return $answer;
	}//end evaluate()

	/**
	 * Read a stored table.
	 *
	 * @param string $reference The stored key.
	 *
	 * @return array<string, mixed>|null The table, or null when OpenRegister's store is absent.
	 *
	 * @throws Throwable When no table carries the reference, or it cannot be executed.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
	 */
	public function find(string $reference): ?array {
		$store = $this->store();
		if ($store === null) {
			return null;
		}

		$table = $store->find($reference);
		if (is_array($table) === false) {
			throw new RuntimeException('OpenRegister answered the decision table "' . $reference . '" without a table.');
		}

		return $table;
	}//end find()

	/**
	 * The id of the stored row with this key, or null.
	 *
	 * @param string $storedKey The stored key.
	 *
	 * @return string|null The row's id.
	 *
	 * @throws RuntimeException When the register cannot be read (OpenRegister absent, or the register not imported yet).
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
	 */
	public function idOf(string $storedKey): ?string {
		try {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $this->objectService(),
				register: self::REGISTER,
				schema: self::SCHEMA,
				filters: ['key' => $storedKey, '_limit' => 50],
			);
		} catch (Throwable $e) {
			throw new RuntimeException('The decision-table register could not be read: ' . $e->getMessage(), 0, $e);
		}

		foreach ($rows as $row) {
			if (trim((string)($row['key'] ?? '')) === $storedKey) {
				return (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
			}
		}

		return null;
	}//end idOf()

	/**
	 * Write a table dossiq owns, by its stored key: create, or replace the row with that key.
	 *
	 * @param array<string, mixed> $table The table: key (stored), name, description, hitPolicy, inputs, outputs, rules.
	 *
	 * @return array<string, mixed> The row as written.
	 *
	 * @throws RuntimeException When OpenRegister refuses the table.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
	 */
	public function save(array $table): array {
		$row = [
			'key' => $this->keyFor(key: (string)($table['key'] ?? '')),
			'name' => (string)($table['name'] ?? ''),
			'description' => (string)($table['description'] ?? ''),
			'hitPolicy' => strtoupper((string)($table['hitPolicy'] ?? 'UNIQUE')),
			'inputs' => array_values((array)($table['inputs'] ?? [])),
			'outputs' => array_values((array)($table['outputs'] ?? [])),
			'rules' => array_values((array)($table['rules'] ?? [])),
			'owner' => Application::APP_ID,
		];
		if ($row['key'] === '' || $row['name'] === '') {
			throw new RuntimeException('A decision table needs a key and a name.');
		}

		try {
			$this->saveObjectAsArray(
				objectService: $this->objectService(),
				register: self::REGISTER,
				schema: self::SCHEMA,
				object: $row,
				uuid: $this->idOf(storedKey: $row['key'])
			);
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister refused the decision table "' . $row['key'] . '": ' . $e->getMessage(), 0, $e);
		}

		return $row;
	}//end save()

	/**
	 * OpenRegister's object service.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function objectService(): object {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available.');
		}

		return $objectService;
	}//end objectService()

	/**
	 * OpenRegister's store, or null when it is not there.
	 *
	 * @return object|null The store.
	 */
	private function store(): ?object {
		if (class_exists(self::STORE) === false) {
			return null;
		}

		try {
			$store = $this->container->get(self::STORE);
		} catch (Throwable $e) {
			// OpenRegister mid-upgrade: the same answer as absent.
			$store = null;
		}

		if (is_object($store) === true && method_exists($store, 'evaluate') === true && method_exists($store, 'find') === true) {
			return $store;
		}

		return null;
	}//end store()
}//end class
