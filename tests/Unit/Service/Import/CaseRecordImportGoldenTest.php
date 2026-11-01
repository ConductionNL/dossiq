<?php

/**
 * CaseRecordImport golden test
 *
 * Pins how a record import orchestrates its collaborators: which records it
 * skips, when it reads the existing cases, how a second record with the same
 * number joins the first one's case, what it writes, arms, repoints and
 * stamps, where it stops, and what it counts.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Import;

use InvalidArgumentException;
use OCA\Dossiq\Service\Import\CaseRecordImport;
use OCA\Dossiq\Service\Import\RecordCaseMapping;
use OCA\Dossiq\Service\Import\RecordImportReferences;
use OCA\Dossiq\Service\Import\RecordImportStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Term\TermCarryOver;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden test for CaseRecordImport.
 *
 * @covers \OCA\Dossiq\Service\Import\CaseRecordImport
 * @uses \OCA\Dossiq\Service\Term\DayCount
 * @uses \OCA\Dossiq\Service\Import\RecordImportStore
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseRecordImportGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every call any fake received, in order.
	 *
	 * @var array<int, mixed>
	 */
	public array $calls = [];

	/**
	 * The world the fakes answer from.
	 *
	 * @var array<string, mixed>
	 */
	public array $world = [];

	/**
	 * A store over the world's case types, sources and existing cases.
	 *
	 * @return RecordImportStore The store.
	 */
	private function store(): RecordImportStore {
		$test = $this;
		return new class($test) extends RecordImportStore {
			/**
			 * Constructor.
			 *
			 * @param CaseRecordImportGoldenTest $test The test holding the world.
			 */
			public function __construct(private CaseRecordImportGoldenTest $test) {
			}

			/**
			 * @param object $objectService The object service.
			 *
			 * @return array<int, array<string, mixed>> The case types.
			 */
			public function caseTypesInUse(object $objectService): array {
				$this->test->calls[] = ['caseTypesInUse'];
				return array_values($this->test->world['types'] ?? []);
			}

			/**
			 * @param object $objectService The object service.
			 * @param string $caseType      The case type.
			 *
			 * @return array<string, mixed>|null The case type.
			 */
			public function caseType(object $objectService, string $caseType): ?array {
				$this->test->calls[] = ['caseType', $caseType];
				return ($this->test->world['types'][$caseType] ?? null);
			}

			/**
			 * @param object               $objectService The object service.
			 * @param array<string, mixed> $import        The import.
			 *
			 * @return array<int, array<string, mixed>>|null The sources.
			 */
			public function sources(object $objectService, array $import): ?array {
				$this->test->calls[] = ['sources', $import['key'] ?? ''];
				$sources = ($this->test->world['sources'] ?? []);
				return array_key_exists((string)($import['key'] ?? ''), $sources) === true ? $sources[(string)$import['key']] : [];
			}

			/**
			 * @param object               $objectService The object service.
			 * @param array<string, mixed> $caseType      The case type.
			 * @param array<string, mixed> $import        The import.
			 *
			 * @return array<string, string>|null The cases by source.
			 */
			public function casesBySource(object $objectService, array $caseType, array $import): ?array {
				$this->test->calls[] = ['casesBySource', $caseType['id'] ?? '', $import['key'] ?? ''];
				return array_key_exists('cases', $this->test->world) === true ? $this->test->world['cases'] : [];
			}

			/**
			 * @param object               $objectService The object service.
			 * @param array<string, mixed> $mapped        The mapped case.
			 *
			 * @return string The case id, or ''.
			 */
			public function writeCase(object $objectService, array $mapped): string {
				$this->test->calls[] = ['writeCase', $mapped];
				$id = (string)$mapped['recordId'];
				return (string)($this->test->world['write'][$id] ?? ('case-' . $id));
			}

			/**
			 * @param object               $objectService The object service.
			 * @param array<string, mixed> $import        The import.
			 * @param string               $sourceId      The source.
			 * @param string               $caseId        The case.
			 *
			 * @return bool Whether the stamp held.
			 */
			public function stamp(object $objectService, array $import, string $sourceId, string $caseId): bool {
				$this->test->calls[] = ['stamp', $import['key'] ?? '', $sourceId, $caseId];
				return in_array($sourceId, ($this->test->world['stampFails'] ?? []), true) === false;
			}
		};
	}//end store()

	/**
	 * References that keep the same-number cache the run hands them.
	 *
	 * @return RecordImportReferences The references.
	 */
	private function references(): RecordImportReferences {
		$test = $this;
		return new class($test) extends RecordImportReferences {
			/**
			 * Constructor.
			 *
			 * @param CaseRecordImportGoldenTest $test The test holding the world.
			 */
			public function __construct(private CaseRecordImportGoldenTest $test) {
			}

			/**
			 * @param object                     $objectService The object service.
			 * @param array<string, mixed>       $caseType      The case type.
			 * @param array<string, mixed>       $import        The import.
			 * @param array<string, mixed>       $source        The source.
			 * @param array<string, string>|null $cache         The run's cache.
			 *
			 * @return string The case of the same number, or ''.
			 */
			public function caseOfSameNumber(object $objectService, array $caseType, array $import, array $source, ?array &$cache): string {
				$number = (string)($source[(string)($import['numberField'] ?? '')] ?? '');
				$this->test->calls[] = ['caseOfSameNumber', $number, $cache];
				if ($number === '') {
					return '';
				}

				if (($this->test->world['byNumber'] ?? null) === 'throw') {
					throw new RuntimeException('numbers unreadable');
				}

				$cache = ($cache ?? ($this->test->world['byNumber'] ?? []));
				return (string)($cache[$number] ?? '');
			}

			/**
			 * @param array<string, mixed>       $import The import.
			 * @param array<string, mixed>       $source The source.
			 * @param string                     $caseId The case.
			 * @param array<string, string>|null $cache  The run's cache.
			 *
			 * @return void
			 */
			public function rememberNumber(array $import, array $source, string $caseId, ?array &$cache): void {
				$number = (string)($source[(string)($import['numberField'] ?? '')] ?? '');
				$this->test->calls[] = ['rememberNumber', $number, $caseId];
				if ($number !== '') {
					$cache[$number] = $caseId;
				}
			}

			/**
			 * @param object             $objectService The object service.
			 * @param array<string, mixed> $import      The import.
			 * @param array<int, string> $oldValues     The old values.
			 * @param string             $caseId        The case.
			 *
			 * @return int How many references moved.
			 */
			public function repoint(object $objectService, array $import, array $oldValues, string $caseId): int {
				$this->test->calls[] = ['repoint', $oldValues, $caseId];
				if (in_array($caseId, ($this->test->world['repointThrows'] ?? []), true) === true) {
					throw new RuntimeException('a reference would not move');
				}

				return (int)($this->test->world['repointed'] ?? 2);
			}
		};
	}//end references()

	/**
	 * Run one call against a world and record every effect.
	 *
	 * @param array<string, mixed> $world The world and the call.
	 *
	 * @return array<string, mixed> The answer and the calls.
	 */
	private function observe(array $world): array {
		$this->calls = [];
		$this->world = $world;
		$test = $this;

		$objectService = match ($world['service'] ?? 'plain') {
			'none' => null,
			'system' => new class($test) {
				/**
				 * Constructor.
				 *
				 * @param CaseRecordImportGoldenTest $test The test.
				 */
				public function __construct(private CaseRecordImportGoldenTest $test) {
				}

				/**
				 * Run as the system.
				 *
				 * @param callable $operation The operation.
				 *
				 * @return mixed Its answer.
				 */
				public function runAsSystem(callable $operation): mixed {
					$this->test->calls[] = ['runAsSystem'];
					return $operation();
				}
			},
			default => new \stdClass(),
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(
			function (string $app) use ($world): bool {
				$this->calls[] = ['isInstalled', $app];
				return in_array($app, ($world['installed'] ?? ['opencatalogi']), true);
			}
		);

		$mapping = new class($test) extends RecordCaseMapping {
			/**
			 * Constructor.
			 *
			 * @param CaseRecordImportGoldenTest $test The test.
			 */
			public function __construct(private CaseRecordImportGoldenTest $test) {
			}

			/**
			 * @param array<string, mixed> $import     The import.
			 * @param array<string, mixed> $record     The record.
			 * @param string               $recordId   The record id.
			 * @param string               $caseTypeId The case type id.
			 *
			 * @return array<string, mixed> The mapped case.
			 */
			public function map(array $import, array $record, string $recordId, string $caseTypeId): array {
				$this->test->calls[] = ['map', $import['key'] ?? '', $recordId, $caseTypeId];
				if (isset($record['unmappable']) === true) {
					throw new InvalidArgumentException('Status ' . $record['unmappable'] . ' maps to no stage');
				}

				return ['recordId' => $recordId, 'caseType' => $caseTypeId, 'term' => ['state' => (string)($record['termState'] ?? 'running'), 'days' => 3]];
			}
		};

		$term = new class($test) extends TermCarryOver {
			/**
			 * Constructor.
			 *
			 * @param CaseRecordImportGoldenTest $test The test.
			 */
			public function __construct(private CaseRecordImportGoldenTest $test) {
			}

			/**
			 * @param string               $caseId         The case.
			 * @param array<string, mixed> $term           The term.
			 * @param string               $definitionSlug The definition.
			 * @param int                  $extensionDays  The extension days.
			 *
			 * @return array<string, string> The outcome.
			 */
			public function carry(string $caseId, array $term, string $definitionSlug, int $extensionDays = 0): array {
				$this->test->calls[] = ['carry', $caseId, $term, $definitionSlug, $extensionDays];
				return ['outcome' => (string)($this->test->world['carry'][$caseId] ?? TermCarryOver::CARRIED), 'instance' => 'i', 'timer' => 't'];
			}
		};

		$import = new CaseRecordImport(
			settingsService: $settings,
			appManager: $apps,
			mapping: $mapping,
			term: $term,
			store: $this->store(),
			references: $this->references(),
		);

		$answer = ($world['call'])($import);

		return json_decode((string)json_encode(['answer' => $answer, 'calls' => $this->calls], JSON_PRESERVE_ZERO_FRACTION), true);
	}//end observe()

	/**
	 * Every run across its skips, stops, failures and counts.
	 *
	 * @return void
	 */
	public function testGoldenCaseRecordImport(): void {
		$run = static fn (string $type = 'ct-1', string $key = ''): \Closure => static fn (CaseRecordImport $i): array => $i->run(caseType: $type, importKey: $key);
		$dry = static fn (string $type = 'ct-1', string $key = ''): \Closure => static fn (CaseRecordImport $i): array => $i->dryRun(caseType: $type, importKey: $key);
		$upgrade = static fn (CaseRecordImport $i): array => $i->runOnUpgrade();
		$imp = static fn (string $key, array $extra = []): array => $extra + ['key' => $key, 'sourceApp' => 'opencatalogi', 'stamp' => ['caseId' => 'migratedTo'], 'formerReference' => ['from' => 'number'], 'numberField' => 'number', 'sourceTimerField' => 'deadline'];
		$type = static fn (array $imports, array $extra = []): array => ['ct-1' => $extra + ['id' => 'ct-1', 'identifier' => 'woo-verzoek', 'extensionPeriod' => 'P14D', 'recordImports' => $imports]];
		$rec = static fn (string $id, array $extra = []): array => $extra + ['id' => $id, 'number' => 'N-' . $id, 'deadline' => '2026-11-0' . strlen($id), '@self' => ['slug' => 'slug-' . $id]];
		$one = $type([$imp('woo')]);
		$three = ['woo' => [$rec('r1'), $rec('r2'), $rec('r3')]];

		$worlds = [
			'run, no object service' => ['service' => 'none', 'call' => $run()],
			'dry run, no object service' => ['service' => 'none', 'call' => $dry()],
			'upgrade, no object service' => ['service' => 'none', 'call' => $upgrade],
			'run, unknown case type' => ['types' => $one, 'call' => $run('ct-x')],
			'run, as the system' => ['service' => 'system', 'types' => $one, 'sources' => $three, 'call' => $run()],
			'run, three new records' => ['types' => $one, 'sources' => $three, 'call' => $run()],
			'dry run, three new records' => ['types' => $one, 'sources' => $three, 'call' => $dry()],
			'run, one import by key' => ['types' => $type([$imp('a'), $imp('b'), $imp('')]), 'sources' => ['a' => [$rec('r1')], 'b' => [$rec('r2')]], 'call' => $run(key: 'b')],
			'run, every import' => ['types' => $type([$imp('a'), 'junk', $imp('b')]), 'sources' => ['a' => [$rec('r1')], 'b' => [$rec('r2')]], 'call' => $run()],
			'run, no imports declared' => ['types' => ['ct-1' => ['id' => 'ct-1']], 'call' => $run()],
			'run, source app not installed' => ['types' => $one, 'installed' => [], 'sources' => $three, 'call' => $run()],
			'run, no source app declared' => ['types' => $type([['key' => 'woo']]), 'installed' => [''], 'sources' => [], 'call' => $run()],
			'run, sources unreadable' => ['types' => $one, 'sources' => ['woo' => null], 'call' => $run()],
			'run, stamped and id-less records are skipped' => ['types' => $one, 'sources' => ['woo' => [$rec('r1', ['migratedTo' => 'case-old']), $rec('r2', ['migratedTo' => '  ']), ['number' => 'N-x'], $rec('r3')]], 'call' => $run()],
			'run, no stamp field declared' => ['types' => $type([$imp('woo', ['stamp' => []])]), 'sources' => ['woo' => [$rec('r1', ['migratedTo' => 'case-old'])]], 'call' => $run()],
			'dry run, a stamped record is already imported' => ['types' => $one, 'sources' => ['woo' => [$rec('r1', ['migratedTo' => 'case-old']), $rec('r2')]], 'call' => $dry()],
			'run, only stamped records read no cases' => ['types' => $one, 'sources' => ['woo' => [$rec('r1', ['migratedTo' => 'c'])]], 'cases' => null, 'call' => $run()],
			'run, existing cases unreadable stops the run' => ['types' => $one, 'sources' => ['woo' => [$rec('r1', ['migratedTo' => 'c']), $rec('r2'), $rec('r3')]], 'cases' => null, 'call' => $run()],
			'run, a half-finished record completes its case' => ['types' => $one, 'sources' => $three, 'cases' => ['r2' => 'case-half'], 'call' => $run()],
			'run, the same number joins the first case' => ['types' => $one, 'sources' => ['woo' => [$rec('r1'), $rec('r2', ['number' => 'N-r1']), $rec('r3', ['number' => ''])]], 'call' => $run()],
			'run, a number already imported earlier' => ['types' => $one, 'sources' => $three, 'byNumber' => ['N-r2' => 'case-earlier'], 'call' => $run()],
			'run, numbers unreadable stops the run' => ['types' => $one, 'sources' => $three, 'byNumber' => 'throw', 'call' => $run()],
			'run, numbers unreadable but the case is known' => ['types' => $one, 'sources' => ['woo' => [$rec('r1')]], 'cases' => ['r1' => 'case-known'], 'byNumber' => 'throw', 'call' => $run()],
			'run, an unmappable record fails and the rest move' => ['types' => $one, 'sources' => ['woo' => [$rec('r1', ['unmappable' => 'raar']), $rec('r2')]], 'call' => $run()],
			'run, a case that will not write' => ['types' => $one, 'sources' => $three, 'write' => ['r2' => ''], 'call' => $run()],
			'run, without a term' => ['types' => $type([$imp('woo', ['carriesTerm' => false])]), 'sources' => $three, 'call' => $run()],
			'run, carriesTerm not false carries' => ['types' => $type([$imp('woo', ['carriesTerm' => 0])]), 'sources' => ['woo' => [$rec('r1')]], 'call' => $run()],
			'run, a term that could not be armed' => ['types' => $one, 'sources' => $three, 'carry' => ['case-r1' => TermCarryOver::NOT_ARMED, 'case-r2' => TermCarryOver::KEPT, 'case-r3' => TermCarryOver::MISSING], 'call' => $run()],
			'run, a closed term needs no arming' => ['types' => $one, 'sources' => ['woo' => [$rec('r1', ['termState' => TermCarryOver::STATE_CLOSED])]], 'carry' => ['case-r1' => TermCarryOver::COMPLETED], 'call' => $run()],
			'run, no extension period' => ['types' => $type([$imp('woo')], ['extensionPeriod' => '', 'identifier' => null]), 'sources' => ['woo' => [$rec('r1')]], 'call' => $run()],
			'run, an unreadable extension period' => ['types' => $type([$imp('woo')], ['extensionPeriod' => 'two weeks']), 'sources' => ['woo' => [$rec('r1')]], 'call' => $run()],
			'run, a month extension' => ['types' => $type([$imp('woo')], ['extensionPeriod' => 'P1M']), 'sources' => ['woo' => [$rec('r1')]], 'call' => $run()],
			'run, references that will not move' => ['types' => $one, 'sources' => $three, 'repointThrows' => ['case-r2'], 'call' => $run()],
			'run, a stamp that does not hold' => ['types' => $one, 'sources' => $three, 'stampFails' => ['r3'], 'call' => $run()],
			'run, no references counted' => ['types' => $one, 'sources' => $three, 'repointed' => 0, 'call' => $run()],
			'run, a record without number, timer or slug' => ['types' => $type([$imp('woo', ['formerReference' => [], 'sourceTimerField' => 'nope'])]), 'sources' => ['woo' => [['id' => 'r9']]], 'call' => $run()],
			'upgrade, only flagged imports' => [
				'types' => $type([$imp('a', ['runOnUpgrade' => true]), $imp('b', ['runOnUpgrade' => 'yes']), $imp('c')]) + ['ct-2' => ['@self' => ['id' => 'ct-2'], 'recordImports' => [$imp('d', ['runOnUpgrade' => true])]]],
				'sources' => ['a' => [$rec('r1')], 'd' => [$rec('r4')]],
				'call' => $upgrade,
			],
			'upgrade, nothing flagged' => ['types' => $one, 'call' => $upgrade],
			'upgrade, as the system' => ['service' => 'system', 'types' => $type([$imp('a', ['runOnUpgrade' => true])]), 'sources' => ['a' => [$rec('r1')]], 'call' => $upgrade],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'case-record-import', value: $observed);
	}//end testGoldenCaseRecordImport()
}//end class
