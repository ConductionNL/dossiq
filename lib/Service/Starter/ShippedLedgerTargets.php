<?php

/**
 * The schema values a shipped-set ledger row may carry.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Starter
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git-id>
 * @link https://conduction.nl
 * @spec openspec/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

use OCA\Dossiq\Service\Settings\SchemaSlugMap;

/**
 * A ledger row names its schema by slug (caseType); rows written before that
 * name it by the configured schema id (165). This maps an id to its slug for
 * writing, and a slug to both values for reading.
 *
 * @spec openspec/specs/case-type-seed-data/spec.md
 */
class ShippedLedgerTargets {

	/**
	 * Schema ids already mapped back to their slug, per id.
	 *
	 * @var array<string, string>
	 */
	private array $slugCache = [];

	/**
	 * Constructor.
	 *
	 * @param StarterStore $store The store, which knows the configured schema ids.
	 */
	public function __construct(
		private readonly StarterStore $store,
	) {
	}//end __construct()

	/**
	 * The slug a ledger row records for a schema.
	 *
	 * The package installer hands over the schema's id (165), the screen and
	 * the role seed its slug (caseType). A ledger keyed both ways answered
	 * "nothing shipped" for every installed package on the shipped
	 * configuration screen, so a configured schema id is recorded as its slug.
	 *
	 * @param string $targetSchema A schema slug or a configured schema id.
	 *
	 * @return string The slug, or the value as given when no slug names it.
	 *
	 * @spec openspec/specs/case-type-seed-data/spec.md
	 */
	public function slugFor(string $targetSchema): string {
		if ($targetSchema === '' || isset(SchemaSlugMap::SLUG_TO_CONFIG_KEY[$targetSchema]) === true) {
			return $targetSchema;
		}

		if (isset($this->slugCache[$targetSchema]) === false) {
			$this->slugCache[$targetSchema] = $targetSchema;
			foreach (SchemaSlugMap::SLUG_TO_CONFIG_KEY as $slug => $configKey) {
				if ($this->store->schemaIdOf(configKey: $configKey) === $targetSchema) {
					$this->slugCache[$targetSchema] = $slug;
					break;
				}
			}
		}

		return $this->slugCache[$targetSchema];
	}//end slugFor()

	/**
	 * The values a ledger row may carry for a schema: its slug, and the
	 * configured schema id rows were recorded under before slugs were.
	 *
	 * @param string $targetSchema The schema slug.
	 *
	 * @return array<int, string> The values to look under.
	 *
	 * @spec openspec/specs/case-type-seed-data/spec.md
	 */
	public function valuesFor(string $targetSchema): array {
		$targetSchema = $this->slugFor(targetSchema: $targetSchema);
		$targets = [$targetSchema];
		$configKey = (SchemaSlugMap::SLUG_TO_CONFIG_KEY[$targetSchema] ?? '');
		if ($configKey !== '') {
			$schemaId = $this->store->schemaIdOf(configKey: $configKey);
			if ($schemaId !== '' && $schemaId !== $targetSchema) {
				$targets[] = $schemaId;
			}
		}

		return $targets;
	}//end valuesFor()

	/**
	 * Every ledger row of a schema, under its slug or its legacy id, once per object.
	 *
	 * @param string $ledgerKey    The app config key naming the ledger schema.
	 * @param string $targetSchema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null when the store is unreachable.
	 *
	 * @spec openspec/specs/case-type-seed-data/spec.md
	 */
	public function rows(string $ledgerKey, string $targetSchema): ?array {
		$ledger = [];
		foreach ($this->valuesFor(targetSchema: $targetSchema) as $target) {
			$rows = $this->store->rows(configKey: $ledgerKey, filters: ['targetSchema' => $target]);
			if ($rows === null) {
				return null;
			}

			foreach ($rows as $row) {
				$ledger[(string)($row['targetObject'] ?? '')] ??= $row;
			}
		}

		return array_values($ledger);
	}//end rows()

	/**
	 * The ledger row of one object, under its schema's slug or legacy id.
	 *
	 * @param string $ledgerKey    The app config key naming the ledger schema.
	 * @param string $targetSchema The schema slug or id.
	 * @param string $targetObject The object's id.
	 *
	 * @return array<string, mixed>|null The row, or null when nothing shipped it.
	 *
	 * @spec openspec/specs/case-type-seed-data/spec.md
	 */
	public function row(string $ledgerKey, string $targetSchema, string $targetObject): ?array {
		foreach ($this->valuesFor(targetSchema: $targetSchema) as $target) {
			$rows = $this->store->rows(
				configKey: $ledgerKey,
				filters: ['targetSchema' => $target, 'targetObject' => $targetObject, '_limit' => 1],
			);
			if ($rows !== null && $rows !== []) {
				return $rows[0];
			}
		}

		return null;
	}//end row()
}//end class
