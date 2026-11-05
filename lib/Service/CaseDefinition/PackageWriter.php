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
		$target = $this->target(strategy: $strategy);
		if ($target === null) {
			return [
				'status' => 'error',
				'message' => "Component '{$component}' was not written: this instance has no OpenRegister register configured.",
			];
		}

		$run = ['component' => $component, 'strategy' => $strategy, 'created' => [], 'replaced' => [], 'skipped' => []];

		try {
			$rows = new ComponentRows(settings: $this->settings);
			foreach ($rows->schemaRows(data: $data, collections: $collections) as [$schema, $row]) {
				$outcome = $this->writeRow(target: $target->inSchema(schema: $schema), row: $row);
				$run[$outcome['kind']][] = $outcome['id'];
			}
		} catch (\Throwable $e) {
			// The component is all or nothing: what this run wrote for it goes
			// back out, so a failed import leaves no half case type behind.
			$this->rollBack(objectService: $target->objectService, register: $target->register, ids: $run['created']);

			return [
				'status' => 'error',
				'message' => "Component '{$component}' was not imported: " . $e->getMessage(),
			];
		}//end try

		return $this->importOutcome(run: $run);
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
	 * @spec openspec/changes/admin-tabs-carry-the-settings-board-blocks/specs/case-types/spec.md#requirement-a-case-type-a-package-placed-reads-as-shipped
	 */
	public function writePackage(string $package, array $groups): array {
		$target = $this->target(strategy: self::STRATEGY_SKIP);
		if ($target === null) {
			return $this->packageFailure(message: "Package '{$package}' was not installed: this instance has no OpenRegister register configured.");
		}

		$run = [
			'target' => $target,
			'package' => $package,
			'written' => [],
			'skipped' => [],
			'adopted' => [],
			'held' => [],
			'ids' => [],
			'caseTypes' => [],
		];

		try {
			foreach ($groups as $group) {
				$this->writeGroup(group: $group, run: $run);
			}
		} catch (\Throwable $e) {
			$this->undo(written: $run['written']);

			return $this->packageFailure(message: "Package '{$package}' was not installed, and nothing it wrote was kept: " . $e->getMessage());
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
	 * A package install that wrote nothing it kept.
	 *
	 * @param string $message What went wrong.
	 *
	 * @return array{status: string, message: string, written: array<never>, skipped: array<never>, adopted: array<never>, held: array<never>}
	 */
	private function packageFailure(string $message): array {
		return [
			'status' => 'error',
			'message' => $message,
			'written' => [],
			'skipped' => [],
			'adopted' => [],
			'held' => [],
		];
	}//end packageFailure()

	/**
	 * The configured register to write into, or null when this instance has none.
	 *
	 * @param string $strategy What to do about a row already here.
	 *
	 * @return RowTarget|null The target, with no schema chosen yet.
	 */
	private function target(string $strategy): ?RowTarget {
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue(key: 'register');
		if ($objectService === null || $register === '') {
			return null;
		}

		return new RowTarget(objectService: $objectService, register: $register, schema: '', strategy: $strategy);
	}//end target()

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
	 * A group that names its own `register` and `schema` goes there: that is a
	 * seed file for another app's schema, resolved by the installer. A group
	 * that names `match` adopts a row with the same value for every property it names.
	 *
	 * @param array<string, mixed>        $group The group.
	 * @param array<string, mixed>        $run   The run: its target, and the written, skipped,
	 *                                           held and adopted rows, the ids adopted and the
	 *                                           case types among them.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the group's schema key resolves to nothing.
	 */
	private function writeGroup(array $group, array &$run): void {
		$target = $this->groupTarget(base: $run['target'], group: $group);
		foreach ($group['rows'] as $row) {
			$stamp = [];
			if (($group['schemaKey'] ?? '') === ExistingRowMatcher::CASE_TYPE_KEY && ($group['schema'] ?? null) === null) {
				$stamp = ['shipped' => true, 'package' => (string)($run['package'] ?? '')];
			}

			$placement = ['group' => $group, 'target' => $target, 'row' => $this->pointedAtAdopted(row: $row, ids: $run['ids']), 'stamp' => $stamp];
			$this->placeRow(placement: $placement, run: $run);
		}
	}//end writeGroup()

	/**
	 * Where a group's rows go: its own register and schema, or the configured ones.
	 *
	 * @param RowTarget            $base  The configured target.
	 * @param array<string, mixed> $group The group.
	 *
	 * @return RowTarget The group's target.
	 *
	 * @throws RuntimeException When the group's schema key resolves to nothing.
	 */
	private function groupTarget(RowTarget $base, array $group): RowTarget {
		$register = (string)($group['register'] ?? $base->register);
		$schema = (string)($group['schema'] ?? $this->settings->getConfigValue(key: $group['schemaKey']));
		if ($schema === '') {
			throw new RuntimeException("No schema is configured for '{$group['schemaKey']}', so its rows cannot be written.");
		}

		return $base->inRegister(register: $register, schema: $schema);
	}//end groupTarget()

	/**
	 * Hold, adopt or write one row of a group.
	 *
	 * @param array{group: array<string, mixed>, target: RowTarget, row: array<string, mixed>, stamp: array<string, mixed>} $placement The row,
	 *        where it goes and what a row the package places carries besides.
	 * @param array<string, mixed> $run The run so far.
	 *
	 * @return void
	 */
	private function placeRow(array $placement, array &$run): void {
		// A case type the package itself places is shipped; one it adopts stays the organisation's own.
		$shipped = ['row' => $placement['row'] + $placement['stamp']] + $placement;
		if ($this->holdsRow(target: $placement['target'], row: $placement['row']) === true) {
			$this->hold(placement: $shipped, run: $run);
			return;
		}

		$existing = $this->matched(placement: $placement, caseTypes: $run['caseTypes']);
		if ($existing !== null && $this->existingId(row: $existing) !== '') {
			$this->adopt(placement: $placement + ['existing' => $existing], run: $run);
			return;
		}

		$placement = $shipped;

		$target = $placement['target'];
		$outcome = $this->writeRow(target: $target, row: $placement['row']);

		$run['written'][] = [
			'schemaKey' => $placement['group']['schemaKey'],
			'schema' => $target->schema,
			'register' => $target->register,
			'id' => $outcome['id'],
			'row' => $placement['row'],
		];
	}//end placeRow()

	/**
	 * Report a row this instance already holds as skipped, and hold it for the refresh.
	 *
	 * @param array{group: array<string, mixed>, target: RowTarget, row: array<string, mixed>} $placement The row and where it goes.
	 * @param array<string, mixed>                                                             $run       The run so far.
	 *
	 * @return void
	 */
	private function hold(array $placement, array &$run): void {
		$packageId = $this->existingId(row: $placement['row']);
		$run['skipped'][] = $packageId;
		$run['held'][] = [
			'schemaKey' => $placement['group']['schemaKey'],
			'schema' => $placement['target']->schema,
			'id' => $packageId,
			'row' => $this->withoutMetadata(row: $placement['row']),
			'ownRegister' => isset($placement['group']['register']),
		];
	}//end hold()

	/**
	 * The row this instance holds under another id for a package row, or null.
	 *
	 * @param array{group: array<string, mixed>, target: RowTarget, row: array<string, mixed>} $placement The row and where it goes.
	 * @param array<int, string>                                                               $caseTypes The case types adopted this run.
	 *
	 * @return array<string, mixed>|null The existing row.
	 */
	private function matched(array $placement, array $caseTypes): ?array {
		$target = $placement['target'];

		return $this->matcher->find(
			objectService: $target->objectService,
			register: $target->register,
			schema: $target->schema,
			schemaKey: $placement['group']['schemaKey'],
			row: $placement['row'],
			caseTypes: $caseTypes,
			match: (array)($placement['group']['match'] ?? []),
		);
	}//end matched()

	/**
	 * Take an existing row as the package's, and point later rows at it.
	 *
	 * @param array<string, mixed> $placement The row as the package ships it (`row`), its `group` and
	 *                                        `target`, and the row this instance holds (`existing`).
	 * @param array<string, mixed> $run       The run so far.
	 *
	 * @return void
	 */
	private function adopt(array $placement, array &$run): void {
		$existing = $placement['existing'];
		$existingId = $this->existingId(row: $existing);
		$packageId = $this->existingId(row: $placement['row']);
		if ($packageId !== '') {
			$run['ids'][$packageId] = $existingId;
		}

		$fill = [];
		if ($placement['group']['schemaKey'] === ExistingRowMatcher::CASE_TYPE_KEY) {
			$run['caseTypes'][] = $existingId;
			$fill = $this->fills()->lackedBy(shipped: $this->withoutMetadata(row: $placement['row']), stored: $existing);
		}

		$run['adopted'][] = [
			'schemaKey' => $placement['group']['schemaKey'],
			'schema' => $placement['target']->schema,
			'register' => $placement['target']->register,
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
	 * Write one row, and say whether it was created, replaced or left alone.
	 *
	 * 🔑 THE PACKAGE'S ID IS KEPT. Every child row carries a `caseType`
	 * back-reference by id, so minting a new id for the case type would leave
	 * every status, role and document type pointing at nothing. A CONFLICT is
	 * this instance already holding that id, which is a different question from
	 * the package carrying one.
	 *
	 * @param RowTarget            $target Where the row goes, and what to do about one already here.
	 * @param array<string, mixed> $row    The row as the package carries it.
	 *
	 * @return array{kind: string, id: string} What became of it, and the id.
	 */
	private function writeRow(RowTarget $target, array $row): array {
		$packageId = $this->existingId(row: $row);
		$conflict = $this->holdsRow(target: $target, row: $row);
		if ($conflict === true && $target->strategy === self::STRATEGY_SKIP) {
			return ['kind' => 'skipped', 'id' => $packageId];
		}

		$rowUuid = null;
		if ($packageId !== '') {
			$rowUuid = $packageId;
		}

		// An administrator's install; see holds() for why it passes the access check.
		$stored = $target->objectService->saveObject(
			$this->withoutMetadata(row: $row),
			register: $target->register,
			schema: $target->schema,
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
	 * Whether the target already holds the row under the id the package gives it.
	 *
	 * @param RowTarget            $target Where the row goes.
	 * @param array<string, mixed> $row    The row as the package carries it.
	 *
	 * @return boolean False for a row without an id.
	 */
	private function holdsRow(RowTarget $target, array $row): bool {
		$packageId = $this->existingId(row: $row);
		if ($packageId === '') {
			return false;
		}

		return $this->holds(objectService: $target->objectService, register: $target->register, schema: $target->schema, id: $packageId);
	}//end holdsRow()

	/**
	 * What one component's import amounts to, said in the caller's vocabulary.
	 *
	 * @param array<string, mixed> $run The component and strategy, and the ids `created`,
	 *                                  `replaced` and `skipped`.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function importOutcome(array $run): array {
		if ($run['created'] === [] && $run['replaced'] === []) {
			return $this->unwrittenOutcome(run: $run);
		}

		$this->logger->info(
			'Imported component {component} with strategy {strategy}: {created} created, {replaced} replaced',
			[
				'component' => $run['component'],
				'strategy' => $run['strategy'],
				'created' => count($run['created']),
				'replaced' => count($run['replaced']),
			]
		);

		return [
			'status' => 'success',
			'message' => "Component '{$run['component']}': " . count($run['created']) . ' created, ' . count($run['replaced']) . ' replaced',
			'created' => $run['created'],
			'replaced' => $run['replaced'],
		];
	}//end importOutcome()

	/**
	 * The outcome of a component import that created and replaced nothing.
	 *
	 * @param array{component: string, skipped: array<int, string>} $run The component and the ids left alone.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function unwrittenOutcome(array $run): array {
		if ($run['skipped'] === []) {
			// 🔴 NOTHING WRITTEN IS NOT A SUCCESS. This is the exact line the old
			// code got wrong, and the one REQ-CT-42 is about.
			return [
				'status' => 'error',
				'message' => "Component '{$run['component']}' held no rows this import could write.",
			];
		}

		// Everything was already here and the caller asked to leave it alone.
		// That is not a write and must not read as one.
		return [
			'status' => 'skipped',
			'message' => "Component '{$run['component']}': " . count($run['skipped']) . ' row(s) already present, left alone.',
			'created' => [],
			'replaced' => [],
		];
	}//end unwrittenOutcome()
}//end class
