<?php

/**
 * Golden test for the workflow-definition backfill repair step.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MigrateWorkflowDefinitions;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Workflow\BackfilledTemplateLabels;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use OCA\Dossiq\Service\WorkflowDefinitionService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Service\Object\ObjectPatch;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every store call (with its system scope), every log and output line
 * of the backfill over a store holding every kind of case type.
 *
 * @covers \OCA\Dossiq\Repair\MigrateWorkflowDefinitions
 * @covers \OCA\Dossiq\Service\Workflow\BackfillSchemas
 * @covers \OCA\Dossiq\Service\Workflow\BackfillTarget
 * @covers \OCA\Dossiq\Service\Workflow\BackfillCaseType
 * @uses \OCA\Dossiq\Service\Workflow\BackfilledTemplateLabels
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses \OCA\Dossiq\Service\Workflow\BackfilledTemplateShape
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard
 */
class MigrateWorkflowDefinitionsGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every call, log line and output line, in order.
	 *
	 * @var \ArrayObject<int, mixed>
	 */
	private \ArrayObject $trace;

	/**
	 * A status row of a case type.
	 *
	 * @param string $id       The id.
	 * @param string $caseType The case type.
	 * @param int    $order    The order.
	 *
	 * @return array<string, mixed>
	 */
	private static function statusRow(string $id, string $caseType, int $order): array {
		return ['id' => $id, 'caseType' => $caseType, 'order' => $order, 'name' => ['nl' => 'Status ' . $id], 'description' => 'Over ' . $id];
	}//end statusRow()

	/**
	 * A basis template as the old backfill stored it, for a case type.
	 *
	 * @param string $id       The id.
	 * @param string $caseType The case type.
	 *
	 * @return array<string, mixed>
	 */
	private static function broken(string $id, string $caseType): array {
		return [
			'id' => $id,
			'title' => 'Array - basis',
			'description' => BackfilledTemplateLabels::MARKER,
			'caseType' => $caseType,
			'lifecycleStatus' => 'published',
			'steps' => json_encode([['id' => 'step-1', 'title' => 'Array', 'description' => 'Array', 'status' => $caseType . '-s1'], ['id' => 'step-2', 'title' => 'Array', 'status' => '']]),
			'transitions' => json_encode([['id' => 'move-1', 'fromStatus' => $caseType . '-s1', 'toStatus' => $caseType . '-s2', 'label' => 'Array']]),
		];
	}//end broken()

	/**
	 * The store: rows by schema, and the stored versions by case type.
	 *
	 * @return array{rows: array<string, array<int, mixed>>, versions: array<string, array<int, array<string, mixed>>>}
	 */
	private static function world(): array {
		return [
			'rows' => [
				'28' => [
					'not a row',
					['title' => 'no id'],
					['id' => 'ct-new', 'title' => ['nl' => 'Nieuw'], '@self' => ['x' => 1]],
					['id' => 'ct-pinned', 'title' => 'Vast', 'workflowDefinition' => 'tpl-x'],
					['id' => 'ct-heal', 'title' => ['nl' => 'Heel']],
					['id' => 'ct-drafts', 'title' => 'Concept'],
					['id' => 'ct-none'],
					['id' => 'ct-noid-status', 'title' => 'Geen id'],
					['id' => 'ct-statusfail'],
					['id' => 'ct-savefail'],
					['id' => 'ct-anon'],
					['id' => 'ct-pinfail', 'title' => 'Pin faalt'],
					['id' => 'ct-casefail'],
					['id' => 'ct-healfail'],
					['id' => 'ct-patchfail'],
					['id' => 'ct-pinned-only', 'workflowDefinition' => 'tpl-elsewhere'],
					['id' => 'ct-published'],
					['id' => 'ct-noid-tpl'],
				],
				'29' => [
					self::statusRow(id: 'ct-new-s2', caseType: 'ct-new', order: 2),
					self::statusRow(id: 'ct-new-s1', caseType: 'ct-new', order: 1),
					['id' => 'ct-new-s3', 'caseType' => 'ct-new', 'name' => 'Zonder volgorde'],
					self::statusRow(id: 'ct-heal-s1', caseType: 'ct-heal', order: 1),
					self::statusRow(id: 'ct-heal-s2', caseType: 'ct-heal', order: 2),
					'not a status',
					self::statusRow(id: 'ct-drafts-s1', caseType: 'ct-drafts', order: 1),
					['caseType' => 'ct-noid-status', 'order' => 1],
					self::statusRow(id: 'ct-savefail-s1', caseType: 'ct-savefail', order: 1),
					self::statusRow(id: 'ct-anon-s1', caseType: 'ct-anon', order: 1),
					self::statusRow(id: 'ct-pinfail-s1', caseType: 'ct-pinfail', order: 1),
					self::statusRow(id: 'ct-casefail-s1', caseType: 'ct-casefail', order: 1),
					['caseType' => 'ct-heal', 'name' => 'Geen id'],
					self::statusRow(id: 'ct-pinned-only-s1', caseType: 'ct-pinned-only', order: 1),
					self::statusRow(id: 'ct-published-s1', caseType: 'ct-published', order: 1),
					self::statusRow(id: 'ct-noid-tpl-s1', caseType: 'ct-noid-tpl', order: 1),
				],
				'35' => [
					['id' => 'case-open', 'caseType' => 'ct-new', 'title' => 'Open', '@self' => ['y' => 2]],
					['id' => 'case-pinned', 'caseType' => 'ct-new', 'workflowTemplate' => 'tpl-old'],
					['caseType' => 'ct-new', 'title' => 'no id'],
					['id' => 'case-fail', 'caseType' => 'ct-new'],
					['id' => 'case-drafts', 'caseType' => 'ct-drafts', 'workflowTemplate' => ''],
					['id' => 'case-pinfail', 'caseType' => 'ct-pinfail'],
				],
			],
			'versions' => [
				'ct-pinned' => [self::broken(id: 'tpl-x', caseType: 'ct-pinned'), ['title' => 'no id', 'description' => 'Array']],
				'ct-heal' => [
					self::broken(id: 'tpl-heal', caseType: 'ct-heal'),
					['id' => 'tpl-other', 'title' => 'Eigen', 'lifecycleStatus' => 'published'],
					['id' => 'tpl-array-not-ours', 'title' => 'Array', 'description' => 'Somebody', 'lifecycleStatus' => 'deprecated'],
				],
				'ct-drafts' => [['id' => 'tpl-draft', 'title' => 'Concept', 'lifecycleStatus' => 'draft', 'isDraft' => true]],
				'ct-healfail' => [self::broken(id: 'tpl-healfail', caseType: 'ct-healfail')],
				'ct-patchfail' => [self::broken(id: 'tpl-patchfail', caseType: 'ct-patchfail')],
				'ct-published' => [['id' => 'tpl-pub', 'title' => 'Eigen', 'lifecycleStatus' => 'published']],
				'ct-noid-tpl' => [array_diff_key(self::broken(id: 'x', caseType: 'ct-noid-tpl'), ['id' => true])],
			],
		];
	}//end world()

	/**
	 * Run the step and answer what it did.
	 *
	 * @param array<string, string> $config Config changes.
	 * @param array<int, string>    $fails  The calls that throw: `search:<schema>[:<caseType>]`, `save:<schema>:<uuid|caseType>`, `patch:<id>`.
	 * @param array<string, mixed>  $setup  `available` and `service` switches.
	 *
	 * @return array<int, mixed>
	 */
	private function observe(array $config = [], array $fails = [], array $setup = []): array {
		$this->trace = new \ArrayObject();
		$world = self::world();
		$store = new class($world['rows'], $fails, $this->trace) {
			/** @var int How deep inside runAsSystem the store is. */
			private int $system = 0;

			/**
			 * @param array<string, array<int, mixed>> $rows  Rows by schema.
			 * @param array<int, string>               $fails The calls that throw.
			 * @param \ArrayObject<int, mixed>         $trace The trace.
			 */
			public function __construct(private array $rows, private array $fails, private \ArrayObject $trace) {
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->trace->append(['system', 'enter']);
				$this->system++;
				try {
					return $operation();
				} finally {
					$this->system--;
					$this->trace->append(['system', 'leave']);
				}
			}

			/**
			 * @param string $key The call key.
			 *
			 * @return void
			 */
			private function maybeFail(string $key): void {
				if (in_array($key, $this->fails, true) === true) {
					throw new RuntimeException('store refused ' . $key);
				}
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, mixed>
			 */
			public function searchObjects(array $query): array {
				$schema = (string)($query['@self']['schema'] ?? '');
				$caseType = ($query['caseType'] ?? null);
				$this->trace->append(['search', $this->system, $query]);
				$this->maybeFail(key: 'search:' . $schema);
				$this->maybeFail(key: 'search:' . $schema . ':' . (string)$caseType);

				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static fn (mixed $row): bool => $caseType === null || (is_array($row) === true && ($row['caseType'] ?? null) === $caseType) || is_string($row) === true
					)
				);
			}

			/**
			 * @param array<string, mixed> $object   The payload.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				$this->trace->append(['save', $this->system, $register, $schema, $uuid, $object]);
				$this->maybeFail(key: 'save:' . $schema . ':' . ($uuid ?? (string)($object['caseType'] ?? '')));
				if ($uuid === null && ($object['caseType'] ?? '') === 'ct-anon') {
					return $object;
				}

				return array_merge($object, ['id' => ($uuid ?? 'tpl-new-' . (string)($object['caseType'] ?? ''))]);
			}

			/**
			 * @param string               $objectId The uuid.
			 * @param array<string, mixed> $data     The fields.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(ObjectPatch $patch): array {
				$objectId = $patch->objectId;
				$data = $patch->data;
				$register = $patch->target->register;
				$schema = $patch->target->schema;
				$this->trace->append(['patch', $this->system, $register, $schema, $objectId, $data]);
				$this->maybeFail(key: 'patch:' . $objectId);
				return $data;
			}
		};

		$values = array_merge(
			['register' => '20', 'case_type_schema' => '28', 'status_type_schema' => '29', 'workflow_template_schema' => '53', 'case_schema' => '35'],
			$config
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($setup['available'] ?? true);
		$settings->method('getObjectService')->willReturn(($setup['service'] ?? true) === true ? $store : null);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($values[$key] ?? ''));

		$versions = $world['versions'];
		$workflow = $this->createMock(WorkflowDefinitionService::class);
		$workflow->method('listVersions')->willReturnCallback(
			function (string $caseTypeId) use ($versions): array {
				$this->trace->append(['listVersions', $caseTypeId]);
				return ($versions[$caseTypeId] ?? []);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$output = $this->createMock(IOutput::class);
		foreach (['error', 'warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->trace->append(['log', $level, $message, $context]);
				}
			);
			if ($level !== 'error') {
				$output->method($level)->willReturnCallback(
					function (string $message) use ($level): void {
						$this->trace->append(['output', $level, $message]);
					}
				);
			}
		}

		$step = new MigrateWorkflowDefinitions(
			settingsService: $settings,
			workflowService: $workflow,
			logger: $logger,
			lifecycle: new WorkflowLifecycleGuard($this->createMock(WorkflowDefinitionRepository::class), $this->createMock(LoggerInterface::class)),
		);
		$step->run($output);

		// Step and transition ids are random uuids: pin their shape, not their value.
		$json = (string)preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/', '<uuid>', json_encode($this->trace->getArrayCopy(), JSON_THROW_ON_ERROR));

		return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
	}//end observe()

	/**
	 * Every path of the backfill.
	 *
	 * @return void
	 */
	public function testGoldenBackfill(): void {
		$healFail = ['search:29:ct-healfail', 'patch:tpl-patchfail', 'search:29:ct-statusfail', 'save:53:ct-savefail', 'save:28:ct-pinfail', 'save:35:case-fail', 'search:35:ct-casefail'];
		$observed = [
			'no openregister' => $this->observe(setup: ['available' => false]),
			'no object service' => $this->observe(setup: ['service' => false]),
			'no register' => $this->observe(config: ['register' => '']),
			'no case type schema' => $this->observe(config: ['case_type_schema' => '']),
			'no status schema' => $this->observe(config: ['status_type_schema' => '']),
			'no template schema' => $this->observe(config: ['workflow_template_schema' => '']),
			'case types cannot be listed' => $this->observe(fails: ['search:28']),
			'every case type' => $this->observe(fails: $healFail),
			'every case type, no case schema' => $this->observe(config: ['case_schema' => ''], fails: $healFail),
			'every case type, nothing fails' => $this->observe(),
		];

		$this->assertMatchesGolden('migrate-workflow-definitions', $observed);
	}//end testGoldenBackfill()
}//end class
