<?php

/**
 * Dossiq case-record import
 *
 * Imports another app's stored records as cases of one case type, by the
 * mapping that case type declares under `recordImports`. Every source record
 * becomes one case, its running term carried at the date the source reported
 * (TermCarryOver), exactly once. The source is stamped (the declared case-id
 * and moment fields) only after the case and its timer both exist, so a run
 * that stopped half way is finished by the next one without a second case.
 * The source app's own timer is never touched: each migrated record is
 * answered with it, so the caller can stop it.
 *
 * Generic: the procedure is the case type's configuration. Which app, which
 * schema, which fields and statuses are all declared, none are named here.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Import;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\Kernel\Contracts\CaseRecordImportInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Term\DayCount;
use OCA\Dossiq\Service\Term\TermCarryOver;
use OCP\App\IAppManager;
use Throwable;

/**
 * Moves another app's stored records onto cases, by the case type's declaration.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 */
class CaseRecordImport implements CaseRecordImportInterface {

	use SearchesObjects;

	/**
	 * Term outcomes that leave an open case watched.
	 */
	private const ARMED = [TermCarryOver::KEPT, TermCarryOver::CARRIED];

	/**
	 * A failed row that names no record.
	 */
	private const NO_RECORD = ['requestId' => '', 'reference' => ''];

	/**
	 * Why a run stops when the existing cases cannot be read.
	 */
	private const UNREAD_CASES = 'The existing cases could not be read, so nothing more was imported.';

	/**
	 * Why a run stops when the existing cases cannot be read by their earlier number.
	 */
	private const UNREAD_NUMBERS = 'The existing cases could not be read by their earlier number, so nothing more was imported.';

	/**
	 * An import's answer before anything was read, after its key and source app.
	 */
	private const NOTHING_YET = [
		'installed' => false,
		'imported' => 0,
		'alreadyImported' => 0,
		'failed' => [],
		'unmigrated' => 0,
		'migrated' => [],
		'duplicates' => [],
		'repointed' => 0,
	];

	/**
	 * Per outcome kind: the counter it raises and the list it joins, if any.
	 * Any other kind is a migration.
	 */
	private const COUNTS = [
		'alreadyImported' => ['alreadyImported', null],
		'unmigrated' => ['unmigrated', null],
		'failed' => ['unmigrated', 'failed'],
		'duplicate' => [null, 'duplicates'],
		'migrated' => ['imported', 'migrated'],
	];

