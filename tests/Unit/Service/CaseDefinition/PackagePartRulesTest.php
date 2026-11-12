<?php

/**
 * Unit tests for PackagePartRules: each declared part of a case-definition
 * package is checked for its file, its JSON shape and fixed, unique row ids.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\PackagePartRules;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackagePartRules
 *
 * @uses \OCA\Dossiq\Service\CaseDefinition\PackageSeedRules
 * @uses \OCA\Dossiq\Service\CaseDefinition\PackageSettingRules
 */
class PackagePartRulesTest extends TestCase {

	private const ID_A = '0b1e8f5a-3c2d-4e6f-8a9b-1c2d3e4f5a6b';

	private const ID_B = '1c2f9a6b-4d3e-4f70-9bac-2d3e4f5a6b7c';

	private const ID_C = '2d3a0b7c-5e4f-4081-acbd-3e4f5a6b7c8d';

	private PackagePartRules $rules;

	protected function setUp(): void {
		parent::setUp();
		$this->rules = new PackagePartRules();
	}//end setUp()

	/**
	 * A file map holding the given JSON documents.
	 *
	 * @param array<string, mixed> $documents Documents by path.
	 *
	 * @return array<string, string>
	 */
	private function files(array $documents): array {
		return array_map(static fn (mixed $document): string => json_encode($document), $documents);
	}//end files()

	public function testAWellFormedCaseTypePasses(): void {
		$files = $this->files(
			[
				'case-type.json' => [
					'caseType' => ['id' => self::ID_A, 'title' => 'Bezwaar'],
					'statusTypes' => [['id' => self::ID_B], ['id' => strtoupper(self::ID_C)]],
				],
			]
		);

		$this->assertSame([], $this->rules->check(['case-type'], $files));
	}//end testAWellFormedCaseTypePasses()

	public function testCaseTypeShapeCollectionsAndIdsAreChecked(): void {
		$files = $this->files(
			[
				'case-type.json' => [
					'caseType' => [['id' => self::ID_A], ['x']],
					'checklists' => [['id' => self::ID_B]],
					'roleTypes' => [['id' => 'role-1'], 'plain', ['id' => self::ID_A]],
				],
			]
		);

		$this->assertSame(
			[
				'case-type.json: caseType must be one object or a non-empty list of objects.',
				'case-type.json caseType row 1: id must be a fixed UUID, so a second install recognises it.',
				"case-type.json: 'checklists' is not a case type collection.",
				'case-type.json roleTypes row 0: id must be a fixed UUID, so a second install recognises it.',
				'case-type.json roleTypes row 1: id must be a fixed UUID, so a second install recognises it.',
				'case-type.json roleTypes row 2: id ' . self::ID_A . ' is also used in case-type.json caseType.',
			],
			$this->rules->check(['case-type'], $files)
		);
	}//end testCaseTypeShapeCollectionsAndIdsAreChecked()

	public function testAMissingCaseTypeIsRefused(): void {
		$this->assertSame(['case-type.json is missing.'], $this->rules->check(['case-type'], []));
		$this->assertSame(
			['case-type.json: caseType must be one object or a non-empty list of objects.'],
			$this->rules->check(['case-type'], $this->files(['case-type.json' => []]))
		);
	}//end testAMissingCaseTypeIsRefused()

	public function testAFileThatIsNoJsonObjectIsRefused(): void {
		$this->assertSame(
			['case-type.json is not a JSON object: Syntax error'],
			$this->rules->check(['case-type'], ['case-type.json' => '{not json'])
		);
		$this->assertSame(
			['settings.json is not a JSON object: No error'],
			$this->rules->check(['settings'], ['settings.json' => '[1, 2]'])
		);
	}//end testAFileThatIsNoJsonObjectIsRefused()

	public function testFlowsAreRowsInJsonFilesUnderTheFlowsDirectory(): void {
		$this->assertSame(['flows/: the part is declared but holds no .json file.'], $this->rules->check(['flows'], ['flows/readme.txt' => 'x']));

		$files = $this->files(['flows/b.json' => ['id' => self::ID_A], 'flows/a.json' => ['id' => self::ID_A]]);
		$this->assertSame(
			['flows/b.json row 0: id ' . self::ID_A . ' is also used in flows/a.json.'],
			$this->rules->check(['flows'], $files)
		);
	}//end testFlowsAreRowsInJsonFilesUnderTheFlowsDirectory()

	public function testSeedAndExamplesNeedASchemaAndANonEmptyRowList(): void {
		$files = $this->files(
			[
				'seed/ok.json' => ['schema' => 'zaak_schema', 'rows' => [['id' => self::ID_A]]],
				'seed/no-rows.json' => ['schema' => 'zaak_schema', 'rows' => ['id' => self::ID_B]],
				'examples/bad-schema.json' => ['schema' => 'zaak', 'rows' => [['id' => self::ID_C]]],
			]
		);

		$this->assertSame(
			[
				'seed/no-rows.json: rows must be a non-empty list.',
				'examples/bad-schema.json: schema must name a settings key ending in _schema, or another app\'s schema by app, register and slug.',
			],
			$this->rules->check(['seed', 'examples'], $files)
		);
		$this->assertSame(['examples/: the part is declared but holds no .json file.'], $this->rules->check(['examples'], []));
	}//end testSeedAndExamplesNeedASchemaAndANonEmptyRowList()

	public function testSettingsAreCheckedByTheSettingRules(): void {
		$this->assertSame(
			["settings.json: 'Bad' is not a valid settings key (a-z, 0-9, _, at most 64)."],
			$this->rules->check(['settings'], $this->files(['settings.json' => ['Bad' => 'x', 'good' => 1]]))
		);
	}//end testSettingsAreCheckedByTheSettingRules()

	public function testAnUnknownPartIsIgnoredAndEachCheckStartsClean(): void {
		$this->assertSame([], $this->rules->check(['translations'], []));

		$files = $this->files(['flows/a.json' => ['id' => self::ID_A]]);
		$this->assertSame([], $this->rules->check(['flows'], $files));
		$this->assertSame([], $this->rules->check(['flows'], $files));
	}//end testAnUnknownPartIsIgnoredAndEachCheckStartsClean()
}//end class
