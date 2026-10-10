<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/portal-permits-as-held-products/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tasks 1.1 and 1.2: the permit schema, issuesPermit on the case type, and the two seeded parking types.
 */
class PermitSchemaTest extends TestCase {
	/**
	 * Every permit property carries a title, and a permit as the issuer will write it validates.
	 *
	 * @return void
	 */
	public function testThePermitSchemaIsTitledAndTakesAPermit(): void {
		$register = new RealSchemaValidator();
		$permit = $register->schemas['permit'];
		foreach ($permit['properties'] as $name => $property) {
			$this->assertNotSame('', (string)($property['title'] ?? ''), $name . ' has a title');
		}

		$this->assertSame(['active', 'suspended', 'revoked'], $permit['properties']['status']['enum']);
		$this->assertSame(
			[],
			$register->errors(
				slug: 'permit',
				payload: [
					'title' => 'Bewonersvergunning binnenstad',
					'kind' => 'parkeren-bewoner',
					'theme' => 'parkeren',
					'portalSubject' => 'subj-sanne',
					'validFrom' => '2026-01-01',
					'validUntil' => '2026-12-31',
					'status' => 'active',
					'kenteken' => 'GZ482K',
					'adres' => 'Lindelaan 12',
				]
			)
		);
		$this->assertNotSame([], $register->errors(slug: 'permit', payload: ['title' => 'x', 'kind' => 'parkeren-bewoner', 'portalSubject' => 's', 'kenteken' => 'GZ-482-K']), 'a plate is stored without dashes');
	}//end testThePermitSchemaIsTitledAndTakesAPermit()

	/**
	 * The two parking case types are seeded, the first issuing a permit and naming the second as its change type.
	 *
	 * @return void
	 */
	public function testTheParkingCaseTypesAreSeeded(): void {
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/85-permits.json'), true);
		$types = [];
		$statuses = [];
		foreach ($seed['components']['objects'] as $object) {
			if ($object['@self']['schema'] === 'caseType') {
				$types[$object['title']] = $object;
			}

			if ($object['@self']['schema'] === 'statusType') {
				$statuses[$object['id']] = $object;
			}
		}

		$this->assertSame(['Parkeervergunning bewoners', 'Kenteken wijzigen parkeervergunning'], array_keys($types));
		$bewoner = $types['Parkeervergunning bewoners'];
		$this->assertSame('parkeren-bewoner', $bewoner['issuesPermit']['kind']);
		$this->assertSame('parkeren', $bewoner['issuesPermit']['theme']);
		$this->assertSame($types['Kenteken wijzigen parkeervergunning']['id'], $bewoner['issuesPermit']['changeCaseType']);
		foreach ($types as $type) {
			$this->assertArrayHasKey($type['initialStatus'], $statuses, $type['title'] . ' starts in one of its own statuses');
			$this->assertSame($type['id'], $statuses[$type['initialStatus']]['caseType']);
		}

		$register = new RealSchemaValidator();
		$this->assertArrayHasKey('issuesPermit', $register->schemas['caseType']['properties']);
	}//end testTheParkingCaseTypesAreSeeded()
}//end class
