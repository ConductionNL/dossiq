<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Tests
 * @package   OCA\Dossiq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RebindCasesOffTheBasisFlow;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Workflow\BackfilledTemplateLabels;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Open Woo requests on the backfilled basis flow move onto the shipped one.
 *
 * On :8080 open Woo requests are bound to "Woo-verzoek (basis)", the flow the
 * backfill wrote with no guards. A case keeps the flow it was bound to, so
 * shipping the guarded flow alone would leave those cases unguarded.
 *
 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
 */
class RebindCasesOffTheBasisFlowTest extends TestCase {

	private const WOO = '3c0f5a00-0000-4000-a000-00000000a001';

	private const SHIPPED = 'cb967581-3962-5b61-8106-9f75248ffd82';

	private const BASIS = '1eded688-15e5-4de3-94dc-a3ff5bae8d7d';

	private const ADMIN_FLOW = 'a0000000-0000-4000-a000-0000000000ad';

	/**
	 * The register: rows by schema, then by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * Every save, as [schema, id].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public array $saves = [];

	/**
	 * Seed a Woo case type naming the shipped flow, its basis flow and five cases.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [
			'caseType' => [
				self::WOO => ['id' => self::WOO, 'title' => 'Woo-verzoek', 'workflowDefinition' => self::SHIPPED],
			],
			'workflowTemplate' => [
				self::SHIPPED => ['id' => self::SHIPPED, 'caseType' => self::WOO, 'version' => 1, 'description' => 'De behandeling van een Woo-verzoek.'],
				self::BASIS => ['id' => self::BASIS, 'caseType' => self::WOO, 'version' => 1, 'description' => BackfilledTemplateLabels::MARKER],
				self::ADMIN_FLOW => ['id' => self::ADMIN_FLOW, 'caseType' => self::WOO, 'version' => 2, 'description' => 'Door de beheerder gemaakt.'],
			],
			'case' => [
				'open-on-basis' => ['id' => 'open-on-basis', 'caseType' => self::WOO, 'workflowTemplate' => self::BASIS, 'isFinalStatus' => false, 'title' => 'Fietspad'],
				'closed-on-basis' => ['id' => 'closed-on-basis', 'caseType' => self::WOO, 'workflowTemplate' => self::BASIS, 'isFinalStatus' => true],
				'on-admin-flow' => ['id' => 'on-admin-flow', 'caseType' => self::WOO, 'workflowTemplate' => self::ADMIN_FLOW, 'isFinalStatus' => false],
				'unbound' => ['id' => 'unbound', 'caseType' => self::WOO, 'isFinalStatus' => false],
				'on-shipped' => ['id' => 'on-shipped', 'caseType' => self::WOO, 'workflowTemplate' => self::SHIPPED, 'isFinalStatus' => false],
			],
		];
		$this->saves = [];
	}//end setUp()

	/**
	 * The repair over this test's register.
	 *
	 * @return RebindCasesOffTheBasisFlow The repair.
	 */
	private function repair(): RebindCasesOffTheBasisFlow {
		$register = new class ($this) {
			/**
			 * @param RebindCasesOffTheBasisFlowTest $test The test holding the rows.
			 */
			public function __construct(private readonly RebindCasesOffTheBasisFlowTest $test) {
			}

			/**
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The matching rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$rows = array_values($this->test->rows[$schema] ?? []);
				if (isset($filters['caseType']) === true) {
					$rows = array_values(array_filter($rows, static fn (array $row): bool => ($row['caseType'] ?? '') === $filters['caseType']));
				}

				return $rows;
			}

			/**
			 * @param string      $id       The id.
			 * @param array|null  $extend   Unused.
			 * @param bool        $files    Unused.
			 * @param string|null $register The register.
			 * @param string|null $schema   The schema.
			 *
			 * @return array<string, mixed>|null The row.
			 */
			public function find(string $id, ?array $extend = null, bool $files = false, ?string $register = null, ?string $schema = null): ?array {
				return ($this->test->rows[(string)$schema][$id] ?? null);
			}

			/**
			 * @param array<string, mixed> $object   The data.
			 * @param array                $extend   Unused.
			 * @param string|null          $register The register.
			 * @param string|null          $schema   The schema.
			 * @param string|null          $uuid     The id.
			 *
			 * @return array<string, mixed> The saved row.
			 */
			public function saveObject(array $object, array $extend = [], ?string $register = null, ?string $schema = null, ?string $uuid = null): array {
				$this->test->rows[(string)$schema][(string)$uuid] = ['id' => (string)$uuid] + $object;
				$this->test->saves[] = [(string)$schema, (string)$uuid];

				return $this->test->rows[(string)$schema][(string)$uuid];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ([
				'register' => 'dossiq',
				'case_type_schema' => 'caseType',
				'workflow_template_schema' => 'workflowTemplate',
				'case_schema' => 'case',
			][$key] ?? '')
		);

		return new RebindCasesOffTheBasisFlow(settingsService: $settings, logger: new NullLogger());
	}//end repair()

	/**
	 * Only the open case on the basis flow moves, onto the flow the case type names.
	 *
	 * @return void
	 */
	public function testOnlyTheOpenCaseOnTheBasisFlowMoves(): void {
		$this->repair()->run($this->createMock(IOutput::class));

		$this->assertSame(expected: [['case', 'open-on-basis']], actual: $this->saves);
		$moved = $this->rows['case']['open-on-basis'];
		$this->assertSame(expected: self::SHIPPED, actual: $moved['workflowTemplate']);
		$this->assertSame(expected: 1, actual: $moved['workflowVersion']);
		$this->assertSame(expected: 'Fietspad', actual: $moved['title'], message: 'the rest of the case is kept');

		$this->assertSame(expected: self::BASIS, actual: $this->rows['case']['closed-on-basis']['workflowTemplate']);
		$this->assertSame(expected: self::ADMIN_FLOW, actual: $this->rows['case']['on-admin-flow']['workflowTemplate']);
		$this->assertArrayNotHasKey(key: 'workflowTemplate', array: $this->rows['case']['unbound']);
	}//end testOnlyTheOpenCaseOnTheBasisFlowMoves()

	/**
	 * A second run changes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$this->repair()->run($this->createMock(IOutput::class));
		$this->saves = [];

		$this->repair()->run($this->createMock(IOutput::class));

		$this->assertSame(expected: [], actual: $this->saves);
	}//end testASecondRunChangesNothing()

	/**
	 * A case type that names a basis flow itself moves nothing.
	 *
	 * @return void
	 */
	public function testACaseTypeNamingABasisFlowMovesNothing(): void {
		$this->rows['caseType'][self::WOO]['workflowDefinition'] = self::BASIS;

		$this->repair()->run($this->createMock(IOutput::class));

		$this->assertSame(expected: [], actual: $this->saves);
	}//end testACaseTypeNamingABasisFlowMovesNothing()

	/**
	 * A basis flow of another case type is not this case type's to replace.
	 *
	 * @return void
	 */
	public function testABasisFlowOfAnotherCaseTypeIsLeftAlone(): void {
		$this->rows['workflowTemplate'][self::BASIS]['caseType'] = 'another-case-type';

		$this->repair()->run($this->createMock(IOutput::class));

		$this->assertSame(expected: [], actual: $this->saves);
	}//end testABasisFlowOfAnotherCaseTypeIsLeftAlone()
}//end class
