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
use Psr\Log\LoggerInterface;
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
	 * Schemas whose listing throws.
	 *
	 * @var array<int, string>
	 */
	public array $failingSchemas = [];

	/**
	 * Case ids whose write throws.
	 *
	 * @var array<int, string>
	 */
	public array $failingWrites = [];

	/**
	 * Every error logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public array $errors = [];

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
		$this->failingSchemas = [];
		$this->failingWrites = [];
		$this->errors = [];
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
				if (in_array($schema, $this->test->failingSchemas, true) === true) {
					throw new \RuntimeException('listing ' . $schema . ' failed');
				}

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
				if (in_array($objectId, $this->test->failingWrites, true) === true) {
					throw new \RuntimeException('writing ' . $objectId . ' failed');
				}

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

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);

		return new AssignOpenCasesTheirCaseTypeTeam(settingsService: $settings, handling: new CaseTypeHandling(), logger: $logger);
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

	/**
	 * The run reports how many cases it gave a team.
	 *
	 * @return void
	 */
	public function testTheRunReportsHowManyCasesItFilled(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Case team fill: 2 open case(s) given the handling team of their case type.');

		$this->repair()->run($output);
	}//end testTheRunReportsHowManyCasesItFilled()

	/**
	 * A case type listing that fails writes nothing and logs why.
	 *
	 * @return void
	 */
	public function testAFailingCaseTypeListingWritesNothing(): void {
		$this->failingSchemas = ['caseType'];
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Case team fill: 0 open case(s) given the handling team of their case type.');

		$this->repair()->run($output);

		$this->assertSame(expected: [], actual: $this->patches);
		$this->assertSame(
			expected: [['Dossiq: the case team fill could not list case types', ['app' => 'dossiq', 'exception' => 'listing caseType failed']]],
			actual: $this->errors
		);
	}//end testAFailingCaseTypeListingWritesNothing()

	/**
	 * A case listing that fails skips that case type and logs it.
	 *
	 * @return void
	 */
	public function testAFailingCaseListingSkipsTheCaseType(): void {
		$this->failingSchemas = ['case'];

		$this->repair()->run($this->createMock(IOutput::class));

		$this->assertSame(expected: [], actual: $this->patches);
		$this->assertSame(
			expected: [['Dossiq: the case team fill could not list cases', ['app' => 'dossiq', 'caseType' => self::HANDLED, 'exception' => 'listing case failed']]],
			actual: $this->errors
		);
	}//end testAFailingCaseListingSkipsTheCaseType()

	/**
	 * One case that cannot be written is logged and the rest still get their team.
	 *
	 * @return void
	 */
	public function testAFailingWriteDoesNotStopTheRest(): void {
		$this->failingWrites = ['open-no-team'];
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Case team fill: 1 open case(s) given the handling team of their case type.');

		$this->repair()->run($output);

		$this->assertSame(expected: [['case', 'open-empty-team', ['assignedGroup' => 'behandelaars']]], actual: $this->patches);
		$this->assertSame(
			expected: [['Dossiq: the case team fill could not write a case', ['app' => 'dossiq', 'caseId' => 'open-no-team', 'exception' => 'writing open-no-team failed']]],
			actual: $this->errors
		);
	}//end testAFailingWriteDoesNotStopTheRest()

	/**
	 * A case without an id is passed over.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnIdIsPassedOver(): void {
		$this->rows['case'] = [
			'x' => ['caseType' => self::HANDLED, 'isFinalStatus' => false],
			'y' => ['@self' => ['id' => 'y'], 'caseType' => self::HANDLED, 'isFinalStatus' => false],
		];

		$this->repair()->run($this->createMock(IOutput::class));

		$this->assertSame(expected: [['case', 'y', ['assignedGroup' => 'behandelaars']]], actual: $this->patches);
	}//end testACaseWithoutAnIdIsPassedOver()
}//end class
