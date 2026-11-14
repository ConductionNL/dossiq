<?php

/**
 * Example data for DqZaaktype: the regular permit's v4 work process step
 * "Besluit opstellen" has no term, so its card reads the step's description,
 * which the board draws as "Vergunningverlener · mandaat teamleider VTH".
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The v4 decision step's line.
 */
class ZuiddrechtRegulierV4DecisionStepLineTest extends TestCase {

	/**
	 * The step reads role and mandate apart, as the board draws them.
	 *
	 * @return void
	 */
	public function testTheDecisionStepReadsRoleAndMandate(): void {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);
		$objects  = array_column((array)$set['objects'], null, 'key');
		$template = ($objects['werkproces-regulier-v4']['data'] ?? null);
		$this->assertIsArray(actual: $template);
		$found = $this->stepsBySlug(node: $template);
		$this->assertArrayHasKey(key: 'besluit-opstellen', array: $found);
		$this->assertSame(expected: 'Vergunningverlener · mandaat teamleider VTH', actual: $found['besluit-opstellen']['description']);
	}//end testTheDecisionStepReadsRoleAndMandate()

	/**
	 * Every step of the template by its slug.
	 *
	 * @param mixed $node A part of the template.
	 *
	 * @return array<string, array<string, mixed>> The steps.
	 */
	private function stepsBySlug(mixed $node): array {
		if (is_array($node) === false) {
			return [];
		}

		$steps = [];
		if (isset($node['slug'], $node['title']) === true) {
			$steps[(string)$node['slug']] = $node;
		}

		foreach ($node as $child) {
			$steps = array_merge($steps, $this->stepsBySlug(node: $child));
		}

		return $steps;
	}//end stepsBySlug()
}//end class
