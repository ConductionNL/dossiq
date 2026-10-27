<?php

/**
 * Example data for DqTeamwachtrij: the team queue of Woo en informatie.
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
 * The queue holds five cases without a handler, oldest six days.
 */
class ZuiddrechtTeamQueueTest extends TestCase {

	/**
	 * Every object of the set, the main file and its parts.
	 *
	 * @return array<string, array<string, mixed>> The objects by key.
	 */
	private function objects(): array {
		$folder  = __DIR__.'/../../../lib/Settings/example-sets';
		$parts = glob($folder.'/zuiddrecht.d/*.json');
		if ($parts === false) {
			$parts = [];
		}

		$files   = array_merge([$folder.'/zuiddrecht.json'], $parts);
		$objects = [];
		foreach ($files as $file) {
			$part = json_decode((string)file_get_contents($file), true);
			foreach (($part['objects'] ?? []) as $object) {
				$objects[$object['key']] = $object;
			}
		}

		return $objects;
	}//end objects()

	/**
	 * The day offset a `{{date:n}}` or `{{datetime:n hh:mm}}` token names.
	 *
	 * @param string $token The token.
	 *
	 * @return int The offset in days.
	 */
	private function dayOf(string $token): int {
		$this->assertMatchesRegularExpression(pattern: '/^\{\{date(time)?:-?\d+( \d\d:\d\d)?\}\}$/', string: $token);
		preg_match('/:(-?\d+)/', $token, $match);
		return (int)$match[1];
	}//end dayOf()

	/**
	 * The open Woo en informatie cases without a handler wait 6, 5, 4, 2 and 1
	 * days, and their terms end in 4, 6, 6, 26 and 9 days.
	 *
	 * @return void
	 */
	public function testTheQueueWaitsAsTheBoardDraws(): void {
		$objects = $this->objects();
		$terms   = [];
		foreach ($objects as $object) {
			if ($object['schema'] === 'deadlineInstance') {
				$terms[$object['data']['case']] = $object['data'];
			}
		}

		$queue = [];
		foreach ($objects as $key => $object) {
			$data = $object['data'] ?? [];
			if ($object['schema'] !== 'case' || ($data['assignedGroup'] ?? '') !== 'zuiddrecht-woo-en-informatie'
				|| isset($data['assignee']) === true || isset($data['endDate']) === true
			) {
				continue;
			}

			$term        = $terms['{{object:'.$key.'.id}}'];
			$queue[$key] = [$this->dayOf(token: $data['startDate']), $this->dayOf(token: $term['endDateCurrent'])];
		}

		asort($queue);
		$this->assertSame(
			expected: [
				'woo-afvalinzameling-oosthaven'             => [-6, 4],
				'woo-dakkapellen-beukenlaan'                => [-5, 6],
				'woo-afgewezen-parkeervergunningen'         => [-4, 6],
				'woo-kosten-nieuw-zwembad'                  => [-2, 26],
				'melding-losliggende-stoeptegels-kerkplein' => [-1, 9],
			],
			actual: $queue
		);
	}//end testTheQueueWaitsAsTheBoardDraws()

	/**
	 * Each of Pieter's open cases in this fragment has a running term, so its
	 * tier comes from the set rather than from the case type's default.
	 *
	 * @return void
	 */
	public function testPietersCasesHaveATerm(): void {
		$objects = $this->objects();
		$termed  = [];
		foreach ($objects as $object) {
			if ($object['schema'] === 'deadlineInstance' && $object['data']['status'] === 'lopend') {
				$termed[] = $object['data']['case'];
			}
		}

		foreach ($objects as $key => $object) {
			$data = $object['data'] ?? [];
			if ($object['schema'] === 'case' && ($data['assignee'] ?? '') === '{{cast:pieter.uid}}' && isset($data['endDate']) === false) {
				$this->assertContains(needle: '{{object:'.$key.'.id}}', haystack: $termed, message: $key);
			}
		}
	}//end testPietersCasesHaveATerm()
}//end class
