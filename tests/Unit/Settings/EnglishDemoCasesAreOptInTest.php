<?php

/**
 * The English demo cases are optional example data, not part of every install.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * The register (the monolith plus register.d) is imported on every install, so
 * a case in it lands in every case list. The English demo cases live in the
 * example data under demo.d, which loads only when someone asks for it; the
 * case types and statuses they use stay in the register.
 */
class EnglishDemoCasesAreOptInTest extends TestCase {

	/**
	 * Repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * The English demo cases.
	 *
	 * @var array<int, string>
	 */
	private const CASES = [
		'case-bp-dormer-lindenstraat',
		'case-bp-tree-dorpsstraat',
		'case-bp-dormer-lindenlaan',
		'case-bp-extension-2025',
		'case-grant-festival-oranjewijk',
		'case-grant-sportsclub-deeik',
		'case-complaint-noise-marktplein',
		'case-complaint-slow-permit',
		'case-foi-ministerial-correspondence',
		'case-foi-infrastructure-budget',
	];

	/**
	 * The English case types.
	 *
	 * @var array<int, string>
	 */
	private const CASE_TYPES = [
		'building-permit-type',
		'grant-application-type',
		'citizen-complaint-type',
		'foi-request-type',
	];

	/**
	 * The objects of the register every install imports.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function registerObjects(): array {
		$base = json_decode((string)file_get_contents(self::ROOT . '/lib/Settings/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: self::ROOT . '/lib/Settings/register.d');

		return array_values((array)($merged['components']['objects'] ?? []));
	}//end registerObjects()

	/**
	 * The objects of the optional example data.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function exampleObjects(): array {
		$objects = [];
		foreach ((glob(self::ROOT . '/lib/Settings/demo.d/*.json') ?: []) as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			$objects = array_merge($objects, array_values((array)($data['components']['objects'] ?? [])));
		}

		return $objects;
	}//end exampleObjects()

	/**
	 * The slugs of the objects of one schema.
	 *
	 * @param array<int, array<string, mixed>> $objects The objects.
	 * @param string                           $schema  The schema slug.
	 *
	 * @return array<int, string> The slugs.
	 */
	private function slugsOf(array $objects, string $schema): array {
		$slugs = [];
		foreach ($objects as $object) {
			if (($object['@self']['schema'] ?? '') === $schema) {
				$slugs[] = (string)($object['@self']['slug'] ?? '');
			}
		}

		return $slugs;
	}//end slugsOf()

	/**
	 * No English demo case is in the register, and nothing in it points at one.
	 *
	 * @return void
	 */
	public function testTheRegisterShipsNoEnglishDemoCase(): void {
		$objects = $this->registerObjects();

		$this->assertSame([], array_values(array_intersect(self::CASES, $this->slugsOf($objects, 'case'))));

		$encoded = (string)json_encode($objects);
		foreach (self::CASES as $slug) {
			$this->assertStringNotContainsString('"' . $slug . '"', $encoded, $slug . ' is still referenced from the register');
			$this->assertStringNotContainsString('@ref:case:' . $slug . '"', $encoded, $slug . ' is still referenced from the register');
		}
	}//end testTheRegisterShipsNoEnglishDemoCase()

	/**
	 * The case types stay in the register, so every install still counts them.
	 *
	 * @return void
	 */
	public function testTheRegisterKeepsTheEnglishCaseTypes(): void {
		$this->assertSame(self::CASE_TYPES, array_values(array_intersect(self::CASE_TYPES, $this->slugsOf($this->registerObjects(), 'caseType'))));
	}//end testTheRegisterKeepsTheEnglishCaseTypes()

	/**
	 * The example data holds the ten cases, with relative dates and the loader as assignee.
	 *
	 * @return void
	 */
	public function testTheExampleDataHoldsTheEnglishCases(): void {
		$cases = [];
		foreach ($this->exampleObjects() as $object) {
			if (in_array((string)($object['@self']['slug'] ?? ''), self::CASES, true) === true) {
				$cases[(string)$object['@self']['slug']] = $object;
			}
		}

		$this->assertSame(self::CASES, array_keys($cases));
		foreach ($cases as $slug => $case) {
			$this->assertSame('@me', $case['assignee'], $slug);
			$this->assertMatchesRegularExpression('/^@today([+-]\d+d)?$/', $case['startDate'], $slug);
			$this->assertMatchesRegularExpression('/^@today([+-]\d+d)?$/', $case['deadline'], $slug);
			$this->assertArrayNotHasKey('geometry', $case, $slug);
			$this->assertMatchesRegularExpression('/^@ref:caseType:(' . implode('|', self::CASE_TYPES) . ')$/', $case['caseType'], $slug);
			$this->assertMatchesRegularExpression('/^@ref:statusType:[a-z-]+$/', $case['status'], $slug);
		}
	}//end testTheExampleDataHoldsTheEnglishCases()

	/**
	 * Every case type and status an example case names is in the register.
	 *
	 * @return void
	 */
	public function testTheCasesNameTypesTheRegisterShips(): void {
		$register = $this->registerObjects();
		$shipped = [];
		foreach (['caseType', 'statusType'] as $schema) {
			foreach ($this->slugsOf($register, $schema) as $slug) {
				$shipped['@ref:' . $schema . ':' . $slug] = true;
			}
		}

		foreach ($this->exampleObjects() as $object) {
			if (in_array((string)($object['@self']['slug'] ?? ''), self::CASES, true) === false) {
				continue;
			}

			$this->assertArrayHasKey($object['caseType'], $shipped);
			$this->assertArrayHasKey($object['status'], $shipped);
		}
	}//end testTheCasesNameTypesTheRegisterShips()
}//end class
