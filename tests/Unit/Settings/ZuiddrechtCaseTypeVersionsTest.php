<?php

/**
 * Example data for DqZaaktype and DqZaaktypeOverzicht: Omgevingsvergunning
 * regulier in five versions (1 to 3 frozen, 4 active, 5 a draft), and the
 * terms the Termijnen card shows (8 weeks, one extension of 6 weeks, a
 * penalty on a late decision, at most 4 weeks of suspension).
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The versions of Omgevingsvergunning regulier.
 */
class ZuiddrechtCaseTypeVersionsTest extends TestCase {

	/**
	 * The Zuiddrecht fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function set(): array {
		return json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
	}//end set()

	/**
	 * The set's versions of the type, keyed by version number.
	 *
	 * @param array<string, mixed> $set The fragment.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function versions(array $set): array {
		$versions = [];
		foreach ($set['objects'] as $object) {
			if ($object['schema'] === 'caseType' && ($object['data']['identifier'] ?? '') === 'omgevingsvergunning-regulier') {
				$versions[$object['data']['version']] = $object;
			}
		}

		ksort($versions);
		return $versions;
	}//end versions()

	/**
	 * Five versions share the identifier, each made from the one before, and
	 * only the fifth is a draft. Version 4 starts as one (the schema default)
	 * and a change publishes it, so its record history shows the publication.
	 *
	 * @return void
	 */
	public function testFiveVersionsInOneChain(): void {
		$set       = $this->set();
		$versions  = $this->versions(set: $set);
		$published = [];
		foreach ($set['changes'] as $change) {
			if (array_key_exists('isDraft', $change['set']) === true) {
				$published[$change['object']] = $change['set']['isDraft'];
			}
		}

		$this->assertSame(expected: [1, 2, 3, 4, 5], actual: array_keys($versions));
		foreach ($versions as $number => $version) {
			$isDraft = ($published[$version['key']] ?? $version['data']['isDraft']);
			$this->assertSame(expected: $number === 5, actual: $isDraft);
			if ($number > 1) {
				$this->assertSame(
					expected: '{{object:'.$versions[$number - 1]['key'].'.id}}',
					actual: $version['data']['previousVersion']
				);
			}
		}
	}//end testFiveVersionsInOneChain()

	/**
	 * Versions 1 to 3 are frozen (an end date and a successor); version 4 is
	 * the active one, with neither.
	 *
	 * @return void
	 */
	public function testOnlyVersionFourIsActive(): void {
		$set        = $this->set();
		$versions   = $this->versions(set: $set);
		$supersedes = [];
		foreach ($set['changes'] as $change) {
			if (isset($change['set']['supersededBy']) === true) {
				$supersedes[$change['object']] = $change['set']['supersededBy'];
			}
		}

		foreach ([1, 2, 3] as $number) {
			$this->assertArrayHasKey(key: 'validUntil', array: $versions[$number]['data']);
			$this->assertSame(
				expected: '{{object:'.$versions[$number + 1]['key'].'.id}}',
				actual: $supersedes[$versions[$number]['key']]
			);
		}

		$this->assertArrayNotHasKey(key: 'validUntil', array: $versions[4]['data']);
		$this->assertArrayNotHasKey(key: $versions[4]['key'], array: $supersedes);
	}//end testOnlyVersionFourIsActive()

	/**
	 * The active version carries the terms the Termijnen card shows.
	 *
	 * @return void
	 */
	public function testTheActiveVersionCarriesTheBoardTerms(): void {
		$data = $this->versions(set: $this->set())[4]['data'];

		$this->assertSame(expected: 'P8W', actual: $data['processingDeadline']);
		$this->assertTrue($data['extensionAllowed']);
		$this->assertSame(expected: 'P6W', actual: $data['extensionPeriod']);
		$this->assertSame(expected: 1, actual: $data['maxExtensions']);
		$this->assertTrue($data['penaltyOnLateDecision']);
		$this->assertTrue($data['suspensionAllowed']);
		$this->assertSame(expected: 28, actual: $data['maxSuspensionDays']);
	}//end testTheActiveVersionCarriesTheBoardTerms()
}//end class
