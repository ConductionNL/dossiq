<?php

/**
 * Case Type General Tab Fields Unit Tests
 *
 * Every field the case type General tab writes is a declared property of the
 * caseType schema, or OpenRegister drops it on save (#2592).
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
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * The General tab writes nothing the schema does not declare.
 *
 * 🔴 A CREATE RESPONSE PROVES NOTHING HERE. OpenRegister echoes an undeclared
 * key back in the response to the write and stores it nowhere, so the form
 * reports a save and a fresh read comes back without the value. The keys are
 * read out of GeneralTab.vue rather than listed in this file, so a control
 * added to the tab later is held to the same rule.
 *
 * @covers \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class CaseTypeGeneralTabFieldsTest extends TestCase {

	/**
	 * Keys the General tab writes that are knowingly not declared yet.
	 *
	 * Each needs a decision rather than a declaration: `responsibleUnit` sits
	 * next to an existing `responsible` property it may be meant to fill, and
	 * `keywords` is a comma-separated string in the form where the spec says
	 * `string[]`. Remove a key from here once it is settled.
	 *
	 * @var array<int, string>
	 */
	private const UNDECIDED = ['responsibleUnit', 'keywords'];

	/**
	 * Every key the General tab emits an update for is a caseType property.
	 *
	 * @return void
	 */
	public function testEveryGeneralTabFieldIsDeclared(): void {
		$root = dirname(__DIR__, 3);
		$base = json_decode((string)file_get_contents($root . '/lib/Settings/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $root . '/lib/Settings/register.d');
		$declared = $merged['components']['schemas']['caseType']['properties'];

		$tab = (string)file_get_contents($root . '/src/views/settings/tabs/GeneralTab.vue');
		preg_match_all(pattern: "/\\\$emit\\('update', '([A-Za-z]+)'/", subject: $tab, matches: $found);
		$written = array_values(array_unique($found[1]));

		$this->assertContains(needle: 'serviceTarget', haystack: $written, message: 'the tab is no longer read as expected');

		foreach ($written as $key) {
			if (in_array($key, self::UNDECIDED, true) === true) {
				continue;
			}

			$this->assertArrayHasKey(
				key: $key,
				array: $declared,
				message: "GeneralTab writes '{$key}', which caseType does not declare, so it is dropped on save"
			);
		}
	}//end testEveryGeneralTabFieldIsDeclared()

	/**
	 * The fields #2592 declared are optional strings, as the tab sends them.
	 *
	 * @return void
	 */
	public function testTheNewFieldsAreOptionalStrings(): void {
		$root = dirname(__DIR__, 3);
		$base = json_decode((string)file_get_contents($root . '/lib/Settings/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $root . '/lib/Settings/register.d');
		$caseType = $merged['components']['schemas']['caseType'];

		foreach (['serviceTarget', 'initiatorAction', 'publicationText'] as $key) {
			$this->assertSame(expected: 'string', actual: $caseType['properties'][$key]['type']);
			// Required would refuse every case type that exists today.
			$this->assertNotContains(needle: $key, haystack: ($caseType['required'] ?? []));
		}
	}//end testTheNewFieldsAreOptionalStrings()
}//end class
