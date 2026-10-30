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
 * @spec openspec/specs/case-types/spec.md#requirement-an-import-writes-the-objects-or-says-it-did-not-req-ct-42
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
 * @spec openspec/specs/case-types/spec.md#requirement-an-import-writes-the-objects-or-says-it-did-not-req-ct-42
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
	 * @param LoggerInterface    $logger   What an import writes about itself.
	 * @param SettingsService    $settings The register and schema names.
	 * @param ExistingRowMatcher $matcher  Finds a row a seeder already wrote under another id.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly SettingsService $settings,
		private readonly ExistingRowMatcher $matcher,
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
	 * @spec openspec/specs/case-types/spec.md#requirement-an-import-writes-the-objects-or-says-it-did-not-req-ct-42
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
	 * Write a whole package's rows, or none of them.
	 *
	 * A group is the rows bound for one schema, named by the settings key that
	 * resolves it (`case_type_schema`, `workflow_template_schema`, or whatever a
	 * seed file names). Every group goes in one run: when any row fails, every
	 * row this run created goes back out, across all groups, so a half-installed
	 * package never shows up as a case type with no statuses.
	 *
	 * A row the instance already holds is left alone and reported as skipped.
	 * That is what makes a second install of the same package a no-op, and it
	 * is why nothing here replaces: a replaced row cannot be rolled back. Each
	 * such row is also reported as held, with the row the package ships, so the
	 * installer can refresh it through the ledger once the write has succeeded.
	 *
	 * A row the instance holds under another id, because a seeder wrote it
	 * before the package existed, is adopted: nothing is written, the existing
	 * row is reported, and every later row of the package that names the
	 * package's id names the existing one instead (decision 190). An adopted
	 * row is never taken back, since this run did not create it. An adopted
	 * case type is reported with the fields it lacks (`fill`, decision 273),
	 * which {@see self::fillAdopted()} writes once the install has succeeded.
	 *
	 * @param string                                                          $package The package id, for the messages.
	 * @param array<int, array<string, mixed>> $groups  The rows, per schema key; a group may name its own
	 *                                                  `register` and `schema`, and the property it matches on.
	 *
	 * @return array{
	 *     status: string,
	 *     message: string,
	 *     written: array<int, array<string, mixed>>,
	 *     skipped: array<int, string>,
	 *     adopted: array<int, array<string, mixed>>,
	 *     held: array<int, array<string, mixed>>
	 * }
	 *         Each written, adopted and held entry carries schemaKey, schema, id and row;
	 *         an adopted entry's row is the one this instance already held, a held
	 *         entry's row is the one the package ships, and a held entry says whether
	 *         its group names a register of its own (`ownRegister`).
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
	 * @spec openspec/changes/package-adoption-fills-missing-case-type-fields/specs/case-types/spec.md#requirement-an-adopted-case-type-gets-the-fields-it-lacks-and-keeps-the-ones-it-has-req-ct-adopt-01
	 */
	public function writePackage(string $package, array $groups): array {
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue(key: 'register');
		if ($objectService === null || $register === '') {
			return [
				'status' => 'error',
				'message' => "Package '{$package}' was not installed: this instance has no OpenRegister register configured.",
				'written' => [],
				'skipped' => [],
				'adopted' => [],
				'held' => [],
			];
		}

		$run = ['written' => [], 'skipped' => [], 'adopted' => [], 'held' => [], 'ids' => [], 'caseTypes' => []];

		try {
			foreach ($groups as $group) {
				$this->writeGroup(objectService: $objectService, register: $register, group: $group, run: $run);
			}
		} catch (\Throwable $e) {
			$this->undo(written: $run['written']);

			return [
				'status' => 'error',
				'message' => "Package '{$package}' was not installed, and nothing it wrote was kept: " . $e->getMessage(),
				'written' => [],
				'skipped' => [],
				'adopted' => [],
				'held' => [],
			];
		}

		$run['adopted'] = $this->fills()->withFillsPointedAt(adopted: $run['adopted'], ids: $run['ids']);

		return [
			'status' => 'success',
			'message' => "Package '{$package}': " . count($run['written']) . ' row(s) written, '
				. count($run['adopted']) . ' adopted, ' . count($run['skipped']) . ' already present.',
			'written' => $run['written'],
			'skipped' => $run['skipped'],
			'adopted' => $run['adopted'],
			'held' => $run['held'],
		];
	}//end writePackage()

	/**
	 * Take back rows a package write created.
	 *
	 * Public because the write is not the last step of an install: the
	 * installer records every row in the shipped-set ledger afterwards, and a
	 * row it cannot record is a row it has to take back.
	 *
	 * @param array<int, array<string, mixed>> $written The rows {@see self::writePackage()} reported written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function undo(array $written): void {
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue(key: 'register');
		if ($objectService === null) {
			return;
		}

		// A seed row may sit in another app's register; each row goes back out of its own.
		foreach ($written as $row) {
			$this->rollBack(objectService: $objectService, register: (string)($row['register'] ?? $register), ids: [(string)$row['id']]);
		}
	}//end undo()

	/**
	 * Write the rows of one group, collecting what became of each.
	 *
	 * @param object                                                        $objectService The OpenRegister object service.
	 * @param string                                                        $register      The register.
	 * @param array<string, mixed>                                          $group         The group.
	 * @param array<string, array<mixed>>                                   $run           Collects written, skipped
	 *                                                                                     and adopted rows, the ids
	 *                                                                                     adopted and the case types
	 *                                                                                     among them.
	 *
	 * @return void
	 *
	 * A group that names its own `register` and `schema` goes there: that is a
	 * seed file for another app's schema, resolved by the installer. A group
	 * that names `match` adopts a row with the same value for every property it names.
	 *
	 * @throws RuntimeException When the group's schema key resolves to nothing.
	 */
	private function writeGroup(object $objectService, string $register, array $group, array &$run): void {
		$register = (string)($group['register'] ?? $register);
		$schema = (string)($group['schema'] ?? $this->settings->getConfigValue(key: $group['schemaKey']));
		if ($schema === '') {
			throw new RuntimeException("No schema is configured for '{$group['schemaKey']}', so its rows cannot be written.");
		}

		foreach ($group['rows'] as $row) {
			$row = $this->pointedAtAdopted(row: $row, ids: $run['ids']);
			$packageId = $this->existingId(row: $row);
			if ($packageId !== '' && $this->holds(objectService: $objectService, register: $register, schema: $schema, id: $packageId) === true) {
				$run['skipped'][] = $packageId;
				$run['held'][] = [
					'schemaKey' => $group['schemaKey'],
					'schema' => $schema,
					'id' => $packageId,
					'row' => $this->withoutMetadata(row: $row),
					'ownRegister' => isset($group['register']),
				];
				continue;
			}

			$existing = $this->matcher->find(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				schemaKey: $group['schemaKey'],
				row: $row,
				caseTypes: $run['caseTypes'],
				match: (array)($group['match'] ?? []),
			);
			if ($existing !== null && $this->existingId(row: $existing) !== '') {
				$this->adopt(
					group: $group,
					target: ['register' => $register, 'schema' => $schema],
					row: $row,
					existing: $existing,
					run: $run
				);
				continue;
			}

			$outcome = $this->writeRow(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				row: $row,
				strategy: self::STRATEGY_SKIP,
			);

			$run['written'][] = ['schemaKey' => $group['schemaKey'], 'schema' => $schema, 'register' => $register, 'id' => $outcome['id'], 'row' => $row];
		}//end foreach
	}//end writeGroup()

	/**
	 * Take an existing row as the package's, and point later rows at it.
	 *
	 * @param array{schemaKey: string}               $group    The group the row belongs to.
	 * @param array{register: string, schema: string} $target  Where the row lives.
	 * @param array<string, mixed>                   $row      The row as the package ships it.
	 * @param array<string, mixed>                   $existing The row this instance holds.
	 * @param array<string, array<mixed>>            $run      The run so far.
	 *
	 * @return void
	 */
	private function adopt(array $group, array $target, array $row, array $existing, array &$run): void {
		$existingId = $this->existingId(row: $existing);
		$packageId = $this->existingId(row: $row);
		if ($packageId !== '') {
			$run['ids'][$packageId] = $existingId;
		}

		$fill = [];
		if ($group['schemaKey'] === ExistingRowMatcher::CASE_TYPE_KEY) {
			$run['caseTypes'][] = $existingId;
			$fill = $this->fills()->lackedBy(shipped: $this->withoutMetadata(row: $row), stored: $existing);
		}

		$run['adopted'][] = [
			'schemaKey' => $group['schemaKey'],
			'schema' => $target['schema'],
			'register' => $target['register'],
			'id' => $existingId,
			'row' => $existing,
			'fill' => $fill,
		];
	}//end adopt()

	/**
	 * Give each adopted case type the fields it lacked, never touching one it has.
	 *
	 * Called by the installer after the install has been recorded, so a failed
	 * install leaves an adopted row exactly as it was.
	 *
	 * @param array<int, array<string, mixed>> $adopted The adopted rows {@see self::writePackage()} reported.
	 *
	 * @return array<int, string> The ids of the rows that were filled.
	 *
	 * @spec openspec/changes/package-adoption-fills-missing-case-type-fields/specs/case-types/spec.md#requirement-an-adopted-case-type-gets-the-fields-it-lacks-and-keeps-the-ones-it-has-req-ct-adopt-01
	 */
	public function fillAdopted(array $adopted): array {
		return $this->fills()->write(adopted: $adopted);
	}//end fillAdopted()

	/**
	 * The fill collaborator.
	 *
	 * @return AdoptedCaseTypeFill
	 */
	private function fills(): AdoptedCaseTypeFill {
		return new AdoptedCaseTypeFill(settings: $this->settings, logger: $this->logger);
	}//end fills()

	/**
	 * The row with every package id that was adopted replaced by the id it has here.
	 *
	 * References sit in plain fields (`caseType`) and inside JSON strings
	 * (a flow's `steps` and `transitions`), so every string value is rewritten,
	 * at any depth. A package id is a UUID, so it cannot occur by accident
	 * inside other text. The row's own id is left alone.
	 *
	 * @param array<string, mixed>  $row The row.
	 * @param array<string, string> $ids Package id to existing id.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function pointedAtAdopted(array $row, array $ids): array {
		return $this->fills()->pointedAt(row: $row, ids: $ids);
	}//end pointedAtAdopted()

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

		// An administrator's install; see holds() for why it passes the access check.
		$stored = $objectService->saveObject(
			$this->withoutMetadata(row: $row),
			register: $register,
			schema: $schema,
			uuid: $rowUuid,
			_rbac: false
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
