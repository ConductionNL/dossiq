<?php

/**
 * Dossiq case definition package writer.
 *
 * Writes an imported case definition package into OpenRegister: the rows of
 * each collection, the workflow templates the archive carries, and the
 * roll-back that undoes a half-written component.
 *
 * It was split out of {@see \OCA\Dossiq\Service\CaseDefinitionImportService},
 * which had grown past a thousand lines doing two separable jobs: reading and
 * validating a package, and writing one. This half is the one that touches the
 * register, and it is the one whose failure has to leave nothing behind.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @spec openspec/changes/case-definition-export-is-real/specs/case-types/spec.md#requirement-an-import-writes-the-objects-or-says-it-did-not-req-ct-42
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Writes an imported case definition package into OpenRegister.
 *
 * @spec openspec/changes/case-definition-export-is-real/specs/case-types/spec.md#requirement-an-import-writes-the-objects-or-says-it-did-not-req-ct-42
 */
class PackageWriter {

	use WritesPackageRows;
	/**
	 * The strategy that leaves an object this instance already has alone.
	 *
	 * @var string
	 */
	private const STRATEGY_SKIP = 'skip';

	/**
	 * The settings key naming the schema each exported collection is written to.
	 *
	 * The keys are the collection names the export writes, so the two services
	 * agree by construction: a collection the export invents and the import does
	 * not know is REFUSED by name rather than dropped, which is the whole
	 * difference between an import and a count.
	 *
	 * @var array<string, string>
	 */
	private const COLLECTION_SCHEMAS = [
		'caseType' => 'case_type_schema',
		'propertyDefinitions' => 'property_definition_schema',
		'statusTypes' => 'status_type_schema',
		'roleTypes' => 'role_type_schema',
		'documentTypes' => 'document_type_schema',
		'resultTypes' => 'result_type_schema',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger   What an import writes about itself.
	 * @param SettingsService $settings The register and schema names.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * Write the collections of one component, or none of them.
	 *
	 * @param string $component The component name, for the messages.
	 * @param array<string, mixed> $data The decoded component file.
	 * @param array<int, string> $collections The collections to write, in order.
	 * @param string $strategy The conflict resolution strategy.
	 *
	 * @return array{status: string, message: string, created?: array<int, string>, replaced?: array<int, string>}
	 *
	 * @spec openspec/changes/case-definition-export-is-real/specs/case-types/spec.md#requirement-an-import-writes-the-objects-or-says-it-did-not-req-ct-42
	 */
	public function writeCollections(string $component, array $data, array $collections, string $strategy): array {
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue(key: 'register');
		if ($objectService === null || $register === '') {
			return [
				'status' => 'error',
				'message' => "Component '{$component}' was not written: this instance has no OpenRegister register configured.",
			];
		}

		$created = [];

		try {
			$written = $this->writeRows(
				objectService: $objectService,
				register: $register,
				data: $data,
				collections: $collections,
				strategy: $strategy,
				created: $created,
			);
		} catch (\Throwable $e) {
			// The component is all or nothing: what this run wrote for it goes
			// back out, so a failed import leaves no half case type behind.
			$this->rollBack(objectService: $objectService, register: $register, ids: $created);

			return [
				'status' => 'error',
				'message' => "Component '{$component}' was not imported: " . $e->getMessage(),
			];
		}//end try

		return $this->importOutcome(
			component: $component,
			strategy: $strategy,
			created: $created,
			replaced: $written['replaced'],
			skipped: $written['skipped'],
		);
	}//end writeCollections()

	/**
	 * Write every row of every collection, and say what became of each.
	 *
	 * `$created` is taken BY REFERENCE because the caller needs it after a
	 * throw: a component is all or nothing, and the rollback can only undo the
	 * rows this run had already written when it failed.
	 *
	 * @param object               $objectService The OpenRegister object service.
	 * @param string               $register      The register being written into.
	 * @param array<string, mixed> $data          The package.
	 * @param array<int, string>   $collections   The collections to write.
	 * @param string               $strategy      What to do about a row already here.
	 * @param array<int, string>   $created       Collects the ids this run created.
	 *
	 * @return array{replaced: array<int, string>, skipped: array<int, string>} What was replaced and left alone.
	 *
	 * @throws RuntimeException When a collection has no schema configured for it.
	 */
	private function writeRows(
		object $objectService,
		string $register,
		array $data,
		array $collections,
		string $strategy,
		array &$created,
	): array {
		$replaced = [];
		$skipped = [];

		foreach ($collections as $collection) {
			$schema = $this->settings->getConfigValue(key: self::COLLECTION_SCHEMAS[$collection]);
			if ($schema === '') {
				throw new RuntimeException(
					"No schema is configured for '{$collection}', so its rows cannot be written."
				);
			}

			foreach ($this->rowsOf(data: $data, collection: $collection) as $row) {
				$outcome = $this->writeRow(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					row: $row,
					strategy: $strategy,
				);

				if ($outcome['kind'] === 'skipped') {
					$skipped[] = $outcome['id'];
					continue;
				}

				if ($outcome['kind'] === 'replaced') {
					$replaced[] = $outcome['id'];
					continue;
				}

				$created[] = $outcome['id'];
			}
		}//end foreach

		return ['replaced' => $replaced, 'skipped' => $skipped];
	}//end writeRows()

	/**
	 * Write one row, and say whether it was created, replaced or left alone.
	 *
	 * 🔑 THE PACKAGE'S ID IS KEPT. Every child row carries a `caseType`
	 * back-reference by id, so minting a new id for the case type would leave
	 * every status, role and document type pointing at nothing. A CONFLICT is
	 * this instance already holding that id, which is a different question from
	 * the package carrying one.
	 *
	 * @param object               $objectService The OpenRegister object service.
	 * @param string               $register      The register being written into.
	 * @param string               $schema        The schema this row belongs to.
	 * @param array<string, mixed> $row           The row as the package carries it.
	 * @param string               $strategy      What to do about a row already here.
	 *
	 * @return array{kind: string, id: string} What became of it, and the id.
	 */
	private function writeRow(
		object $objectService,
		string $register,
		string $schema,
		array $row,
		string $strategy,
	): array {
		$packageId = $this->existingId(row: $row);
		$conflict = ($packageId !== '' && $this->holds(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $packageId
		) === true);

		if ($conflict === true && $strategy === self::STRATEGY_SKIP) {
			return ['kind' => 'skipped', 'id' => $packageId];
		}

		$rowUuid = null;
		if ($packageId !== '') {
			$rowUuid = $packageId;
		}

		$stored = $objectService->saveObject(
			$this->withoutMetadata(row: $row),
			register: $register,
			schema: $schema,
			uuid: $rowUuid
		);

		$id = $this->storedId(stored: $stored);
		if ($conflict === true) {
			return ['kind' => 'replaced', 'id' => $id];
		}

		return ['kind' => 'created', 'id' => $id];
	}//end writeRow()

	/**
	 * What one component's import amounts to, said in the caller's vocabulary.
	 *
	 * @param string             $component The component.
	 * @param string             $strategy  What was to be done about rows already here.
	 * @param array<int, string> $created   The ids created.
	 * @param array<int, string> $replaced  The ids replaced.
	 * @param array<int, string> $skipped   The ids left alone.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function importOutcome(
		string $component,
		string $strategy,
		array $created,
		array $replaced,
		array $skipped,
	): array {
		if ($created === [] && $replaced === [] && $skipped === []) {
			// 🔴 NOTHING WRITTEN IS NOT A SUCCESS. This is the exact line the old
			// code got wrong, and the one REQ-CT-42 is about.
			return [
				'status' => 'error',
				'message' => "Component '{$component}' held no rows this import could write.",
			];
		}

		if ($created === [] && $replaced === []) {
			// Everything was already here and the caller asked to leave it alone.
			// That is not a write and must not read as one.
			return [
				'status' => 'skipped',
				'message' => "Component '{$component}': " . count($skipped) . ' row(s) already present, left alone.',
				'created' => [],
				'replaced' => [],
			];
		}

		$this->logger->info(
			'Imported component {component} with strategy {strategy}: {created} created, {replaced} replaced',
			[
				'component' => $component,
				'strategy' => $strategy,
				'created' => count($created),
				'replaced' => count($replaced),
			]
		);

		return [
			'status' => 'success',
			'message' => "Component '{$component}': " . count($created) . ' created, ' . count($replaced) . ' replaced',
			'created' => $created,
			'replaced' => $replaced,
		];
	}//end importOutcome()





	/**
	 * The rows of one collection, whichever shape the package carries.
	 *
	 * `caseType` is one object rather than a list, so it is wrapped; every
	 * other collection is a list.
	 *
	 * @param array<string, mixed> $data The decoded component file.
	 * @param string $collection The collection name.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsOf(array $data, string $collection): array {
		$value = ($data[$collection] ?? null);
		if (is_array($value) === false || $value === []) {
			return [];
		}

		if (array_is_list($value) === false) {
			return [$value];
		}

		return array_values(array_filter($value, 'is_array'));
	}//end rowsOf()





}//end class
