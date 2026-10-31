<?php

/**
 * Example data for DqMijnTeam "Wachtrij van uw team": three open Woo requests
 * in Woo en informatie with no handler, so Pieter's team queue is not empty.
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
 * The Woo team's queue.
 */
class ZuiddrechtWooTeamQueueTest extends TestCase {

	/**
	 * Three open, unassigned Woo requests sit with Woo en informatie; the team's assigned requests are not in its queue.
	 *
	 * @return void
	 */
	public function testTheWooTeamHasThreeUnassignedRequests(): void {
		$set    = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$queued = [];
		foreach ($set['objects'] as $object) {
			if ($object['schema'] !== 'case' || ($object['data']['assignedGroup'] ?? '') !== 'zuiddrecht-woo-en-informatie'
				|| isset($object['data']['assignee']) === true
			) {
				continue;
			}

			$this->assertArrayNotHasKey(key: 'endDate', array: $object['data'], message: $object['key']);
			$this->assertSame(expected: 'woo-verzoek', actual: $object['lookups']['caseType']['where']['identifier'], message: $object['key']);
			$queued[] = $object['data']['title'];
		}

		$this->assertSame(
			expected: ['Afvalinzameling Oosthaven 2025', 'Vergunningen dakkapellen Beukenlaan', 'Afgewezen parkeervergunningen 2025'],
			actual: $queued
		);
	}//end testTheWooTeamHasThreeUnassignedRequests()
}//end class
