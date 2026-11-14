<?php

/**
 * Example data for the contact and organisation lists and the new-case
 * duplicate warning: Pieter Bos and Henk Visser as persons, Visser Bouw and
 * Aannemersbedrijf Van Wijk as companies, all quoted from the cast.
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
 * @spec openspec/specs/example-set-contacts-companies/spec.md#requirement-the-example-data-carries-the-contacts-and-companies-the-list-boards-draw
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The persons and companies the list boards draw.
 *
 * @spec openspec/specs/example-set-contacts-companies/spec.md#requirement-the-example-data-carries-the-contacts-and-companies-the-list-boards-draw
 */
class ZuiddrechtContactsAndCompaniesTest extends TestCase {

	/**
	 * Two persons and two companies, each quoting its cast entry and never a typed name or number.
	 *
	 * @return void
	 */
	public function testTheListBoardsContactsAreInTheSet(): void {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);
		$objects = array_column($set['objects'], null, 'key');

		foreach (['persoon-pieterbos' => 'pieterbos', 'persoon-henkvisser' => 'henkvisser', 'persoon-jvanwijk' => 'jvanwijk'] as $key => $cast) {
			$this->assertSame(expected: 'brpPerson', actual: $objects[$key]['schema']);
			$this->assertSame(expected: '{{cast:'.$cast.'.bsn}}', actual: $objects[$key]['data']['citizenServiceNumber']);
			$this->assertSame(expected: '{{cast:'.$cast.'.displayName}}', actual: $objects[$key]['data']['displayName']);
		}

		foreach (['bedrijf-visserbouw' => 'visserbouw', 'bedrijf-vanwijk' => 'vanwijk', 'bedrijf-bewonerslindelaan' => 'bewonerslindelaan'] as $key => $cast) {
			$this->assertSame(expected: 'kvkCompany', actual: $objects[$key]['schema']);
			$this->assertSame(expected: '{{cast:'.$cast.'.kvk}}', actual: $objects[$key]['data']['kvkNumber']);
			$this->assertSame(expected: '{{cast:'.$cast.'.name}}', actual: $objects[$key]['data']['tradeName']);
		}

		// The residents' association reported more than once; Sanne asked for no secrecy.
		$this->assertSame(
			expected: [['key' => 'meerdere-meldingen', 'label' => 'Meerdere meldingen', 'effect' => 'warn', 'note' => 'Meldt vaker over de Lindelaan, ook via de balie.']],
			actual: $objects['bedrijf-bewonerslindelaan']['data']['indicators']
		);
		$this->assertSame(expected: [], actual: ($objects['persoon-sanne']['data']['indicators'] ?? []));
	}//end testTheListBoardsContactsAreInTheSet()
}//end class
