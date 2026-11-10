<?php

/**
 * CaseSplitExecutor golden test
 *
 * Pins what a split reads, writes, logs and answers: the moves, the party on
 * both halves, the record on the original, every refusal, and every read or
 * write that fails part way.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Cases\CaseSplitExecutor;
use OCA\Dossiq\Service\Cases\CaseSplitPlan;
use OCA\Dossiq\Service\Cases\CaseSplitPolicy;
use OCA\Dossiq\Service\Cases\CaseSplitStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseMoveLedger;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\SideEffectDispatcher;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden test for CaseSplitExecutor.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseSplitExecutor
 * @uses \OCA\Dossiq\Service\Cases\CaseSplitPlan
 * @uses \OCA\Dossiq\Service\Cases\CaseSplitPolicy
 * @uses \OCA\Dossiq\Service\Cases\CaseSplitStore
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Transitions\CaseMoveLedger
 * @uses \OCA\Dossiq\Service\Transitions\CaseStatusStore
 * @uses \OCA\Dossiq\Service\Support\TranslatedText
 */
class CaseSplitExecutorGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * A register that records every call and fails where a world says so.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $rows  Rows by schema and id.
	 * @param array<string, mixed>                               $knobs Failures: findThrows, searchThrows, leaky, saveThrows, saveAnswers.
	 *
	 * @return object The register.
	 */
	private static function register(array $rows, array $knobs): object {
		return new class($rows, $knobs) {
			/**
			 * Every call, in order.
			 *
			 * @var array<int, mixed>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, array<string, array<string, mixed>>> $rows  Rows by schema and id.
			 * @param array<string, mixed>                               $knobs The failures.
			 */
			public function __construct(public array $rows, private array $knobs) {
			}

			/**
			 * Find one row.
			 *
			 * @param int|string $id       The id.
			 * @param mixed      $_extend  Ignored.
			 * @param bool       $files    Ignored.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return array<string, mixed>|null The row.
			 */
			public function find(int|string $id, ?ObjectFindOptions $options = null): ?array {
				$register = ($options?->register ?? '');
				$schema = ($options?->schema ?? '');
				$this->calls[] = ['find', ['id' => $id, 'register' => $register, 'schema' => $schema]];
				if (in_array($schema, ($this->knobs['findThrows'] ?? []), true) === true) {
					throw new RuntimeException('find failed on ' . $schema);
				}

				return ($this->rows[$schema][$id] ?? null);
			}

			/**
			 * Search by slug.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->calls[] = ['search', ['register' => $register, 'schema' => $schema, 'filters' => $filters]];
				if (in_array($schema, ($this->knobs['searchThrows'] ?? []), true) === true) {
					throw new RuntimeException('search failed on ' . $schema);
				}

				$leaky = in_array($schema, ($this->knobs['leaky'] ?? []), true);
				$found = [];
				foreach (($this->rows[$schema] ?? []) as $row) {
					if ($leaky === true || ($row['case'] ?? null) === ($filters['case'] ?? null)) {
						$found[] = $row;
					}
				}

				return $found;
			}

			/**
			 * Save one row.
			 *
			 * @param array<string, mixed> $object   The row.
			 * @param int|string           $register The register.
			 * @param int|string           $schema   The schema.
			 * @param string|null          $uuid     The id to update, or null.
			 *
			 * @return mixed The stored row, or what the world answers.
			 */
			public function saveObject(array $object, int|string $register = '', int|string $schema = '', ?string $uuid = null): mixed {
				$this->calls[] = ['save', ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid]];
				$key = $schema . '|' . ($uuid ?? 'new');
				if (in_array($key, ($this->knobs['saveThrows'] ?? []), true) === true) {
					throw new RuntimeException('save failed on ' . $key);
				}

				if (array_key_exists($key, ($this->knobs['saveAnswers'] ?? [])) === true) {
					return $this->knobs['saveAnswers'][$key];
				}

				$id = ($uuid ?? ($object['id'] ?? ('new-' . (count($this->rows[$schema] ?? []) + 1))));
				$object['id'] = $id;
				$this->rows[$schema][$id] = $object;

				return $object;
			}
		};
	}//end register()

	/**
	 * The rows every world starts from.
	 *
	 * @return array<string, array<string, array<string, mixed>>> Rows by schema and id.
	 */
	private static function rows(): array {
		return [
			'caseType' => ['ct-1' => ['id' => 'ct-1', 'title' => 'Melding']],
			'case' => [
				'case-1' => [
					'id' => 'case-1',
					'title' => 'Twee klachten',
					'caseType' => 'ct-1',
					'identifier' => 'ZAAK-1',
					'status' => 's-1',
					'assignedGroup' => 'team',
					'assignee' => 'anna',
					'urgency' => 'high',
					'@self' => ['id' => 'case-1'],
				],
			],
			'caseDocument' => [
				'doc-1' => ['id' => 'doc-1', 'case' => 'case-1', 'title' => 'Brief'],
				'doc-2' => ['id' => ' doc-2 ', 'case' => 'case-1', 'name' => 'Foto'],
				'doc-3' => ['uuid' => 'doc-3', 'case' => 'case-1'],
				'doc-4' => ['id' => 'doc-4', 'case' => 'case-1', 'title' => 'Bijlage', '@self' => ['x' => 1]],
				'doc-9' => ['id' => 'doc-9', 'case' => 'case-2', 'title' => 'Andermans'],
			],
			'role' => [
				'role-1' => ['id' => 'role-1', 'case' => 'case-1', 'roleType' => 'belanghebbende', 'name' => 'Piet'],
				'role-2' => ['id' => 'role-2', 'case' => 'case-1', 'roleType' => 'initiator', 'name' => 'Klaas'],
			],
		];
	}//end rows()

	/**
	 * The status engine's write step over the world's register; no status is final.
	 *
	 * @param SettingsService $settings The world's settings.
	 * @param AbstractLogger  $logger   The world's logger.
	 *
	 * @return CaseMoveLedger The ledger.
	 */
	private function ledger(SettingsService $settings, AbstractLogger $logger): CaseMoveLedger {
		$results = $this->createMock(originalClassName: CaseResultWriter::class);
		$results->method('isFinalStatus')->willReturn(false);

		return new CaseMoveLedger(
			store: new CaseStatusStore(
				settingsService: $settings,
				statusTypeLookup: $this->createMock(originalClassName: StatusTypeLookup::class),
				logger: $logger,
			),
			resultWriter: $results,
			dispatcher: $this->createMock(originalClassName: SideEffectDispatcher::class),
			logger: $logger,
		);
	}//end ledger()

	/**
	 * Run one call against a world and record every effect.
	 *
	 * @param array<string, mixed> $world The rows, knobs, settings and the call.
	 *
	 * @return array<string, mixed> The answer or refusal, calls, logs and the rows after.
	 */
	private function observe(array $world): array {
		$rows = self::rows();
		foreach (($world['seed'] ?? []) as $schema => $byId) {
			foreach ($byId as $id => $row) {
				$rows[$schema][$id] = $row;
			}
		}

		$register = self::register(rows: $rows, knobs: ($world['knobs'] ?? []));
		$map = ($world['config'] ?? []) + [
			'register' => 'dossiq',
			'case_schema' => 'case',
			'case_type_schema' => 'caseType',
			'case_document_schema' => 'caseDocument',
			'role_schema' => 'role',
			'status_record_schema' => 'statusRecord',
		];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(($world['noService'] ?? false) === true ? null : $register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (string)($map[$key] ?? $default)
		);

		$logger = new class extends AbstractLogger {
			/**
			 * Every log line.
			 *
			 * @var array<int, mixed>
			 */
			public array $lines = [];

			/**
			 * Record one line.
			 *
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, $context];
			}
		};

		$executor = new CaseSplitExecutor(
			policy: new CaseSplitPolicy(),
			plan: new CaseSplitPlan(),
			logger: $logger,
			store: new CaseSplitStore(settingsService: $settings, ledger: $this->ledger(settings: $settings, logger: $logger)),
		);

		try {
			$answer = ($world['call'])($executor);
		} catch (RefusedException $refused) {
			$answer = [
				'refused' => $refused->getRule(),
				'sentence' => $refused->getSentence(),
				'status' => $refused->getStatus(),
				'message' => $refused->getMessage(),
				'previous' => ($refused->getPrevious()?->getMessage()),
			];
		}

		$observed = ['answer' => $answer, 'calls' => $register->calls, 'logs' => $logger->lines, 'rows' => $register->rows];
		$json = (string)json_encode($observed, JSON_PRESERVE_ZERO_FRACTION);

		return json_decode(
			(string)preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d/', '<now>', $json),
			true
		);
	}//end observe()

	/**
	 * A split of case-1 with this selection and title.
	 *
	 * @param array<string, mixed> $chosen The selection.
	 * @param string               $title  The new title.
	 * @param string               $caseId The case.
	 *
	 * @return \Closure The call.
	 */
	private static function split(array $chosen, string $title = 'De tweede', string $caseId = 'case-1'): \Closure {
		return static fn (CaseSplitExecutor $executor): array => $executor->split(
			caseId: $caseId,
			title: $title,
			chosen: $chosen,
			actor: 'jan',
		);
	}//end split()

	/**
	 * Every split and parts question across its guards and its failures.
	 *
	 * @return void
	 */
	public function testGoldenCaseSplitExecutor(): void {
		$docs = ['documents' => ['doc-1', ' doc-2 ', 'doc-3']];
		$worlds = [
			'documents and a party move' => ['call' => self::split(chosen: ['documents' => ['doc-1', 'doc-4'], 'parties' => ['role-1']])],
			'ids in every shape' => ['call' => self::split(chosen: $docs + ['parties' => ['', '  ', 'role-2', 7]])],
			'one party on both, one document' => ['call' => self::split(chosen: ['documents' => ['doc-1'], 'partiesOnBoth' => ['role-1', 'role-x']])],
			'only parties on both' => ['call' => self::split(chosen: ['partiesOnBoth' => ['role-2']])],
			'party moved and on both' => ['call' => self::split(chosen: ['parties' => ['role-1'], 'partiesOnBoth' => ['role-1']])],
			'nothing chosen' => ['call' => self::split(chosen: [])],
			'only unknown ids' => ['call' => self::split(chosen: ['documents' => ['doc-x'], 'partiesOnBoth' => ['role-x']])],
			'only blank ids' => ['call' => self::split(chosen: ['documents' => ['', ' ']])],
			'a document of another case' => ['call' => self::split(chosen: ['documents' => ['doc-1', 'doc-9']])],
			'a leaky search hands back another case' => [
				'knobs' => ['leaky' => ['caseDocument']],
				'call' => self::split(chosen: ['documents' => ['doc-1', 'doc-9']]),
			],
			'only another case on a leaky search' => [
				'knobs' => ['leaky' => ['caseDocument']],
				'call' => self::split(chosen: ['documents' => ['doc-9']]),
			],
			'a task' => ['call' => self::split(chosen: ['tasks' => ['task-1']])],
			'a task and a document' => ['call' => self::split(chosen: ['documents' => ['doc-1'], 'tasks' => ['task-1']])],
			'blank task ids' => ['call' => self::split(chosen: ['documents' => ['doc-1'], 'tasks' => ['', ' ']])],
			'case type forbids documents' => [
				'seed' => ['caseType' => ['ct-1' => ['id' => 'ct-1', 'splittableParts' => ['parties']]]],
				'call' => self::split(chosen: ['documents' => ['doc-1'], 'parties' => ['role-1']]),
			],
			'case type allows parties' => [
				'seed' => ['caseType' => ['ct-1' => ['id' => 'ct-1', 'splittableParts' => ['parties']]]],
				'call' => self::split(chosen: ['parties' => ['role-1']]),
			],
			'case type forbids everything' => [
				'seed' => ['caseType' => ['ct-1' => ['id' => 'ct-1', 'splittableParts' => []]]],
				'call' => self::split(chosen: ['parties' => ['role-1']]),
			],
			'case type allows tasks' => [
				'seed' => ['caseType' => ['ct-1' => ['id' => 'ct-1', 'splittableParts' => ['tasks', 'documents']]]],
				'call' => self::split(chosen: ['documents' => ['doc-1'], 'tasks' => ['t']]),
			],
			'case type unreadable' => [
				'knobs' => ['findThrows' => ['caseType']],
				'call' => self::split(chosen: ['documents' => ['doc-1']]),
			],
			'case type missing' => [
				'seed' => ['caseType' => ['ct-1' => null]],
				'call' => self::split(chosen: ['documents' => ['doc-1']]),
			],
			'case without a case type' => [
				'seed' => ['case' => ['case-1' => ['id' => 'case-1', 'title' => 'Kaal', 'caseType' => '  ']]],
				'call' => self::split(chosen: ['documents' => ['doc-1']], title: '  '),
			],
			'blank title, no identifier, no fields' => [
				'seed' => ['case' => ['case-1' => ['uuid' => 'case-1']]],
				'call' => self::split(chosen: ['documents' => ['doc-1']], title: ''),
			],
			'case id with spaces' => ['call' => self::split(chosen: ['documents' => ['doc-1']], caseId: '  case-1 ')],
			'case missing' => ['call' => self::split(chosen: ['documents' => ['doc-1']], caseId: 'case-404')],
			'case unreadable' => [
				'knobs' => ['findThrows' => ['case']],
				'call' => self::split(chosen: ['documents' => ['doc-1']]),
			],
			'no object service' => ['noService' => true, 'call' => self::split(chosen: ['documents' => ['doc-1']])],
			'document schema not configured' => [
				'config' => ['case_document_schema' => ''],
				'call' => self::split(chosen: ['documents' => ['doc-1'], 'parties' => ['role-1']]),
			],
			'document search fails' => [
				'knobs' => ['searchThrows' => ['caseDocument']],
				'call' => self::split(chosen: ['documents' => ['doc-1'], 'parties' => ['role-1']]),
			],
			'every search fails' => [
				'knobs' => ['searchThrows' => ['caseDocument', 'role']],
				'call' => self::split(chosen: ['documents' => ['doc-1'], 'partiesOnBoth' => ['role-1']]),
			],
			'a document move fails' => [
				'knobs' => ['saveThrows' => ['caseDocument|doc-1']],
				'call' => self::split(chosen: ['documents' => ['doc-1', 'doc-4']]),
			],
			'the second case cannot be written' => [
				'knobs' => ['saveThrows' => ['case|new']],
				'call' => self::split(chosen: ['documents' => ['doc-1']]),
			],
			'the second case answers nothing' => [
				'knobs' => ['saveAnswers' => ['case|new' => 'nope']],
				'call' => self::split(chosen: ['documents' => ['doc-1']]),
			],
			'the second case answers without an id' => [
				'knobs' => ['saveAnswers' => ['case|new' => ['title' => 'x', 'identifier' => '  ']]],
				'call' => self::split(chosen: ['documents' => ['doc-1'], 'partiesOnBoth' => ['role-1']]),
			],
			'the second case answers a serialisable object' => [
				'knobs' => ['saveAnswers' => ['case|new' => new class implements \JsonSerializable {
					/**
					 * The stored case.
					 *
					 * @return array<string, string> The case.
					 */
					public function jsonSerialize(): array {
						return ['id' => 'new-js', 'identifier' => 'ZAAK-9'];
					}
				}]],
				'call' => self::split(chosen: ['documents' => ['doc-1']]),
			],
			'the record on the original fails' => [
				'knobs' => ['saveThrows' => ['case|case-1']],
				'call' => self::split(chosen: ['documents' => ['doc-1']]),
			],
			'the party copy fails' => [
				'knobs' => ['saveThrows' => ['role|new']],
				'call' => self::split(chosen: ['documents' => ['doc-1'], 'partiesOnBoth' => ['role-1', 'role-2']]),
			],
			'earlier moved items are kept' => [
				'seed' => ['case' => ['case-1' => ['id' => 'case-1', 'caseType' => 'ct-1', 'splitMovedItems' => [['id' => 'old-1'], 'junk', ['id' => 'old-2'], null], 'splitInto' => 'case-0']]],
				'call' => self::split(chosen: ['documents' => ['doc-1'], 'parties' => ['role-2']]),
			],
			'moved items that are not a list' => [
				'seed' => ['case' => ['case-1' => ['id' => 'case-1', 'caseType' => 'ct-1', 'splitMovedItems' => 'text']]],
				'call' => self::split(chosen: ['documents' => ['doc-1']]),
			],
			'parts of a case' => ['call' => static fn (CaseSplitExecutor $executor): array => $executor->divisibleParts(caseId: ' case-1 ')],
			'parts when the type limits them' => [
				'seed' => ['caseType' => ['ct-1' => ['id' => 'ct-1', 'splittableParts' => ['documents']]]],
				'call' => static fn (CaseSplitExecutor $executor): array => $executor->divisibleParts(caseId: 'case-1'),
			],
			'parts when the type is unreadable' => [
				'knobs' => ['findThrows' => ['caseType']],
				'call' => static fn (CaseSplitExecutor $executor): array => $executor->divisibleParts(caseId: 'case-1'),
			],
			'parts of a missing case' => ['call' => static fn (CaseSplitExecutor $executor): array => $executor->divisibleParts(caseId: 'nope')],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'case-split-executor', value: $observed);
	}//end testGoldenCaseSplitExecutor()
}//end class
