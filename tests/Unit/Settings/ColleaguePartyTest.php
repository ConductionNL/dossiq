<?php

/**
 * A colleague is a party of the municipality's own: the colleague schema and
 * the case's accepted kinds.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/colleague-party/specs/party-pages/spec.md#requirement-a-colleague-can-be-a-party-on-a-case
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * The merged register declares the colleague party and the case accepts it.
 *
 * @spec openspec/changes/colleague-party/specs/party-pages/spec.md#requirement-a-colleague-can-be-a-party-on-a-case
 */
class ColleaguePartyTest extends TestCase {

	/**
	 * The merged register's schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		$root = dirname(__DIR__, 3);
		$base = json_decode((string)file_get_contents($root.'/lib/Settings/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $root.'/lib/Settings/register.d');

		return $merged['components']['schemas'];
	}//end schemas()

	/**
	 * The colleague schema is a party of kind colleague, named by its display
	 * name and tied to a Nextcloud account by user id.
	 *
	 * @return void
	 */
	public function testTheColleagueIsAPartyTiedToAnAccount(): void {
		$colleague = $this->schemas()['colleague'];

		$this->assertSame(expected: ['kind' => 'colleague', 'nameProperty' => 'displayName'], actual: $colleague['configuration']['x-openregister-party']);
		$this->assertSame(expected: ['userId', 'displayName'], actual: $colleague['required']);
		$this->assertArrayHasKey(key: 'jobTitle', array: $colleague['properties']);
	}//end testTheColleagueIsAPartyTiedToAnAccount()

	/**
	 * A case accepts a colleague, and every kind names the identity shape a
	 * shared vocabulary (pipelinq's role kinds) checks it by.
	 *
	 * @return void
	 */
	public function testTheCaseAcceptsAColleagueAndNamesTheShapes(): void {
		$shapes = [];
		foreach ($this->schemas()['case']['configuration']['partyKinds'] as $kind) {
			$shapes[$kind['key']] = ($kind['identityShapes'] ?? null);
		}

		$this->assertSame(expected: ['naturalPerson'], actual: $shapes['person']);
		$this->assertSame(expected: ['organization'], actual: $shapes['organisation']);
		$this->assertSame(expected: ['naturalPerson'], actual: $shapes['colleague']);
	}//end testTheCaseAcceptsAColleagueAndNamesTheShapes()
}//end class
