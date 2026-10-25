<?php

/**
 * A case's deadline is its statutory term's end date; without a term it is
 * startDate plus the case type's term. These cases declare a deadline the
 * boards draw that the fallback does not give, so each carries the statutory
 * term that ends on it (REFRESH-4b read their deadlines as not kept).
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
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Declared deadlines rest on a statutory term.
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
 */
class ZuiddrechtDeclaredDeadlinesHaveTheirTermTest extends TestCase {

	/**
	 * The cases whose declared deadline differs from startDate plus their case type's term.
	 */
	private const CASES = [
		'melding-stoeptegels-kerkplein',
		'kapvergunning-parkstraat',
		'woo-afvalinzameling-oosthaven',
		'woo-dakkapellen-beukenlaan',
		'woo-afgewezen-parkeervergunningen',
	];

	/**
	 * Each has an open or exceeded statutory term, declared after it, that ends on its declared deadline.
	 *
	 * @return void
	 */
	public function testEachDeclaredDeadlineIsTheEndOfAStatutoryTerm(): void {
		$set  = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$keys = array_column($set['objects'], 'key');
		$byKey = array_column($set['objects'], null, 'key');
		foreach (self::CASES as $case) {
			$terms = array_filter(
				$set['objects'],
				static fn (array $o): bool => $o['schema'] === 'deadlineInstance' && ($o['data']['case'] ?? '') === '{{object:'.$case.'.id}}'
			);
			$this->assertCount(expectedCount: 1, haystack: $terms, message: $case);
			$term = array_values($terms)[0];
			$this->assertSame(expected: 'statutory', actual: $term['data']['kind'], message: $case);
			$this->assertNotSame(expected: 'completed', actual: $term['data']['status'], message: $case);
			$this->assertSame(expected: $byKey[$case]['data']['deadline'], actual: $term['data']['endDateCurrent'], message: $case);
			$this->assertGreaterThan(expected: array_search($case, $keys, true), actual: array_search($term['key'], $keys, true), message: $case);
		}
	}//end testEachDeclaredDeadlineIsTheEndOfAStatutoryTerm()
}//end class
