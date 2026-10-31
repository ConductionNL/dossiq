<?php

/**
 * A change to a case is validated as the case would be written. OpenRegister
 * reads a geometry kept as JSON text back as a structure, so a change to a
 * case that has one names it in `jsonFields`, or the check refuses the set
 * ("Property 'geometry' should be type 'string or null' but is 'object'").
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
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Changes keep a case's JSON-text geometry as text.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
 */
class ZuiddrechtChangesKeepJsonTextTest extends TestCase {

	/**
	 * Every change to a declared case with a geometry lists geometry as a JSON-text field.
	 *
	 * @return void
	 */
	public function testAChangeToACaseWithAGeometryKeepsItAsText(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');
		$missing = [];
		foreach ($set['changes'] as $change) {
			$target = ($objects[$change['object'] ?? ''] ?? null);
			if ($target === null || $target['schema'] !== 'case' || isset($target['data']['geometry']) === false) {
				continue;
			}

			if (in_array('geometry', (array)($change['jsonFields'] ?? []), true) === false) {
				$missing[] = $change['key'];
			}
		}

		$this->assertSame(expected: [], actual: $missing);
	}//end testAChangeToACaseWithAGeometryKeepsItAsText()
}//end class
