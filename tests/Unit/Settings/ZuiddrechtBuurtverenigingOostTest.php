<?php

/**
 * Example data for DqOrganisatie: Buurtvereniging Oost with its chair, its
 * three cases (one in decision, two closed) and its contact moments.
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
 * @spec openspec/specs/example-set-buurtvereniging-oost/spec.md#requirement-buurtvereniging-oost-has-its-cases-and-contact-moments
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The organisation board's association.
 *
 * @spec openspec/specs/example-set-buurtvereniging-oost/spec.md#requirement-buurtvereniging-oost-has-its-cases-and-contact-moments
 */
class ZuiddrechtBuurtverenigingOostTest extends TestCase {

	/**
	 * The association, its chair, three cases filed for it and four contact moments naming it.
	 *
	 * @return void
	 */
	public function testTheAssociationHasItsCasesAndContactMoments(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');

		$this->assertSame(expected: '{{cast:buurtoost.kvk}}', actual: $objects['bedrijf-buurtoost']['data']['kvkNumber']);
		// Pipelinq's client-buurtoost names the same master entity, so its tickets show on the organisation.
		$this->assertSame(expected: '0f5e0000-0000-4000-8000-000000000007', actual: $objects['bedrijf-buurtoost']['data']['masterEntityRef']);
		$this->assertSame(expected: '{{cast:marja.bsn}}', actual: $objects['persoon-marja']['data']['citizenServiceNumber']);

		$cases = [];
		foreach ($objects as $object) {
			if ($object['schema'] === 'case' && ($object['data']['requester'] ?? '') === '{{object:bedrijf-buurtoost.id}}') {
				$cases[$object['data']['title']] = $object['lookups']['caseType']['where']['identifier'];
			}
		}

		$this->assertSame(
			expected: [
				'Subsidie buurtfeest Oosthaven'          => 'subsidieaanvraag',
				'Evenementenvergunning buurtfeest 2026'  => 'evenementenvergunning',
				'Melding kapot speeltoestel Oosthavenplein' => 'melding-openbare-ruimte',
			],
			actual: $cases
		);

		$moments = array_filter($objects, static fn (array $o): bool => $o['schema'] === 'contactmoment' && ($o['data']['contact'] ?? '') === '{{object:bedrijf-buurtoost.id}}');
		$this->assertCount(expectedCount: 4, haystack: $moments);
	}//end testTheAssociationHasItsCasesAndContactMoments()
}//end class
