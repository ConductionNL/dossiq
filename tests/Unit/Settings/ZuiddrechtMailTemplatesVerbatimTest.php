<?php

/**
 * The Zuiddrecht mail templates carry the template's own placeholders
 * ({{zaakNummer}}, {{contactNaam}}, ...). The example-set installer fills
 * `{{kind:...}}` tokens in object data, so the templates name the fields that
 * hold their own placeholders in `verbatimFields`, or the set is refused.
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
 * @spec openspec/changes/example-set-verbatim-fields/specs/example-sets/spec.md#requirement-a-declared-object-may-keep-fields-verbatim
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Template placeholders are kept verbatim.
 *
 * @spec openspec/changes/example-set-verbatim-fields/specs/example-sets/spec.md#requirement-a-declared-object-may-keep-fields-verbatim
 */
class ZuiddrechtMailTemplatesVerbatimTest extends TestCase {

	/**
	 * Every field holding a placeholder that is no example-set token (no `kind:`) is named in `verbatimFields`.
	 *
	 * @return void
	 */
	public function testTemplatePlaceholdersAreKeptVerbatim(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$missing = [];
		foreach ($set['objects'] as $object) {
			foreach ((array)$object['data'] as $field => $value) {
				if (is_string($value) === false || preg_match('/\{\{(?![a-z]+:)[^{}]+\}\}/', $value) !== 1) {
					continue;
				}

				if (in_array($field, (array)($object['verbatimFields'] ?? []), true) === false) {
					$missing[] = $object['key'].'.'.$field;
				}
			}
		}

		$this->assertSame(expected: [], actual: $missing);
	}//end testTemplatePlaceholdersAreKeptVerbatim()
}//end class
