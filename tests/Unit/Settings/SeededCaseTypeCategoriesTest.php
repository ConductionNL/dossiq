<?php
/**
 * Seeded Case Type Categories Unit Tests
 *
 * Every case type dossiq ships carries a category, so the Case types index
 * has folders to show from the first install.
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
 * The Case types index groups by `category` (REQ-PDM-01). A category is a
 * free word, so nothing but the shipped data can make the folder pane show
 * anything on a fresh install; a seeded type without one lands under "All"
 * and the pane stands empty beside the table.
 *
 * @covers \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class SeededCaseTypeCategoriesTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings';


	/**
	 * Every case type object in the register and its fragments names a category.
	 *
	 * @return void
	 */
	public function testEveryRegisterSeededCaseTypeHasACategory(): void {
		$base = json_decode((string)file_get_contents(self::SETTINGS . '/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: self::SETTINGS . '/register.d');

		$caseTypes = array_filter(
			$merged['components']['objects'],
			static fn (array $object): bool => ($object['@self']['schema'] ?? null) === 'caseType'
		);

		$this->assertNotEmpty(actual: $caseTypes);
		foreach ($caseTypes as $caseType) {
			$this->assertCategory(caseType: $caseType, where: 'register object');
			// A changed seed reaches an existing install only when its version
			// moves: the import updates an object whose imported version is
			// newer than the stored one, and skips it otherwise.
			$this->assertTrue(
				condition: version_compare((string)($caseType['@self']['version'] ?? '1.0.0'), '1.0.0', '>'),
				message: 'case type "' . $caseType['title'] . '" needs a version above 1.0.0 so an existing install picks up its category'
			);
		}
	}//end testEveryRegisterSeededCaseTypeHasACategory()


	/**
	 * The VTH and case-flow seed files, read by their repair steps, name one too.
	 *
	 * @return void
	 */
	public function testEveryRepairStepSeededCaseTypeHasACategory(): void {
		foreach (['vth_seed_data.json', 'case_flow_seed_data.json'] as $file) {
			$data = json_decode((string)file_get_contents(self::SETTINGS . '/' . $file), true);
			$this->assertNotEmpty(actual: $data['caseTypes'], message: $file);
			foreach ($data['caseTypes'] as $caseType) {
				$this->assertCategory(caseType: $caseType, where: $file);
			}
		}
	}//end testEveryRepairStepSeededCaseTypeHasACategory()


	/**
	 * The besluitvorming template bundles name one for the case type they seed.
	 *
	 * @return void
	 */
	public function testEveryTemplateBundleCaseTypeHasACategory(): void {
		$bundles = glob(self::SETTINGS . '/templates/bvw-*.json');
		$this->assertNotEmpty(actual: $bundles);
		foreach ($bundles as $path) {
			$bundle = json_decode((string)file_get_contents($path), true);
			$this->assertCategory(caseType: $bundle['caseType'], where: basename($path));
		}
	}//end testEveryTemplateBundleCaseTypeHasACategory()


	/**
	 * A category is a short free word, never an empty one.
	 *
	 * @param array<string, mixed> $caseType The seeded case type.
	 * @param string               $where    Which file it came from, for the message.
	 *
	 * @return void
	 */
	private function assertCategory(array $caseType, string $where): void {
		$title = (string)($caseType['title'] ?? '?');
		$category = $caseType['category'] ?? null;
		$this->assertIsString(actual: $category, message: "case type \"{$title}\" in {$where} has no category");
		$this->assertNotSame(expected: '', actual: trim($category), message: "case type \"{$title}\" in {$where} has an empty category");
		$this->assertLessThanOrEqual(expected: 255, actual: mb_strlen($category), message: "case type \"{$title}\" in {$where}");
	}//end assertCategory()
}//end class
