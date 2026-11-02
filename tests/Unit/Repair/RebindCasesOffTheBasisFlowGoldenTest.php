<?php

/**
 * Golden test for the basis flow rebind repair.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RebindCasesOffTheBasisFlow;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Workflow\BackfilledTemplateLabels;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every read, write, log line and output line of the rebind, per world.
 *
 * @covers \OCA\Dossiq\Repair\RebindCasesOffTheBasisFlow
 * @uses \OCA\Dossiq\Repair\FlowRebindRun
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 *
 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
 */
class RebindCasesOffTheBasisFlowGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	private const TYPE_A = 'type-a';

	private const TYPE_B = 'type-b';

	/**
	 * The calls, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $log = [];

	/**
	 * The worlds: the rows by schema, and which calls fail.
	 *
	 * @return array<string, array{0: array<string, array<string, array<string, mixed>>>, 1: list<string>}>
	 */
	public static function worlds(): array {
		$world = self::world();

		$noTarget = $world;
		unset($noTarget['workflowTemplate']['flow-a']);

		$basisTarget = $world;
		$basisTarget['caseType'][self::TYPE_A]['workflowDefinition'] = 'basis-a';

		$selfId = $world;
		$selfId['caseType'][self::TYPE_B] = ['@self' => ['id' => self::TYPE_B], 'workflowDefinition' => ['id' => 'flow-b']];

		return [
			'two case types' => [$world, []],
			'a named flow that is gone' => [$noTarget, []],
			'a case type naming a basis flow' => [$basisTarget, []],
			'ids under @self and a reference object' => [$selfId, []],
			'case types cannot be listed' => [$world, ['search:caseType']],
			'cases cannot be listed' => [$world, ['search:case']],
			'a template cannot be read' => [$world, ['find:basis-a']],
			'a case cannot be saved' => [$world, ['save:a-open']],
			'nothing seeded' => [['caseType' => [], 'workflowTemplate' => [], 'case' => []], []],
		];
	}//end worlds()

	/**
	 * The seeded register: two case types, their flows and their cases.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private static function world(): array {
		$basis = BackfilledTemplateLabels::MARKER;

		return [
			'caseType' => [
				self::TYPE_A => ['id' => self::TYPE_A, 'workflowDefinition' => 'flow-a'],
				self::TYPE_B => ['id' => self::TYPE_B, 'workflowDefinition' => 'flow-b'],
				'type-c' => ['id' => 'type-c', 'workflowDefinition' => ''],
				'type-d' => ['id' => 'type-d', 'workflowDefinition' => 'flow-a'],
				'' => ['workflowDefinition' => 'flow-a'],
			],
			'workflowTemplate' => [
				'flow-a' => ['id' => 'flow-a', 'caseType' => self::TYPE_A, 'version' => 3, 'description' => 'Shipped A'],
				'basis-a' => ['id' => 'basis-a', 'caseType' => self::TYPE_A, 'version' => 1, 'description' => $basis],
				'flow-b' => ['@self' => ['id' => 'flow-b'], 'caseType' => self::TYPE_B, 'description' => 'Shipped B'],
				'basis-b' => ['id' => 'basis-b', 'caseType' => ['id' => self::TYPE_B], 'version' => 1, 'description' => $basis],
				'basis-other' => ['id' => 'basis-other', 'caseType' => 'type-z', 'version' => 1, 'description' => $basis],
			],
			'case' => [
				'a-open' => ['id' => 'a-open', 'caseType' => self::TYPE_A, 'workflowTemplate' => 'basis-a', 'isFinalStatus' => false, 'title' => 'A1'],
				'a-open-2' => ['@self' => ['id' => 'a-open-2'], 'caseType' => self::TYPE_A, 'workflowTemplate' => ['id' => 'basis-a'], 'title' => 'A2'],
				'a-closed' => ['id' => 'a-closed', 'caseType' => self::TYPE_A, 'workflowTemplate' => 'basis-a', 'isFinalStatus' => true],
				'a-shipped' => ['id' => 'a-shipped', 'caseType' => self::TYPE_A, 'workflowTemplate' => 'flow-a'],
				'a-foreign-basis' => ['id' => 'a-foreign-basis', 'caseType' => self::TYPE_A, 'workflowTemplate' => 'basis-other'],
				'a-missing-flow' => ['id' => 'a-missing-flow', 'caseType' => self::TYPE_A, 'workflowTemplate' => 'gone'],
				'b-open' => ['id' => 'b-open', 'caseType' => self::TYPE_B, 'workflowTemplate' => 'basis-b'],
				'b-unbound' => ['id' => 'b-unbound', 'caseType' => self::TYPE_B],
				'b-on-the-flow-of-a' => ['id' => 'b-on-the-flow-of-a', 'caseType' => self::TYPE_B, 'workflowTemplate' => 'flow-a'],
			],
		];
	}//end world()

	/**
	 * The calls, the writes and the output match the recording.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $rows  The rows by schema, then by id.
	 * @param list<string>                                       $fails The calls that throw: `search:<schema>`, `find:<id>`, `save:<id>`.
	 *
	 * @dataProvider worlds
	 *
	 * @return void
	 */
	public function testTheRebindMatchesTheRecording(array $rows, array $fails): void {
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->log[] = ['info' => $message];
			}
		);

		$this->repair(rows: $rows, fails: $fails)->run(output: $output);

		$name = trim(strtolower((string)preg_replace('/[^a-z]+/i', '-', $this->dataName())), '-');
		$this->assertMatchesGolden(name: 'rebind-cases-off-the-basis-flow/' . $name, value: $this->log);
	}//end testTheRebindMatchesTheRecording()

	/**
	 * The repair over a recording register.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $rows  The rows.
	 * @param list<string>                                       $fails The calls that throw.
	 *
	 * @return RebindCasesOffTheBasisFlow
	 */
	private function repair(array $rows, array $fails): RebindCasesOffTheBasisFlow {
		$register = new class ($this, $rows, $fails) {
			/**
			 * Constructor.
			 *
			 * @param RebindCasesOffTheBasisFlowGoldenTest                $test  The test keeping the log.
			 * @param array<string, array<string, array<string, mixed>>> $rows  The rows.
			 * @param list<string>                                        $fails The calls that throw.
			 */
			public function __construct(
				private readonly RebindCasesOffTheBasisFlowGoldenTest $test,
				private readonly array $rows,
				private readonly array $fails,
			) {
			}

			/**
			 * Search by slug.
			 *
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The matching rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->test->log[] = ['search' => ['register' => $register, 'schema' => $schema, 'filters' => $filters]];
				if (in_array('search:' . $schema, $this->fails, true) === true) {
					throw new RuntimeException('search ' . $schema . ' failed');
				}

				$rows = array_values($this->rows[$schema] ?? []);
				if (isset($filters['caseType']) === true) {
					$rows = array_values(array_filter($rows, static fn (array $row): bool => ($row['caseType'] ?? '') === $filters['caseType']));
				}

				return $rows;
			}

			/**
			 * Find one row.
			 *
			 * @param string      $id       The id.
			 * @param array|null  $extend   Unused.
			 * @param bool        $files    Unused.
			 * @param string|null $register The register.
			 * @param string|null $schema   The schema.
			 *
			 * @return array<string, mixed>|null The row.
			 */
			public function find(string $id, ?array $extend = null, bool $files = false, ?string $register = null, ?string $schema = null): ?array {
				$this->test->log[] = ['find' => ['id' => $id, 'register' => $register, 'schema' => $schema]];
				if (in_array('find:' . $id, $this->fails, true) === true) {
					throw new RuntimeException('find ' . $id . ' failed');
				}

				return ($this->rows[(string)$schema][$id] ?? null);
			}

			/**
			 * Save one row.
			 *
			 * @param array<string, mixed> $object   The data.
			 * @param array                $extend   Unused.
			 * @param string|null          $register The register.
			 * @param string|null          $schema   The schema.
			 * @param string|null          $uuid     The id.
			 *
			 * @return array<string, mixed> The saved row.
			 */
			public function saveObject(array $object, array $extend = [], ?string $register = null, ?string $schema = null, ?string $uuid = null): array {
				$this->test->log[] = ['save' => ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid]];
				if (in_array('save:' . $uuid, $this->fails, true) === true) {
					throw new RuntimeException('save ' . $uuid . ' failed');
				}

				return ['id' => (string)$uuid] + $object;
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => [
				'register' => 'dossiq',
				'case_type_schema' => 'caseType',
				'workflow_template_schema' => 'workflowTemplate',
				'case_schema' => 'case',
			][$key] ?? ''
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->log[] = ['error' => $message, 'context' => $context];
			}
		);

		return new RebindCasesOffTheBasisFlow(settingsService: $settings, logger: $logger);
	}//end repair()
}//end class
