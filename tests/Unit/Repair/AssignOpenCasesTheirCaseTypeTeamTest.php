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

use OCA\Dossiq\Repair\AssignOpenCasesTheirCaseTypeTeam;
use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Open cases filed while their case type had no handling block get its team.
 *
 * @spec openspec/specs/case-types/spec.md
 */
class AssignOpenCasesTheirCaseTypeTeamTest extends TestCase {

	private const HANDLED = '3c0f5a00-0000-4000-a000-00000000a001';

	private const UNHANDLED = '3c0f5a00-0000-4000-a000-00000000a002';

	/**
	 * The register: rows by schema, then by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * Every partial write, as [schema, id, changes].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public array $patches = [];

	/**
	 * Seed a case type with a handling team, one without, and their cases.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [
			'caseType' => [
				self::HANDLED => ['id' => self::HANDLED, 'title' => 'Verzoek', 'handling' => ['defaultGroup' => 'behandelaars']],
				self::UNHANDLED => ['id' => self::UNHANDLED, 'title' => 'Melding', 'handling' => null],
			],
			'case' => [
				'open-no-team' => ['id' => 'open-no-team', 'caseType' => self::HANDLED, 'isFinalStatus' => false, 'title' => 'Fietspad'],
				'open-empty-team' => ['id' => 'open-empty-team', 'caseType' => self::HANDLED, 'isFinalStatus' => false, 'assignedGroup' => ''],
				'open-other-team' => ['id' => 'open-other-team', 'caseType' => self::HANDLED, 'isFinalStatus' => false, 'assignedGroup' => 'juristen'],
				'closed-no-team' => ['id' => 'closed-no-team', 'caseType' => self::HANDLED, 'isFinalStatus' => true],
				'unhandled-no-team' => ['id' => 'unhandled-no-team', 'caseType' => self::UNHANDLED, 'isFinalStatus' => false],
			],
		];
		$this->patches = [];
	}//end setUp()

	/**
	 * The repair over this test's register.
	 *
	 * @return AssignOpenCasesTheirCaseTypeTeam The repair.
	 */
	private function repair(): AssignOpenCasesTheirCaseTypeTeam {
		$register = new class ($this) {
			/**
			 * @param AssignOpenCasesTheirCaseTypeTeamTest $test The test holding the rows.
			 */
			public function __construct(private readonly AssignOpenCasesTheirCaseTypeTeamTest $test) {
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

				return array_slice($rows, (int)($filters['_offset'] ?? 0), (int)($filters['_limit'] ?? count($rows)));
			}

			/**
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data     The fields to write.
			 * @param string|null          $register The register.
			 * @param string|null          $schema   The schema.
			 *
			 * @return array<string, mixed> The saved row.
			 */
			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): array {
				$this->test->rows[(string)$schema][$objectId] = array_merge($this->test->rows[(string)$schema][$objectId], $data);
				$this->test->patches[] = [(string)$schema, $objectId, $data];

				return $this->test->rows[(string)$schema][$objectId];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ([
				'register' => 'dossiq',
				'case_type_schema' => 'caseType',
				'case_schema' => 'case',
			][$key] ?? '')
		);

		return new AssignOpenCasesTheirCaseTypeTeam(settingsService: $settings, handling: new CaseTypeHandling(), logger: new NullLogger());
	}//end repair()

	/**
	 * Only open cases without a team, of a case type with a handling group, get it.
	 *
	 * @return void
	 */
	public function testOnlyOpenCasesWithoutATeamGetTheHandlingTeam(): void {
		$this->repair()->run($this->createMock(IOutput::class));

		$this->assertSame(
			expected: [
				['case', 'open-no-team', ['assignedGroup' => 'behandelaars']],
				['case', 'open-empty-team', ['assignedGroup' => 'behandelaars']],
			],
			actual: $this->patches
		);
		$this->assertSame(expected: 'Fietspad', actual: $this->rows['case']['open-no-team']['title'], message: 'the rest of the case is kept');
	}//end testOnlyOpenCasesWithoutATeamGetTheHandlingTeam()

	/**
	 * The controls: a team, a closed case and a case type without handling are left alone.
	 *
	 * @return void
	 */
	public function testCasesOutsideTheRuleAreLeftAlone(): void {
		$this->repair()->run($this->createMock(IOutput::class));

		$this->assertSame(expected: 'juristen', actual: $this->rows['case']['open-other-team']['assignedGroup']);
		$this->assertArrayNotHasKey(key: 'assignedGroup', array: $this->rows['case']['closed-no-team']);
		$this->assertArrayNotHasKey(key: 'assignedGroup', array: $this->rows['case']['unhandled-no-team']);
	}//end testCasesOutsideTheRuleAreLeftAlone()

	/**
	 * A second run writes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunWritesNothing(): void {
		$this->repair()->run($this->createMock(IOutput::class));
		$this->patches = [];

		$this->repair()->run($this->createMock(IOutput::class));

		$this->assertSame(expected: [], actual: $this->patches);
	}//end testASecondRunWritesNothing()

	/**
	 * Cases past the first page are reached too.
	 *
	 * @return void
	 */
	public function testCasesPastTheFirstPageAreReached(): void {
		$this->rows['case'] = [];
		for ($i = 0; $i < 501; $i++) {
			$this->rows['case']['c' . $i] = ['id' => 'c' . $i, 'caseType' => self::HANDLED, 'isFinalStatus' => false];
		}

		$this->repair()->run($this->createMock(IOutput::class));

		$this->assertCount(expectedCount: 501, haystack: $this->patches);
		$this->assertSame(expected: 'behandelaars', actual: $this->rows['case']['c500']['assignedGroup']);
	}//end testCasesPastTheFirstPageAreReached()

	/**
	 * Without OpenRegister nothing is read or written.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingHappens(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('x');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('nothing to do'));

		(new AssignOpenCasesTheirCaseTypeTeam(settingsService: $settings, handling: new CaseTypeHandling(), logger: new NullLogger()))->run($output);

		$this->assertSame(expected: [], actual: $this->patches);
	}//end testWithoutOpenRegisterNothingHappens()
}//end class