	/**
	 * The cases a number became, and the references to move.
	 *
	 * @var RecordImportReferences
	 */
	private readonly RecordImportReferences $references;

	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService Register, schemas and OpenRegister.
	 * @param IAppManager       $appManager      Whether the source app is installed.
	 * @param RecordImportSteps $steps The record mapping, the term carry-over and the import store.
	 * @param DayCount               $days            Counts the days between two moments.
	 * @param RecordImportReferences|null $references The cases a number became, and the references to move.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppManager $appManager,
		private readonly RecordImportSteps $steps,
		private readonly DayCount $days = new DayCount(),
		?RecordImportReferences $references = null,
	) {
		$this->references = ($references ?? new RecordImportReferences(settingsService: $settingsService, store: $steps->store));
	}//end __construct()

	/**
	 * Run every import a case type declares, or the one named.
	 *
	 * @param string $caseType  The case type, by uuid or identifier.
	 * @param string $importKey One declared import's key, or '' for all.
	 *
	 * @return array{caseType: string, imports: list<array<string, mixed>>} One answer per import:
	 *         `{key, sourceApp, installed, imported, alreadyImported, failed, unmigrated, migrated}`.
	 *         An empty `caseType` means the case type was not found.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
	 */
	public function run(string $caseType, string $importKey = ''): array {
		return $this->runAll(caseType: $caseType, importKey: $importKey, dryRun: false);
	}//end run()

	/**
	 * Count what has not moved, writing nothing.
	 *
	 * @param string $caseType  The case type, by uuid or identifier.
	 * @param string $importKey One declared import's key, or '' for all.
	 *
	 * @return array{caseType: string, imports: list<array<string, mixed>>} As {@see run()}, with nothing imported.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
	 */
	public function dryRun(string $caseType, string $importKey = ''): array {
		return $this->runAll(caseType: $caseType, importKey: $importKey, dryRun: true);
	}//end dryRun()

	/**
	 * Run every declaration flagged `runOnUpgrade` of every case type version in use.
	 *
	 * @return list<array<string, mixed>> One answer per declaration, as {@see run()} gives them,
	 *                                    each with the `caseType` it belongs to.
	 *
	 * @spec openspec/specs/case-record-import/spec.md#requirement-declared-imports-run-on-upgrade-req-cri-007
	 */
	public function runOnUpgrade(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		return (array)$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: function () use ($objectService): array {
				$answers = [];
				foreach ($this->steps->store->caseTypesInUse(objectService: $objectService) as $type) {
					foreach ((array)($type['recordImports'] ?? []) as $import) {
						$import = (array)$import;
						if (($import['runOnUpgrade'] ?? false) !== true) {
							continue;
						}

						$answers[] = ['caseType' => $this->steps->store->idOf(row: $type)]
							+ $this->runOne(run: ['objectService' => $objectService, 'caseType' => $type, 'import' => $import, 'dryRun' => false]);
					}
				}

				return $answers;
			}
		);
	}//end runOnUpgrade()

	/**
	 * Both runs.
	 *
	 * @param string $caseType  The case type.
	 * @param string $importKey The import key, or ''.
	 * @param bool   $dryRun    Count only.
	 *
	 * @return array{caseType: string, imports: list<array<string, mixed>>}
	 */
	private function runAll(string $caseType, string $importKey, bool $dryRun): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return ['caseType' => '', 'imports' => []];
		}

		// The import runs from occ, with no user session: every read and write
		// below is the system's, and its inputs are stored rows and the case
		// type's own declaration.
		return (array)$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: function () use ($objectService, $caseType, $importKey, $dryRun): array {
				$type = $this->steps->store->caseType(objectService: $objectService, caseType: $caseType);
				if ($type === null) {
					return ['caseType' => '', 'imports' => []];
				}

				$imports = [];
				foreach ((array)($type['recordImports'] ?? []) as $import) {
					$import = (array)$import;
					if ($importKey !== '' && (string)($import['key'] ?? '') !== $importKey) {
						continue;
					}

					$imports[] = $this->runOne(run: ['objectService' => $objectService, 'caseType' => $type, 'import' => $import, 'dryRun' => $dryRun]);
				}

				return ['caseType' => $this->steps->store->idOf(row: $type), 'imports' => $imports];
			}
		);
	}//end runAll()

	/**
	 * Run one declared import.
	 *
	 * @param array<string, mixed> $run The run: objectService, caseType, import, dryRun.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function runOne(array $run): array {
		$import = $run['import'];
		$answer = [
			'key' => (string)($import['key'] ?? ''),
			'sourceApp' => (string)($import['sourceApp'] ?? ''),
		] + self::NOTHING_YET;

		// An undeclared app id is an app nobody installed: isInstalled('') answers false.
		if ($this->appManager->isInstalled($answer['sourceApp']) === false) {
			return $answer;
		}

		$answer['installed'] = true;
		$sources = $this->steps->store->sources(objectService: $run['objectService'], import: $import);
		if ($sources === null) {
			$answer['failed'][] = self::NO_RECORD + ['reason' => 'The source records could not be read.'];
			return $answer;
		}

		$state = ['cases' => null, 'byKey' => null];
		foreach ($sources as $source) {
			$outcome = $this->moveRecord(run: $run, source: $source, state: $state);
			if ($outcome['kind'] === 'stop') {
				$answer['failed'][] = $outcome['row'];
				return $answer;
			}

			$answer = $this->counted(answer: $answer, outcome: $outcome);
		}

		return $answer;
	}//end runOne()

	/**
	 * Why a record is not imported this run, or '' when it is.
	 *
	 * @param array<string, mixed>                                 $source The source record.
	 * @param array{import: array<string, mixed>, dryRun: bool} $run    The declaration, and whether to count only.
	 *
	 * @return string `alreadyImported`, `unmigrated` or ''.
	 */
	private function skippedAs(array $source, array $run): string {
		$stampField = (string)($run['import']['stamp']['caseId'] ?? '');
		if ($stampField !== '' && trim((string)($source[$stampField] ?? '')) !== '') {
			return 'alreadyImported';
		}

		if ($run['dryRun'] === true || $this->steps->store->idOf(row: $source) === '') {
			return 'unmigrated';
		}

		return '';
	}//end skippedAs()

	/**
	 * Move one source record onto a case, or say why it was not.
	 *
	 * @param array<string, mixed> $run The run: objectService, caseType, import, dryRun.
	 * @param array<string, mixed> $source The source record.
	 * @param array<string, mixed> $state  The cases read so far, by source and by number; read once, on first need.
	 *
	 * @return array{kind: string, row: array<string, mixed>} `kind` is alreadyImported, unmigrated, failed, duplicate, migrated or stop.
	 */
	private function moveRecord(array $run, array $source, array &$state): array {
		$skipped = $this->skippedAs(source: $source, run: $run);
		if ($skipped !== '') {
			return ['kind' => $skipped, 'row' => []];
		}

		$match = $this->matchFor(run: $run + ['source' => $source], state: $state);
		if (isset($match['kind']) === true) {
			return $match;
		}

		$outcome = $this->importOne(run: $run + ['existing' => $match['existing'] . $match['duplicateOf']], source: $source);
		if (isset($outcome['reason']) === true) {
			return ['kind' => 'failed', 'row' => $outcome];
		}

		// A second record carrying the same number is the same matter: it
		// joins the case the first one became, and is reported.
		if ($match['duplicateOf'] !== '') {
			return ['kind' => 'duplicate', 'row' => $outcome];
		}

		$this->references->rememberNumber(import: $run['import'], source: $source, caseId: (string)$outcome['caseId'], cache: $state['byKey']);
		return ['kind' => 'migrated', 'row' => $outcome];
	}//end moveRecord()

	/**
	 * The case a record already has, or the case of an earlier record with its number.
	 *
	 * Read once, and only when there is something to import: a second run
	 * over a fully migrated source reads no case at all. An unread list stops
	 * the run: importing over it would write a half-finished case a second time.
	 * A record that already has a case while its number became another one is
	 * a conflict: completing it would give one number two cases, and joining
	 * would leave its own case behind.
	 *
	 * @param array<string, mixed> $run The run: objectService, caseType, import, source.
	 * @param array<string, mixed> $state The cases read so far, by source and by number.
	 *
	 * @return array{existing: string, duplicateOf: string}|array{kind: string, row: array<string, mixed>} The match, or
	 *         the outcome when the run stops (`stop`) or this record is not imported (`failed`).
	 */
	private function matchFor(array $run, array &$state): array {
		$state['cases'] = ($state['cases'] ?? $this->steps->store->casesBySource(
			objectService: $run['objectService'],
			caseType: $run['caseType'],
			import: $run['import']
		));
		if ($state['cases'] === null) {
			return ['kind' => 'stop', 'row' => self::NO_RECORD + ['reason' => self::UNREAD_CASES]];
		}

		$existing = (string)($state['cases'][$this->steps->store->idOf(row: $run['source'])] ?? '');
		try {
			$numbered = $this->references->caseOfSameNumber(
				objectService: $run['objectService'],
				caseType: $run['caseType'],
				import: $run['import'],
				source: $run['source'],
				cache: $state['byKey']
			);
		} catch (Throwable $e) {
			return ['kind' => 'stop', 'row' => self::NO_RECORD + ['reason' => self::UNREAD_NUMBERS]];
		}

		$placement = $this->references->placement(existing: $existing, numbered: $numbered);
		if (isset($placement['conflict']) === true) {
			$reference = (string)($run['source'][(string)($run['import']['formerReference']['from'] ?? '')] ?? '');
			$row = ['requestId' => $this->steps->store->idOf(row: $run['source']), 'reference' => $reference, 'reason' => $placement['conflict']];

			return ['kind' => 'failed', 'row' => $row];
		}

		return $placement;
	}//end matchFor()

	/**
	 * The answer with one record's outcome counted.
	 *
	 * @param array<string, mixed>                          $answer  The answer so far.
	 * @param array{kind: string, row: array<string, mixed>} $outcome The record's outcome.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function counted(array $answer, array $outcome): array {
		$row = $outcome['row'];
		$answer['repointed'] += (int)($row['repointed'] ?? 0);
		unset($row['repointed']);

		[$counter, $list] = (self::COUNTS[$outcome['kind']] ?? self::COUNTS['migrated']);
		if ($counter !== null) {
			$answer[$counter]++;
		}

		if ($list !== null) {
			$answer[$list][] = $row;
		}

		return $answer;
	}//end counted()

	/**
	 * Write one record as a case, carry its term, move its references and stamp it.
	 *
	 * @param array<string, mixed> $run The run: objectService, caseType, import, existing.
	 * @param array<string, mixed> $source The source record.
	 *
	 * @return array<string, int|string> The migrated row, or a failed row carrying `reason`.
	 */
	private function importOne(array $run, array $source): array {
		$sourceId = $this->steps->store->idOf(row: $source);
		$reference = (string)($source[(string)($run['import']['formerReference']['from'] ?? '')] ?? '');
		$failed = static fn (string $reason): array => ['requestId' => $sourceId, 'reference' => $reference, 'reason' => $reason];

		$placed = $this->placeCase(run: $run, source: $source);
		if (isset($placed['reason']) === true) {
			return $failed($placed['reason']);
		}

		$caseId = $placed['caseId'];

		// References are moved before the stamp: a record whose references
		// could not all be moved stays unstamped, so the next run tries again.
		try {
			$repointed = $this->references->repoint(
				objectService: $run['objectService'],
				import: $run['import'],
				oldValues: [$sourceId, (string)($source['@self']['slug'] ?? '')],
				caseId: $caseId
			);
		} catch (Throwable $e) {
			return $failed('The case ' . $caseId . ' was written, but the records pointing at the source could not all be moved onto it: ' . $e->getMessage());
		}

		if ($this->steps->store->stamp(objectService: $run['objectService'], import: $run['import'], sourceId: $sourceId, caseId: $caseId) === false) {
			return $failed('The source app did not keep the stamp, so the record is not counted as moved.');
		}

		return [
			'requestId' => $sourceId,
			'reference' => $reference,
			'caseId' => $caseId,
			'sourceTimer' => (string)($source[(string)($run['import']['sourceTimerField'] ?? '')] ?? ''),
			'repointed' => $repointed,
		];
	}//end importOne()

	/**
	 * Map a record, write its case unless it has one, and carry its term.
	 *
	 * @param array<string, mixed> $run The run: objectService, caseType, import, existing.
	 * @param array<string, mixed> $source The source record.
	 *
	 * @return array{caseId: string}|array{reason: string} The case, or why the record failed.
	 */
	private function placeCase(array $run, array $source): array {
		$caseType = $run['caseType'];
		try {
			$ids = [$this->steps->store->idOf(row: $source), $this->steps->store->idOf(row: $caseType)];
			$mapped = $this->steps->mapping->map(import: $run['import'], record: $source, recordId: $ids[0], caseTypeId: $ids[1]);
		} catch (InvalidArgumentException $e) {
			return ['reason' => $e->getMessage()];
		}

		$caseId = $run['existing'];
		if ($caseId === '') {
			$caseId = $this->steps->store->writeCase(objectService: $run['objectService'], mapped: $mapped);
		}

		if ($caseId === '') {
			return ['reason' => 'The case could not be written.'];
		}

		if (($run['import']['carriesTerm'] ?? true) === false) {
			return ['caseId' => $caseId];
		}

		$carried = $this->steps->term->carry(
			caseId: $caseId,
			term: $mapped['term'],
			definitionSlug: (string)($caseType['identifier'] ?? ''),
			extensionDays: $this->days(period: (string)($caseType['extensionPeriod'] ?? '')),
		);
		if ($mapped['term']['state'] !== TermCarryOver::STATE_CLOSED && in_array($carried['outcome'], self::ARMED, true) === false) {
			return ['reason' => 'The case ' . $caseId . ' was written, but its term could not be armed (' . $carried['outcome'] . ').'];
		}

		return ['caseId' => $caseId];
	}//end placeCase()

	/**
	 * An ISO 8601 period as days, 0 when absent or unreadable.
	 *
	 * @param string $period The period, such as P14D or P2W.
	 *
	 * @return int The days.
	 */
	private function days(string $period): int {
		// An empty or unreadable period throws, and reads as no extension.
		try {
			$from = new DateTimeImmutable('2000-01-01');
			return $this->days->between(from: $from, to: $from->add(new DateInterval($period)));
		} catch (Throwable) {
			return 0;
		}
	}//end days()

}//end class
