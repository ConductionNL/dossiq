<?php

/**
 * Example data for DqDashboard and DqMijnTeam "Vastgelopen zaken": of the
 * set's open cases, only Kapvergunning essen Parkweg has been idle for more
 * than a week.
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
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The set's stalled cases.
 */
class ZuiddrechtOneStalledCaseTest extends TestCase {

	/**
	 * Days since the last activity of every open case in the set, by key.
	 *
	 * @return array<string, int>
	 */
	private function idleDays(): array {
		$dir   = __DIR__.'/../../../lib/Settings/example-sets/';
		$files = array_merge([$dir.'zuiddrecht.json'], (glob($dir.'zuiddrecht.d/*.json') ?: []));

		$idle = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			$this->assertIsArray(actual: $data, message: $file);
			foreach (($data['objects'] ?? []) as $object) {
				$case = ($object['data'] ?? []);
				if (($object['schema'] ?? '') !== 'case' || isset($case['endDate']) === true) {
					continue;
				}

				if (preg_match('/^\{\{datetime:(-?\d+) /', (string)($case['lastActivityAt'] ?? ''), $match) === 1) {
					$idle[$object['key']] = -(int)$match[1];
				}
			}
		}

		return $idle;
	}//end idleDays()

	/**
	 * Kapvergunning essen Parkweg, 8 days idle, is the one open case idle for
	 * more than a week, as both boards draw it.
	 *
	 * @return void
	 */
	public function testOnlyTheParkwegCaseIsIdleForMoreThanAWeek(): void {
		$stalled = array_filter($this->idleDays(), static fn (int $days): bool => $days > 7);

		$this->assertSame(expected: ['kapvergunning-essen-parkweg' => 8], actual: $stalled);
	}//end testOnlyTheParkwegCaseIsIdleForMoreThanAWeek()
}//end class
