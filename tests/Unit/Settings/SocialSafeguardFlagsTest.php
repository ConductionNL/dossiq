<?php

/**
 * The safeguard flags a social domain case type sets, and the fields its
 * anonymised export would strip.
 *
 * @category Test
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
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The case type declares the four safeguards, and the four social packages
 * switch consent and classification on with identifying fields that exist.
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
class SocialSafeguardFlagsTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * A JSON file of the repository, decoded.
	 *
	 * @param string $path The path from the root.
	 *
	 * @return array<string, mixed>
	 */
	private function json(string $path): array {
		$decoded = json_decode((string)file_get_contents($this->root().'/'.$path), true);
		$this->assertIsArray(actual: $decoded, message: $path.' is not JSON');

		return $decoded;
	}//end json()

	/**
	 * Every property the case schema declares across the base register and its fragments.
	 *
	 * @return array<int, string>
	 */
	private function caseProperties(): array {
		$files = array_merge(
			[$this->root().'/lib/Settings/dossiq_register.json'],
			glob($this->root().'/lib/Settings/register.d/*.json')
		);
		$names = [];
		foreach ($files as $file) {
			$decoded = json_decode((string)file_get_contents($file), true);
			$schema  = $decoded['components']['schemas']['case']['properties'] ?? [];
			$names   = array_merge($names, array_keys((array)$schema));
		}

		return array_values(array_unique($names));
	}//end caseProperties()

	/**
	 * The four social packages.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function socialPackages(): array {
		return [
			'jeugdwet'             => ['jeugdwet'],
			'wmo-melding'          => ['wmo-melding'],
			'bijstandsaanvraag'    => ['bijstandsaanvraag'],
			're-integratietraject' => ['re-integratietraject'],
		];
	}//end socialPackages()

	/**
	 * The case type schema declares the four safeguards, off unless a type says otherwise.
	 *
	 * @return void
	 */
	public function testTheCaseTypeDeclaresTheFourSafeguards(): void {
		$caseType   = $this->json(path: 'lib/Settings/register.d/99-case-type-social-safeguards.json')['components']['schemas']['caseType'];
		$properties = $caseType['properties'];

		$this->assertSame(expected: '1.48.0', actual: $caseType['version']);
		foreach (['requiresConsentForSharing', 'requiresSpecialCategory', 'handlingTeamOnly'] as $flag) {
			$this->assertSame(expected: 'boolean', actual: $properties[$flag]['type'], message: $flag);
			$this->assertFalse(condition: $properties[$flag]['default'], message: $flag.' is on by default');
		}

		$this->assertSame(expected: 'array', actual: $properties['identifyingFields']['type']);
		$this->assertSame(expected: 'string', actual: $properties['identifyingFields']['items']['type']);
	}//end testTheCaseTypeDeclaresTheFourSafeguards()

	/**
	 * A social package asks for consent and a classification, and names its identifying fields.
	 *
	 * @param string $package The package id.
	 *
	 * @return void
	 */
	#[DataProvider('socialPackages')]
	public function testASocialPackageSwitchesConsentAndClassificationOn(string $package): void {
		$caseType = $this->json(path: 'lib/Settings/packages/'.$package.'/case-type.json')['caseType'];

		$this->assertTrue(condition: $caseType['requiresConsentForSharing']);
		$this->assertTrue(condition: $caseType['requiresSpecialCategory']);
		$this->assertContains(needle: 'subjectBsn', haystack: $caseType['identifyingFields']);
		$this->assertContains(needle: 'requester', haystack: $caseType['identifyingFields']);
		// Team-only reading is not enforced yet, so no package claims it.
		$this->assertArrayNotHasKey(key: 'handlingTeamOnly', array: $caseType);
	}//end testASocialPackageSwitchesConsentAndClassificationOn()

	/**
	 * Every identifying field names a case property or one of the package's own fields.
	 *
	 * A misspelt name would leave that field in an anonymised export, so a
	 * name nothing declares fails here rather than in a researcher's file.
	 *
	 * @param string $package The package id.
	 *
	 * @return void
	 */
	#[DataProvider('socialPackages')]
	public function testEveryIdentifyingFieldExists(string $package): void {
		$definition = $this->json(path: 'lib/Settings/packages/'.$package.'/case-type.json');
		$known      = array_merge(
			$this->caseProperties(),
			array_column((array)($definition['propertyDefinitions'] ?? []), 'name')
		);

		$unknown = array_values(array_diff($definition['caseType']['identifyingFields'], $known));

		$this->assertSame(expected: [], actual: $unknown, message: $package.' names fields nothing declares');
	}//end testEveryIdentifyingFieldExists()
}//end class
