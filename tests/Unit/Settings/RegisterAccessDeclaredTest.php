<?php

/**
 * The dossiq register is closed to anybody outside its staff group.
 *
 * Swept over the fragment-merged register the import ships, so a schema added
 * by any fragment, today or later, is held to it. Found live on 2026-10-11: a
 * user in no group read every case, every case type and every case's files,
 * because 84 schemas had no block and OpenRegister reads no block as open.
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
 *
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Repair\ProvisionAssignedGroups;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class RegisterAccessDeclaredTest extends TestCase {

	/**
	 * The staff group.
	 */
	private const STAFF = 'dossiq-medewerkers';

	/**
	 * The background service group.
	 */
	private const BACKGROUND = 'dossiq-background-service';

	/**
	 * The verbs OpenRegister evaluates.
	 */
	private const VERBS = ['read', 'create', 'update', 'delete'];

	/**
	 * The merged register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = [];

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

		$this->config = $merged;
	}//end setUp()

	/**
	 * The register grants every verb to the staff group and the background account, and nobody else.
	 *
	 * @return void
	 */
	public function testTheRegisterIsClosedToEverybodyButItsStaff(): void {
		$register = $this->config['components']['registers']['dossiq'];

		foreach (self::VERBS as $verb) {
			$this->assertSame([self::STAFF, self::BACKGROUND], $register['authorization'][$verb], $verb);
		}

		$this->assertTrue(
			version_compare((string)$register['version'], '1.3.0', '>'),
			'OpenRegister writes a register\'s own fields only when its version is newer, so the block would reach no existing instance'
		);
		$this->assertContains(self::STAFF, ProvisionAssignedGroups::ASSIGNED_GROUPS);
	}//end testTheRegisterIsClosedToEverybodyButItsStaff()

	/**
	 * No schema that declares its own block opens a verb to every account or to anonymous callers.
	 *
	 * A schema's own block replaces the register's for that schema, so one
	 * `authenticated` here is the whole register open again for that schema.
	 *
	 * @return void
	 */
	public function testNoSchemaOpensAVerbToEverybody(): void {
		$open = [];
		foreach ($this->config['components']['schemas'] as $slug => $schema) {
			$block = ($schema['authorization'] ?? []);
			foreach (self::VERBS as $verb) {
				foreach (($block[$verb] ?? []) as $entry) {
					$group = (is_array($entry) === true) ? ($entry['group'] ?? '') : $entry;
					$matched = (is_array($entry) === true && empty($entry['match']) === false);
					if (in_array($group, ['authenticated', 'public'], true) === true && $matched === false) {
						$open[] = $slug . '.' . $verb . ' grants ' . $group;
					}
				}
			}
		}

		$this->assertSame([], $open);
	}//end testNoSchemaOpensAVerbToEverybody()

	/**
	 * A schema with a block of its own still lets the background account write.
	 *
	 * Every background job that writes does so as that account. A block that
	 * leaves it out refuses the job the moment the register closes.
	 *
	 * @return void
	 */
	public function testTheBackgroundAccountKeepsItsWrites(): void {
		$missing = [];
		foreach ($this->config['components']['schemas'] as $slug => $schema) {
			$block = ($schema['authorization'] ?? []);
			if ($block === [] || $this->restrictedToAdministration(block: $block) === true) {
				continue;
			}

			foreach (['read', 'create', 'update'] as $verb) {
				if (in_array(self::BACKGROUND, ($block[$verb] ?? []), true) === false) {
					$missing[] = $slug . '.' . $verb;
				}
			}
		}

		$this->assertSame([], $missing);
	}//end testTheBackgroundAccountKeepsItsWrites()

	/**
	 * Whether a block restricts its writes to administration on purpose.
	 *
	 * `dossiqIntegration` (admin only) and `wooRefusalGround` (admin and
	 * beheerders) are configuration, kept as they were.
	 *
	 * @param array<string, mixed> $block The block.
	 *
	 * @return bool True when no write is open to the staff group.
	 */
	private function restrictedToAdministration(array $block): bool {
		foreach (['create', 'update'] as $verb) {
			if (in_array(self::STAFF, ($block[$verb] ?? []), true) === true) {
				return false;
			}
		}

		return true;
	}//end restrictedToAdministration()
}//end class
