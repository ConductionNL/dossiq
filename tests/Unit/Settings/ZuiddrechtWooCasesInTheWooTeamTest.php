<?php

/**
 * Example data for the Woo team's queue and the case boards: every open
 * Woo request of the Zuiddrecht set sits in the team Woo en informatie.
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
 * @spec openspec/changes/example-set-woo-team/specs/example-set-woo-team/spec.md#requirement-open-woo-requests-sit-in-the-woo-team
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Woo team owns the open Woo requests.
 *
 * @spec openspec/changes/example-set-woo-team/specs/example-set-woo-team/spec.md#requirement-open-woo-requests-sit-in-the-woo-team
 */
class ZuiddrechtWooCasesInTheWooTeamTest extends TestCase {

	/**
	 * Open Woo requests still with the KCC agent who took them in (the request a new one duplicates).
	 */
	private const AT_INTAKE = ['woo-kosten-zwembad'];

	/**
	 * No open Woo request of the set is left in the generic `behandelaars` group.
	 *
	 * @return void
	 */
	public function testOpenWooRequestsSitInTheWooTeam(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$wrong = [];
		foreach ($set['objects'] as $object) {
			$type = ($object['lookups']['caseType']['where']['identifier'] ?? '');
			if ($object['schema'] !== 'case' || $type !== 'woo-verzoek' || isset($object['data']['endDate']) === true
				|| in_array($object['key'], self::AT_INTAKE, true) === true
			) {
				continue;
			}

			if (($object['data']['assignedGroup'] ?? '') !== 'zuiddrecht-woo-en-informatie') {
				$wrong[] = $object['key'].' ('.($object['data']['assignedGroup'] ?? 'none').')';
			}
		}

		$this->assertSame(expected: [], actual: $wrong);
	}//end testOpenWooRequestsSitInTheWooTeam()
}//end class
