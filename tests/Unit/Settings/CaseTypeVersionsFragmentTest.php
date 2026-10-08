<?php
/**
 * Case Type Versions Fragment Unit Tests
 *
 * The shipped register carries one case type in two published versions, so
 * the Case types index's version chips have something to tell apart.
 *
 * @category Tests
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

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * Publishing a version writes `supersededBy` on the version it replaces and
 * `previousVersion` on the new one; the shipped chain carries exactly that.
 *
 * @covers \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class CaseTypeVersionsFragmentTest extends TestCase {

	/**
	 * @var array<int, array<string, mixed>> Every case type object the effective register ships.
	 */
	private array $caseTypes = [];


	/**
	 * Merge the shipped register the way SettingsService does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$root = dirname(__DIR__, 3);
		$base = json_decode((string)file_get_contents($root . '/lib/Settings/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $root . '/lib/Settings/register.d');
		$this->caseTypes = array_values(array_filter(
			$merged['components']['objects'],
			static fn (array $object): bool => ($object['@self']['schema'] ?? null) === 'caseType'
		));
	}//end setUp()


	/**
	 * At least one shipped case type is superseded, so Current versions and All versions differ.
	 *
	 * @return void
	 */
	public function testAShippedCaseTypeIsSuperseded(): void {
		$superseded = array_filter(
			$this->caseTypes,
			static fn (array $caseType): bool => (string)($caseType['supersededBy'] ?? '') !== ''
		);

		$this->assertNotEmpty(actual: $superseded, message: 'no shipped case type is superseded');
	}//end testAShippedCaseTypeIsSuperseded()


	/**
	 * Both ends of every shipped chain name each other, are published, and are the same case type.
	 *
	 * @return void
	 */
	public function testEveryChainIsLinkedBothWays(): void {
		$byId = [];
		foreach ($this->caseTypes as $caseType) {
			if (isset($caseType['id']) === true) {
				$byId[(string)$caseType['id']] = $caseType;
			}
		}

		$chains = 0;
		foreach ($this->caseTypes as $old) {
			$successorId = (string)($old['supersededBy'] ?? '');
			if ($successorId === '') {
				continue;
			}
			$chains++;
			$new = $byId[$successorId] ?? null;

			$this->assertNotNull(actual: $new, message: "'{$old['title']}' names a successor this register does not ship");
			$this->assertSame(expected: $old['id'], actual: $new['previousVersion'] ?? null, message: 'the successor does not name its predecessor');
			$this->assertSame(expected: $old['title'], actual: $new['title'], message: 'a version is the same case type later on');
			$this->assertSame(expected: $old['category'], actual: $new['category'], message: 'a version keeps its folder');
			$this->assertGreaterThan(expected: (int)$old['version'], actual: (int)$new['version']);
			$this->assertFalse(condition: $old['isDraft'], message: 'a superseded version was published once');
			$this->assertFalse(condition: $new['isDraft'], message: 'only a published version supersedes another');
			$this->assertSame(expected: '', actual: (string)($new['supersededBy'] ?? ''), message: 'the chain ends on a current version');
		}

		$this->assertGreaterThan(expected: 0, actual: $chains);
	}//end testEveryChainIsLinkedBothWays()
}//end class
