<?php

/**
 * Subsidie Register Fragment Unit Tests
 *
 * Verifies that the register.d/50-subsidie.json fragment unions its nine
 * schemas, register membership and seed objects onto the procest monolith via
 * the ADR-037 deep-merge loader, without disturbing the base configuration.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Settings
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Settings;

use OCA\Procest\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * Integration-style unit tests for the subsidie register fragment.
 *
 * @covers \OCA\Procest\Service\SettingsService
 *
 * @uses \OCA\Procest\Service\Settings\RegisterFragmentMerger
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-01
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-02
 */
class SubsidieFragmentTest extends TestCase {

	/**
	 * The nine subsidie schema slugs introduced by the fragment.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMA_SLUGS = [
		'subsidieRegeling',
		'subsidieAanvraag',
		'subsidieBeoordeling',
		'subsidieBeschikking',
		'subsidieUitvoering',
		'tussenrapportage',
		'subsidieVaststelling',
		'terugvordering',
		'bewijsstuk',
	];

	/**
	 * @var array<string, mixed>
	 */
	private array $merged;

	/**
	 * Load the monolith and merge the real register.d fragments.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$base = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/procest_register.json'),
			true
		);

		[$merged] = (new RegisterFragmentMerger())->merge(
			base: $base,
			fragmentDir: __DIR__ . '/../../../lib/Settings/register.d'
		);

		$this->merged = $merged;
	}//end setUp()

	/**
	 * All nine subsidie schemas are present after the merge.
	 *
	 * @return void
	 */
	public function testSubsidieSchemasPresent(): void {
		$schemas = $this->merged['components']['schemas'];
		foreach (self::SCHEMA_SLUGS as $slug) {
			$this->assertArrayHasKey($slug, $schemas, $slug . ' schema must be merged in');
		}
	}//end testSubsidieSchemasPresent()

	/**
	 * The base schemas survive the union (disjoint merge).
	 *
	 * @return void
	 */
	public function testBaseSchemasPreserved(): void {
		$schemas = $this->merged['components']['schemas'];
		$this->assertArrayHasKey('case', $schemas);
		$this->assertArrayHasKey('voorstel', $schemas);
	}//end testBaseSchemasPreserved()

	/**
	 * A contact/person is NOT re-invented — no bespoke applicant schema.
	 *
	 * @return void
	 */
	public function testNoApplicantSchemaInvented(): void {
		$schemas = $this->merged['components']['schemas'];
		$this->assertArrayNotHasKey('applicant', $schemas);
		$this->assertArrayNotHasKey('subsidieContact', $schemas);
		$this->assertArrayNotHasKey('subsidiePersoon', $schemas);
	}//end testNoApplicantSchemaInvented()

	/**
	 * The procest register lists the new subsidie schemas (list concatenation).
	 *
	 * @return void
	 */
	public function testRegisterMembershipUnioned(): void {
		$schemas = $this->merged['components']['registers']['procest']['schemas'];
		foreach (self::SCHEMA_SLUGS as $slug) {
			$this->assertContains($slug, $schemas, $slug . ' must be a member of the procest register');
		}

		// Existing membership preserved.
		$this->assertContains('caseType', $schemas);
	}//end testRegisterMembershipUnioned()

	/**
	 * Subsidie seed objects are appended to components.objects.
	 *
	 * @return void
	 */
	public function testSeedObjectsAppended(): void {
		$slugs = array_map(
			static function (array $object): string {
				return (string)($object['@self']['slug'] ?? '');
			},
			$this->merged['components']['objects']
		);

		$this->assertContains('regeling-innovatiefonds-2026', $slugs);
		$this->assertContains('regeling-cultuur-subsidie-2026', $slugs);
	}//end testSeedObjectsAppended()

	/**
	 * The bewijsstuk schema masks special-category data: BSN lives on the
	 * aanvraag as a masked reference, never as a raw plaintext property.
	 *
	 * @return void
	 */
	public function testBsnIsMaskedReference(): void {
		$request = $this->merged['components']['schemas']['subsidieAanvraag']['properties'];
		$this->assertArrayHasKey('applicantBsnRef', $request);
		$this->assertStringContainsStringIgnoringCase(
			'never stored raw',
			(string)$request['applicantBsnRef']['description']
		);
	}//end testBsnIsMaskedReference()
}//end class
