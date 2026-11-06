<?php

/**
 * The Zuiddrecht set decides terms in the current quarter.
 *
 * DqTermijnen's "Op tijd beslist" and "Op tijd beslist per zaaktype",
 * DqDoorlooptijd's norm line and the quarter line under the quarterly report
 * read terms that were decided. The set had none, so every one of them read
 * empty. These cases closed recently, each with its statutory term completed,
 * across five case types, one of them late.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/example-set-decided-terms/spec.md#requirement-the-example-set-decides-terms-in-the-current-quarter
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Checks the decided terms of the Zuiddrecht set.
 */
class ZuiddrechtDecidedTermsTest extends TestCase {
	/**
	 * The set's objects by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function objects(): array {
		$decoded = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray($decoded);

		return array_column($decoded['objects'], null, 'key');
	}//end objects()

	/**
	 * The day a `{{date:n}}` or `{{datetime:n hh:mm}}` token names, as n.
	 *
	 * @param string $token The token.
	 *
	 * @return integer
	 */
	private function dayOf(string $token): int {
		$this->assertMatchesRegularExpression('/^\{\{date(time)?:[+-]?\d+( \d\d:\d\d)?\}\}$/', $token);
		preg_match('/:([+-]?\d+)/', $token, $match);

		return (int)$match[1];
	}//end dayOf()

	/**
	 * Every decided term: completed, on a closed case that ended the day the
	 * term was completed, started within the last 25 days, and late only
	 * when completed after its end date.
	 *
	 * @return void
	 */
	public function testTheDecidedTermsCloseTheirCases(): void {
		$objects = $this->objects();
		$decided = array_filter($objects, static fn (array $o): bool => $o['schema'] === 'deadlineInstance' && ($o['data']['status'] ?? '') === 'completed');
		$this->assertGreaterThanOrEqual(8, count($decided));

		$types = [];
		$late = 0;
		foreach ($decided as $key => $term) {
			preg_match('/^\{\{object:(.+)\.id\}\}$/', $term['data']['case'], $match);
			$case = ($objects[$match[1] ?? ''] ?? null);
			$this->assertNotNull($case, $key.' names a case the set does not hold');
			$this->assertSame('Afgehandeld', $case['lookups']['status']['where']['name'], $key);
			$this->assertSame($case['data']['endDate'], $term['data']['voltooiDatum'], $key.': the case closes the day the term is completed');
			$this->assertLessThanOrEqual(25, -$this->dayOf($term['data']['startDate']), $key.' starts inside the current quarter');
			$this->assertSame($case['data']['deadline'], $term['data']['endDateCurrent'], $key);
			if ($this->dayOf($term['data']['voltooiDatum']) > $this->dayOf($term['data']['endDateCurrent'])) {
				$late++;
			}

			$types[$case['lookups']['caseType']['where']['identifier']] = true;
		}

		$this->assertGreaterThanOrEqual(5, count($types), 'decided terms over five case types');
		$this->assertSame(1, $late, 'one term decided late');
	}//end testTheDecidedTermsCloseTheirCases()
}//end class
