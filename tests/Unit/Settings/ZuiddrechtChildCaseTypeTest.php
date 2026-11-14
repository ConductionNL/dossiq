<?php

/**
 * Example data for DqZaaktype, DqZaaktypeOverzicht and DqZaaktypeVelden:
 * the case type "Omgevingsvergunning regulier", a draft fifth version that
 * derives from Omgevingsvergunning and adds one field of its own.
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
 * @spec openspec/specs/example-set-omgevingsvergunning-regulier/spec.md#requirement-a-child-case-type-derives-from-omgevingsvergunning
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The child case type and its own field.
 *
 * @spec openspec/specs/example-set-omgevingsvergunning-regulier/spec.md#requirement-a-child-case-type-derives-from-omgevingsvergunning
 */
class ZuiddrechtChildCaseTypeTest extends TestCase {

	/**
	 * Omgevingsvergunning regulier names Omgevingsvergunning as its parent, is a draft version 5, and carries one required field of its own.
	 *
	 * @return void
	 */
	public function testTheChildCaseTypeDerivesFromOmgevingsvergunning(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');

		$child = $objects['zaaktype-omgevingsvergunning-regulier'];
		$this->assertSame(expected: 'caseType', actual: $child['schema']);
		$this->assertSame(expected: ['identifier' => 'omgevingsvergunning'], actual: $child['lookups']['parent']['where']);
		$this->assertSame(expected: '{{lookup:parent}}', actual: $child['data']['parentCaseType']);
		$this->assertSame(expected: [5, true], actual: [$child['data']['version'], $child['data']['isDraft']]);

		$field = $objects['veld-omgevingsvergunning-regulier-situatietekening'];
		$this->assertSame(expected: '{{object:zaaktype-omgevingsvergunning-regulier.id}}', actual: $field['data']['caseType']);
		$this->assertTrue(condition: $field['data']['isRequired']);
	}//end testTheChildCaseTypeDerivesFromOmgevingsvergunning()
}//end class
