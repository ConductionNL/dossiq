<?php

/**
 * Example data for DqAfdelingen: Pieter Jansen, the functional administrator
 * the boards draw, is sub-admin of the department groups, not a member.
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
 * Pieter may list the department groups.
 */
class ZuiddrechtDepartmentSubAdminTest extends TestCase {

	/**
	 * Every Zuiddrecht department group names Pieter as sub-admin and none makes him a member.
	 *
	 * @return void
	 */
	public function testPieterIsSubAdminNotMember(): void {
		$set    = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$groups = array_filter($set['groups'], static fn (array $group): bool => str_starts_with($group['group'], 'zuiddrecht-'));

		$this->assertCount(expectedCount: 6, haystack: $groups);
		foreach ($groups as $group) {
			$this->assertSame(expected: ['pieter'], actual: $group['subAdmins'], message: $group['group']);
			$this->assertNotContains(needle: 'pieter', haystack: $group['members'], message: $group['group']);
		}
	}//end testPieterIsSubAdminNotMember()
}//end class
