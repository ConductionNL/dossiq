<?php

/**
 * A package's example cases pass the special-category rule of their case type
 *
 * A case type that sets `requiresSpecialCategory` refuses a case without a
 * `gdprClassification` (SpecialCategoryClassification). A package's example
 * case is a case like any other, so one that lacks the classification makes
 * the whole package install fail on an instance that opted into example data,
 * and takes every example-set object that refers to it down with it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-package-reinstall-applies-changed-content/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Intake\SpecialCategoryClassification;
use PHPUnit\Framework\TestCase;

/**
 * Every example case of a shipped package passes its case type's special-category rule.
 */
class PackageExampleCasesAreClassifiedTest extends TestCase {

	/**
	 * The packages directory.
	 */
	private const PACKAGES = __DIR__ . '/../../../lib/Settings/packages/';

	/**
	 * One file, decoded.
	 *
	 * @param string $path The file.
	 *
	 * @return array<string, mixed>
	 */
	private static function json(string $path): array {
		return (array)json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	}//end json()

	/**
	 * Every example case row with the case type it names, by package.
	 *
	 * @return array<int, array{package: string, row: array<string, mixed>, caseType: array<string, mixed>}>
	 */
	private static function exampleCases(): array {
		$cases = [];
		foreach ((array)glob(self::PACKAGES . '*/case-type.json') as $file) {
			$package  = basename(dirname((string)$file));
			$declared = self::json(path: (string)$file)['caseType'] ?? [];
			$types    = [];
			$declared = (array)$declared;
			if (array_is_list($declared) === false) {
				$declared = [$declared];
			}

			foreach ($declared as $caseType) {
				$types[(string)($caseType['id'] ?? '')] = (array)$caseType;
			}

			foreach ((array)glob(dirname((string)$file) . '/examples/*.json') as $example) {
				$data = self::json(path: (string)$example);
				if (($data['schema'] ?? null) !== 'case_schema') {
					continue;
				}

				foreach ((array)($data['rows'] ?? []) as $row) {
					$caseType = ($types[(string)($row['caseType'] ?? '')] ?? null);
					if ($caseType !== null) {
						$cases[] = ['package' => $package, 'row' => (array)$row, 'caseType' => $caseType];
					}
				}
			}
		}

		return $cases;
	}//end exampleCases()

	/**
	 * Every example case of a case type that requires a classification carries one it allows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-package-reinstall-applies-changed-content/tasks.md
	 */
	public function testEveryExampleCasePassesItsCaseTypesSpecialCategoryRule(): void {
		$cases = self::exampleCases();
		$checked = array_filter(
			array: $cases,
			callback: fn (array $case): bool => (($case['caseType']['requiresSpecialCategory'] ?? false) === true)
		);
		self::assertNotSame(
			expected: [],
			actual: $checked,
			message: 'jeugdwet ships a classified example case; finding none means this test reads the packages wrongly.'
		);

		$rule = new SpecialCategoryClassification();
		foreach ($cases as $case) {
			try {
				$rule->assertCreatable(case: $case['row'], caseType: $case['caseType']);
			} catch (RefusedException $e) {
				self::fail(
					message: "Package {$case['package']}, example case \"" . ($case['row']['title'] ?? '') . '": ' . $e->getMessage()
				);
			}
		}

		$this->addToAssertionCount(count: count($cases));
	}//end testEveryExampleCasePassesItsCaseTypesSpecialCategoryRule()
}//end class
