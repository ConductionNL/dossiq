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
class CaseRecordImport {

	use SearchesObjects;

	/**
	 * Term outcomes that leave an open case watched.
	 */
	private const ARMED = [TermCarryOver::KEPT, TermCarryOver::CARRIED];

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
	 * @param RecordCaseMapping $mapping         One record onto a case payload.
	 * @param TermCarryOver     $term            The term carried onto the written case.
	 * @param RecordImportStore $store           Reads the sources and cases, writes the case and the stamp.
	 * @param DayCount               $days            Counts the days between two moments.
	 * @param RecordImportReferences|null $references The cases a number became, and the references to move.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppManager $appManager,
		private readonly RecordCaseMapping $mapping,
		private readonly TermCarryOver $term,
		private readonly RecordImportStore $store,
		private readonly DayCount $days = new DayCount(),
		?RecordImportReferences $references = null,
	) {
		$this->references = ($references ?? new RecordImportReferences(settingsService: $settingsService, store: $store));
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
				foreach ($this->store->caseTypesInUse(objectService: $objectService) as $type) {
					foreach ((array)($type['recordImports'] ?? []) as $import) {
						$import = (array)$import;
						if (($import['runOnUpgrade'] ?? false) !== true) {
							continue;
						}

						$answers[] = ['caseType' => $this->store->idOf(row: $type)]
							+ $this->runOne(objectService: $objectService, caseType: $type, import: $import, dryRun: false);
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
				$type = $this->store->caseType(objectService: $objectService, caseType: $caseType);
				if ($type === null) {
					return ['caseType' => '', 'imports' => []];
				}

				$imports = [];
				foreach ((array)($type['recordImports'] ?? []) as $import) {
					$import = (array)$import;
					if ($importKey !== '' && (string)($import['key'] ?? '') !== $importKey) {
						continue;
					}

					$imports[] = $this->runOne(objectService: $objectService, caseType: $type, import: $import, dryRun: $dryRun);
				}

				return ['caseType' => $this->store->idOf(row: $type), 'imports' => $imports];
			}
		);
	}//end runAll()

	/**
	 * One declared import.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $caseType      The case type.
	 * @param array<string, mixed> $import        The declaration.
	 * @param bool                 $dryRun        Count only.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function runOne(object $objectService, array $caseType, array $import, bool $dryRun): array {
		$answer = [
			'key' => (string)($import['key'] ?? ''),
			'sourceApp' => (string)($import['sourceApp'] ?? ''),
			'installed' => false,
			'imported' => 0,
			'alreadyImported' => 0,
			'failed' => [],
			'unmigrated' => 0,
			'migrated' => [],
			'duplicates' => [],
			'repointed' => 0,
		];

		// An undeclared app id is an app nobody installed: isInstalled('') answers false.
		if ($this->appManager->isInstalled($answer['sourceApp']) === false) {
			return $answer;
		}

		$answer['installed'] = true;
		$sources = $this->store->sources(objectService: $objectService, import: $import);
		if ($sources === null) {
			$answer['failed'][] = ['requestId' => '', 'reference' => '', 'reason' => 'The source records could not be read.'];
			return $answer;
		}

		$state = ['cases' => null, 'byKey' => null];
		foreach ($sources as $source) {
			$outcome = $this->moveRecord(
				objectService: $objectService,
				caseType: $caseType,
				import: $import,
				source: $source,
				dryRun: $dryRun,
				state: $state
			);
			if ($outcome['kind'] === 'stop') {
				$answer['failed'][] = $outcome['row'];
				return $answer;
			}

			$answer = $this->counted(answer: $answer, outcome: $outcome);
		}

		return $answer;
	}//end runOne()

	/**
	 * Which counter a source record lands in without being imported, or '' to import it.
	 *
	 * @param array<string, mixed> $source     The source record.
	 * @param string               $sourceId   Its uuid.
	 * @param string               $stampField The field an earlier run stamped, or ''.
	 * @param bool                 $dryRun     Count only.
	 *
	 * @return string `alreadyImported`, `unmigrated` or ''.
	 */
	private function skippedAs(array $source, string $sourceId, string $stampField, bool $dryRun): string {
		if ($stampField !== '' && trim((string)($source[$stampField] ?? '')) !== '') {
			return 'alreadyImported';
		}

		if ($dryRun === true || $sourceId === '') {
			return 'unmigrated';
		}

		return '';
	}//end skippedAs()

	/**
	 * Move one source record, or say why it was not moved.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $caseType      The case type.
	 * @param array<string, mixed> $import        The declaration.
	 * @param array<string, mixed> $source        The source record.
	 * @param bool                 $dryRun        Count only.
	 * @param array<string, mixed> $state         The cases read so far, by source and by number; read once, on first need.
	 *
	 * @return array{kind: string, row: array<string, mixed>} `kind` is alreadyImported, unmigrated, failed, duplicate, migrated or stop.
	 */
	private function moveRecord(object $objectService, array $caseType, array $import, array $source, bool $dryRun, array &$state): array {
		$sourceId = $this->store->idOf(row: $source);
		$stampField = (string)($import['stamp']['caseId'] ?? '');
		$skipped = $this->skippedAs(source: $source, sourceId: $sourceId, stampField: $stampField, dryRun: $dryRun);
		if ($skipped !== '') {
			return ['kind' => $skipped, 'row' => []];
		}

		// Read once, and only when there is something to import: a second
		// run over a fully migrated source reads no case at all. An unread
		// list stops the run: importing over it would write a half-finished
		// case a second time.
		$state['cases'] = ($state['cases'] ?? $this->store->casesBySource(objectService: $objectService, caseType: $caseType, import: $import));
		if ($state['cases'] === null) {
			$reason = 'The existing cases could not be read, so nothing more was imported.';
			return ['kind' => 'stop', 'row' => ['requestId' => '', 'reference' => '', 'reason' => $reason]];
		}

		$existing = (string)($state['cases'][$sourceId] ?? '');
		$duplicateOf = '';
		try {
			if ($existing === '') {
				$duplicateOf = $this->references->caseOfSameNumber(
					objectService: $objectService,
					caseType: $caseType,
					import: $import,
					source: $source,
					cache: $state['byKey']
				);
			}
		} catch (Throwable $e) {
			$reason = 'The existing cases could not be read by their earlier number, so nothing more was imported.';
			return ['kind' => 'stop', 'row' => ['requestId' => '', 'reference' => '', 'reason' => $reason]];
		}

		$outcome = $this->importOne(
			objectService: $objectService,
			caseType: $caseType,
			import: $import,
			source: $source,
			existing: $existing . $duplicateOf
		);
		if (isset($outcome['reason']) === true) {
			return ['kind' => 'failed', 'row' => $outcome];
		}

		// A second record carrying the same number is the same matter: it
		// joins the case the first one became, and is reported.
		if ($duplicateOf !== '') {
			return ['kind' => 'duplicate', 'row' => $outcome];
		}

		$this->references->rememberNumber(import: $import, source: $source, caseId: (string)$outcome['caseId'], cache: $state['byKey']);
		return ['kind' => 'migrated', 'row' => $outcome];
	}//end moveRecord()

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
		switch ($outcome['kind']) {
			case 'alreadyImported':
				$answer['alreadyImported']++;
				break;
			case 'unmigrated':
				$answer['unmigrated']++;
				break;
			case 'failed':
				$answer['failed'][] = $row;
				$answer['unmigrated']++;
				break;
			case 'duplicate':
				$answer['duplicates'][] = $row;
				break;
			default:
				$answer['imported']++;
				$answer['migrated'][] = $row;
		}

		return $answer;
	}//end counted()

	/**
	 * Move one record: find or write its case, carry its term, then stamp it.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $caseType      The case type.
	 * @param array<string, mixed> $import        The declaration.
	 * @param array<string, mixed> $source        The source record.
	 * @param string               $existing      The case a previous run wrote for it, or ''.
	 *
	 * @return array<string, int|string> The migrated row, or a failed row carrying `reason`.
	 */
	private function importOne(object $objectService, array $caseType, array $import, array $source, string $existing): array {
		$sourceId = $this->store->idOf(row: $source);
		$reference = (string)($source[(string)($import['formerReference']['from'] ?? '')] ?? '');
		$failed = static fn (string $reason): array => ['requestId' => $sourceId, 'reference' => $reference, 'reason' => $reason];

		try {
			$mapped = $this->mapping->map(import: $import, record: $source, recordId: $sourceId, caseTypeId: $this->store->idOf(row: $caseType));
		} catch (InvalidArgumentException $e) {
			return $failed($e->getMessage());
		}

		$caseId = $existing;
		if ($caseId === '') {
			$caseId = $this->store->writeCase(objectService: $objectService, mapped: $mapped);
			if ($caseId === '') {
				return $failed('The case could not be written.');
			}
		}

		if (($import['carriesTerm'] ?? true) !== false) {
			$carried = $this->term->carry(
				caseId: $caseId,
				term: $mapped['term'],
				definitionSlug: (string)($caseType['identifier'] ?? ''),
				extensionDays: $this->days(period: (string)($caseType['extensionPeriod'] ?? '')),
			);
			if ($mapped['term']['state'] !== TermCarryOver::STATE_CLOSED && in_array($carried['outcome'], self::ARMED, true) === false) {
				return $failed('The case ' . $caseId . ' was written, but its term could not be armed (' . $carried['outcome'] . ').');
			}
		}

		// References are moved before the stamp: a record whose references
		// could not all be moved stays unstamped, so the next run tries again.
		try {
			$repointed = $this->references->repoint(
				objectService: $objectService,
				import: $import,
				oldValues: [$sourceId, (string)($source['@self']['slug'] ?? '')],
				caseId: $caseId
			);
		} catch (Throwable $e) {
			return $failed('The case ' . $caseId . ' was written, but the records pointing at the source could not all be moved onto it: ' . $e->getMessage());
		}

		if ($this->store->stamp(objectService: $objectService, import: $import, sourceId: $sourceId, caseId: $caseId) === false) {
			return $failed('The source app did not keep the stamp, so the record is not counted as moved.');
		}

		return [
			'requestId' => $sourceId,
			'reference' => $reference,
			'caseId' => $caseId,
			'sourceTimer' => (string)($source[(string)($import['sourceTimerField'] ?? '')] ?? ''),
			'repointed' => $repointed,
		];
	}//end importOne()


	/**
	 * An ISO 8601 period as days, 0 when absent or unreadable.
	 *
	 * @param string $period The period, such as P14D or P2W.
	 *
	 * @return int The days.
	 */
	private function days(string $period): int {
		if ($period === '') {
			return 0;
		}

		try {
			$from = new DateTimeImmutable('2000-01-01');
			return $this->days->between(from: $from, to: $from->add(new DateInterval($period)));
		} catch (Throwable) {
			return 0;
		}
	}//end days()

}//end class
