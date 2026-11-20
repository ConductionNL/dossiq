<?php

/**
 * Every case the Zuiddrecht set links to or changes is a case the set itself
 * writes, so `openregister:example-set:install zuiddrecht` does not depend on
 * rows only case_flow_seed_data or a package's opt-in examples create.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 * @spec exclude example set data; no requirement covers which fragment writes an example case
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The set's case links and case changes resolve within the set.
 */
class ZuiddrechtCasesAreTheSetsOwnTest extends TestCase {

	/**
	 * The uuid of the Jeugdwet package's opt-in example case.
	 *
	 * @var string
	 */
	private const PACKAGE_EXAMPLE_CASE = 'e0b306d2-c523-5938-9b5c-3474d292dc01';

	/**
	 * The decoded set file.
	 *
	 * @return array<string, mixed>
	 */
	private function set(): array {
		return json_decode(json: (string)file_get_contents(filename: __DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), associative: true);
	}//end set()

	/**
	 * The keys of the set's case objects, in the order the set writes them.
	 *
	 * @param array<string, mixed> $set The set.
	 *
	 * @return array<int, string>
	 */
	private function caseKeys(array $set): array {
		$cases = array_filter(array: $set['objects'], callback: static fn (array $object): bool => $object['schema'] === 'case');
		return array_values(array: array_column(array: $cases, column_key: 'key'));
	}//end caseKeys()

	/**
	 * No object looks a case up: a case comes from `{{object:...}}` of one the set writes earlier.
	 *
	 * @return void
	 */
	public function testNoObjectLooksUpACase(): void {
		$set  = $this->set();
		$keys = array_column(array: $set['objects'], column_key: 'key');
		foreach ($set['objects'] as $position => $object) {
			foreach ((array)($object['lookups'] ?? []) as $name => $lookup) {
				$this->assertNotSame(expected: 'case', actual: $lookup['schema'], message: $object['key'].' looks up a case by "'.$name.'"');
			}

			$case = ($object['data']['case'] ?? null);
			if (is_string(value: $case) === false || $case === '') {
				continue;
			}

			$this->assertSame(
				expected: 1,
				actual: preg_match(pattern: '/^\{\{object:([a-z0-9-]+)\.id\}\}$/', subject: $case, matches: $match),
				message: $object['key'].' names its case as '.$case
			);
			$this->assertContains(needle: $match[1], haystack: $this->caseKeys(set: $set), message: $object['key'].' names a case the set does not write');
			$this->assertLessThan(
				expected: $position,
				actual: array_search(needle: $match[1], haystack: $keys, strict: true),
				message: $object['key'].' is written before its case'
			);
		}
	}//end testNoObjectLooksUpACase()

	/**
	 * Every change on a case targets a case object of the set, not a row found by title.
	 *
	 * @return void
	 */
	public function testEveryCaseChangeTargetsACaseOfTheSet(): void {
		$set   = $this->set();
		$cases = $this->caseKeys(set: $set);
		foreach ($set['changes'] as $change) {
			if (($change['schema'] ?? '') !== 'case') {
				continue;
			}

			$this->assertArrayNotHasKey(key: 'where', array: $change, message: $change['key'].' finds its case by a where');
			$this->assertContains(needle: $change['object'], haystack: $cases, message: $change['key'].' changes a case the set does not write');
		}
	}//end testEveryCaseChangeTargetsACaseOfTheSet()

	/**
	 * The Jeugdwet case and the Molenweg 3 shed are written by the set, on case types the packages ship.
	 *
	 * @return void
	 */
	public function testTheTwoFormerlyMissingCasesAreDeclared(): void {
		$set     = $this->set();
		$objects = array_column(array: $set['objects'], column_key: null, index_key: 'key');

		$jeugdwet = $objects['jeugdwet-gezin-oosthaven'];
		$this->assertSame(expected: 'Melding Jeugdwet gezin Oosthaven', actual: $jeugdwet['data']['title']);
		$this->assertSame(expected: ['identifier' => 'melding-jeugdwet'], actual: $jeugdwet['lookups']['caseType']['where']);
		$this->assertSame(expected: 'Onderzoek', actual: $jeugdwet['lookups']['status']['where']['name']);

		$shed = $objects['schuur-molenweg-3'];
		$this->assertSame(expected: ['identifier' => 'omgevingsvergunning'], actual: $shed['lookups']['caseType']['where']);
		$this->assertSame(expected: '{{object:schuur-molenweg-3.id}}', actual: $objects['lhs-advies-molenweg']['data']['case']);
	}//end testTheTwoFormerlyMissingCasesAreDeclared()

	/**
	 * Nothing in the set quotes the package's opt-in example case by its uuid.
	 *
	 * @return void
	 */
	public function testThePackageExampleCaseIsNotQuoted(): void {
		$raw = (string)file_get_contents(filename: __DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json');
		$this->assertStringNotContainsString(needle: self::PACKAGE_EXAMPLE_CASE, haystack: $raw);
	}//end testThePackageExampleCaseIsNotQuoted()
}//end class
