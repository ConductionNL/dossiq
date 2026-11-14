<?php

/**
 * The aanbesteding package seeds its decision types and its standstill term
 *
 * An award is a decision, and a refusal of a bid is one too; both are decision
 * types of the tender case type. The award only becomes final once the 20-day
 * standstill term has ended, so the package also ships that term for the case
 * type, counted in calendar days.
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
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\CaseDefinition\ShippedPackageRules;
use PHPUnit\Framework\TestCase;

class AanbestedingPackageSeedTest extends TestCase {

	/**
	 * The package directory.
	 */
	private const DIR = __DIR__ . '/../../../lib/Settings/packages/aanbesteding';

	/**
	 * One file of the package, decoded.
	 *
	 * @param string $path The path in the package.
	 *
	 * @return array<string, mixed>
	 */
	private static function json(string $path): array {
		return json_decode((string)file_get_contents(self::DIR . '/' . $path), true, 512, JSON_THROW_ON_ERROR);
	}//end json()

	/**
	 * A schema of the register by key, from the main file or a fragment.
	 *
	 * @param string $key The schema key.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(string $key): array {
		$files = array_merge([__DIR__ . '/../../../lib/Settings/dossiq_register.json'], glob(__DIR__ . '/../../../lib/Settings/register.d/*.json'));
		foreach ($files as $file) {
			$register = json_decode((string)file_get_contents($file), true);
			if (isset($register['components']['schemas'][$key]) === true) {
				return $register['components']['schemas'][$key];
			}
		}

		return [];
	}//end schema()

	public function testThePackagePassesTheShippedPackageRulesWithItsSeed(): void {
		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DIR, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			$files[substr($file->getPathname(), strlen(self::DIR) + 1)] = (string)file_get_contents($file->getPathname());
		}

		$result = (new ShippedPackageRules())->check(['id' => 'aanbesteding', 'files' => $files]);

		$this->assertSame([], $result['errors']);
		$this->assertSame([], $result['warnings']);
		$this->assertTrue($result['valid']);
		$this->assertContains('seed', $result['manifest']['parts']);
		$this->assertSame('1.9.0', $result['manifest']['version']);
	}//end testThePackagePassesTheShippedPackageRulesWithItsSeed()

	public function testTheAwardAndTheRefusalAreDecisionTypesOfTheTenderCaseType(): void {
		$caseType = self::json('case-type.json')['caseType']['id'];
		$seed     = self::json('seed/10-decision-types.json');

		$this->assertSame('decision_type_schema', $seed['schema']);
		$this->assertSame(['Gunningsbeslissing', 'Afwijzingsbeslissing'], array_column($seed['rows'], 'name'));
		$declared = array_keys(self::schema('decisionType')['properties']);
		foreach ($seed['rows'] as $row) {
			$this->assertSame($caseType, $row['caseType']);
			$this->assertSame([$caseType], $row['caseTypes']);
			$this->assertFalse($row['isDraft']);
			$this->assertNotSame('', trim($row['description']));
			$this->assertSame([], array_diff(array_keys($row), array_merge($declared, ['id'])), 'only declared fields');
		}

		$this->assertSame(2, count(array_unique(array_column($seed['rows'], 'id'))));
	}//end testTheAwardAndTheRefusalAreDecisionTypesOfTheTenderCaseType()

	public function testTheStandstillIsTwentyCalendarDaysForTheTenderCaseType(): void {
		$seed   = self::json('seed/20-standstill-term.json');
		$schema = self::schema('deadlineDefinition');

		$this->assertSame('termijn_definitie_schema', $seed['schema']);
		$this->assertCount(1, $seed['rows']);
		$term = $seed['rows'][0];

		$this->assertSame(self::json('case-type.json')['caseType']['identifier'], $term['caseType'], 'bound by the case type slug');
		$this->assertSame('Aw 2012 art. 2.127', $term['legalBasis']);
		$this->assertSame(20, $term['standardDurationDays']);
		$this->assertSame('calendarDays', $term['countingMode']);
		$this->assertFalse($term['rollToWorkingDay']);

		foreach ($schema['required'] as $field) {
			$this->assertArrayHasKey($field, $term, "the term carries required $field");
		}

		$this->assertContains($term['countingMode'], $schema['properties']['countingMode']['enum']);
		$this->assertContains($term['kind'], $schema['properties']['kind']['enum']);
		$this->assertSame([], array_diff(array_keys($term), array_merge(array_keys($schema['properties']), ['id'])), 'only declared fields');
	}//end testTheStandstillIsTwentyCalendarDaysForTheTenderCaseType()
}//end class
