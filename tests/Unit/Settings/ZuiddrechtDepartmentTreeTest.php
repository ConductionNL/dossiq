<?php

/**
 * Example data for DqAfdelingen: the Zuiddrecht departments as organisation
 * roles, each naming its Nextcloud group and its parent role, so the
 * departments pane nests them as the board draws.
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
 * @spec openspec/specs/example-set-department-tree/spec.md#requirement-the-zuiddrecht-departments-are-organisation-roles-with-a-group-and-a-parent
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The department tree of the Zuiddrecht set.
 *
 * @spec openspec/specs/example-set-department-tree/spec.md#requirement-the-zuiddrecht-departments-are-organisation-roles-with-a-group-and-a-parent
 */
class ZuiddrechtDepartmentTreeTest extends TestCase {

	/**
	 * The groups the cast declares in OpenRegister's fragment.
	 *
	 * @var array<int, string>
	 */
	private const CAST_GROUPS = ['zuiddrecht-medewerkers', 'zuiddrecht-vergunningen', 'zuiddrecht-sociaal-domein', 'zuiddrecht-woo-en-informatie'];

	/**
	 * Every department node names a group the set makes, and its parent is another node, so the tree is the board's.
	 *
	 * @return void
	 */
	public function testTheDepartmentsNestAsTheBoardDraws(): void {
		$set    = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$groups = array_merge(self::CAST_GROUPS, array_column($set['groups'], 'group'));

		$nodes = [];
		foreach ($set['objects'] as $object) {
			if ($object['schema'] === 'organisatieRol') {
				$nodes['{{object:'.$object['key'].'.id}}'] = $object['data'];
			}
		}

		foreach ($set['changes'] as $change) {
			if ($change['schema'] === 'organisatieRol') {
				$nodes[$change['where']['roleName']] = $change['set'];
			}
		}

		$parentOf = [];
		foreach ($nodes as $ref => $node) {
			$this->assertContains(needle: $node['ncGroupId'], haystack: $groups, message: (string) $ref);
			$name = ($node['roleName'] ?? $ref);
			$parentOf[$name] = null;
			if (isset($node['parentRoleId']) === true) {
				$this->assertArrayHasKey(key: $node['parentRoleId'], array: $nodes, message: $name);
				$parentOf[$name] = $nodes[$node['parentRoleId']]['roleName'];
			}
		}

		$this->assertSame(
			expected: [
				'Gemeente Zuiddrecht'      => null,
				'Fysiek domein'            => 'Gemeente Zuiddrecht',
				'Buitenruimte'             => 'Fysiek domein',
				'Sociaal domein'           => 'Gemeente Zuiddrecht',
				'Jeugdteam'                => 'Sociaal domein',
				'Werk en inkomen'          => 'Sociaal domein',
				'Bedrijfsvoering'          => 'Gemeente Zuiddrecht',
				'Woo en informatie'        => 'Bedrijfsvoering',
				'Team Vergunningen'        => 'Fysiek domein',
				'Team Handhaving'          => 'Fysiek domein',
			],
			actual: $parentOf
		);
	}//end testTheDepartmentsNestAsTheBoardDraws()
}//end class
