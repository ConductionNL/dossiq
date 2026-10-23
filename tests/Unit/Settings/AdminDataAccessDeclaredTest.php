<?php

/**
 * Dossiq Admin Data Access Declared Test
 *
 * The records behind the admin-only pages carry an authorization block of
 * their own (register.d/92-admin-data-access.json), so they no longer take
 * the register block that opens them to every member of staff.
 *
 * The blocks are read from the configuration OpenRegister imports: the base
 * register with every fragment merged on top, the same merge
 * SettingsService::loadConfiguration() runs.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/admin-data-access/specs/admin-data-access/spec.md
 * @spec openspec/changes/privacy-and-social-domain-groups/specs/admin-data-access/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Repair\ProvisionAssignedGroups;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Schema-level authorization on the admin data.
 */
final class AdminDataAccessDeclaredTest extends TestCase {

	/**
	 * The four verbs OpenRegister's PermissionHandler reads from a block.
	 *
	 * @var array<int, string>
	 */
	private const VERBS = ['read', 'create', 'update', 'delete'];

	/**
	 * Who may write each schema; nobody else may.
	 *
	 * @var array<string, array<string, array<int, string>>>
	 */
	private const EXPECTED = [
		'avgIncident' => [
			'read' => ['admin', 'beheerders', 'dossiq-privacy', 'dossiq-background-service'],
			'create' => ['admin', 'beheerders', 'dossiq-privacy', 'dossiq-medewerkers', 'dossiq-background-service'],
			'update' => ['admin', 'beheerders', 'dossiq-privacy', 'dossiq-background-service'],
			'delete' => ['admin', 'beheerders'],
		],
		'indicatiestelling' => [
			'read' => ['admin', 'beheerders', 'dossiq-sociaal-domein', 'dossiq-background-service'],
			'create' => ['admin', 'beheerders', 'dossiq-sociaal-domein', 'dossiq-background-service'],
			'update' => ['admin', 'beheerders', 'dossiq-sociaal-domein', 'dossiq-background-service'],
			'delete' => ['admin', 'beheerders'],
		],
		'mdoOverleg' => [
			'read' => ['admin', 'beheerders', 'dossiq-sociaal-domein', 'dossiq-background-service'],
			'create' => ['admin', 'beheerders', 'dossiq-sociaal-domein', 'dossiq-background-service'],
			'update' => ['admin', 'beheerders', 'dossiq-sociaal-domein', 'dossiq-background-service'],
			'delete' => ['admin', 'beheerders'],
		],
		'reIntegratieTraject' => [
			'read' => ['admin', 'beheerders', 'dossiq-sociaal-domein', 'dossiq-background-service'],
			'create' => ['admin', 'beheerders', 'dossiq-sociaal-domein', 'dossiq-background-service'],
			'update' => ['admin', 'beheerders', 'dossiq-sociaal-domein', 'dossiq-background-service'],
			'delete' => ['admin', 'beheerders'],
		],
		'supplierUser' => [
			'read' => ['admin', 'beheerders'],
			'create' => ['admin', 'beheerders'],
			'update' => ['admin', 'beheerders'],
			'delete' => ['admin', 'beheerders'],
		],
		'tenantUser' => [
			'read' => ['admin', 'beheerders', 'dossiq-medewerkers', 'dossiq-background-service'],
			'create' => ['admin', 'beheerders'],
			'update' => ['admin', 'beheerders'],
			'delete' => ['admin', 'beheerders'],
		],
		'tenantMandate' => [
			'read' => ['admin', 'beheerders', 'dossiq-medewerkers', 'dossiq-background-service'],
			'create' => ['admin', 'beheerders'],
			'update' => ['admin', 'beheerders'],
			'delete' => ['admin', 'beheerders'],
		],
		'tenantBillingEvent' => [
			'read' => ['admin', 'beheerders', 'dossiq-background-service'],
			'create' => ['admin', 'beheerders', 'dossiq-background-service'],
			'update' => ['admin', 'beheerders', 'dossiq-background-service'],
			'delete' => ['admin', 'beheerders'],
		],
		'tenantSubscription' => [
			'read' => ['admin', 'beheerders', 'dossiq-background-service'],
			'create' => ['admin', 'beheerders'],
			'update' => ['admin', 'beheerders'],
			'delete' => ['admin', 'beheerders'],
		],
	];

	/**
	 * The merged register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = [];

	/**
	 * Merge the base register with its fragments.
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
	 * The schemas under test.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function schemas(): array {
		$cases = [];
		foreach (array_keys(self::EXPECTED) as $slug) {
			$cases[$slug] = [$slug];
		}

		return $cases;
	}//end schemas()

	/**
	 * Each schema carries exactly the declared block, on every verb.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return void
	 */
	#[DataProvider('schemas')]
	public function testTheSchemaCarriesItsOwnBlock(string $slug): void {
		$block = ($this->config['components']['schemas'][$slug]['authorization'] ?? null);

		$this->assertIsArray($block, $slug . ' must carry an authorization block, or it takes the register block that opens it to all staff');
		foreach (self::VERBS as $verb) {
			$this->assertSame(self::EXPECTED[$slug][$verb], ($block[$verb] ?? null), $slug . '.' . $verb);
		}
	}//end testTheSchemaCarriesItsOwnBlock()

	/**
	 * No verb is granted to everybody, and of ordinary staff only the reading
	 * of tenant records and the reporting of a breach remain.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return void
	 */
	#[DataProvider('schemas')]
	public function testNoVerbIsOpenToEverybody(string $slug): void {
		$block = $this->config['components']['schemas'][$slug]['authorization'];
		foreach (self::VERBS as $verb) {
			foreach ($block[$verb] as $group) {
				$this->assertIsString($group, $slug . '.' . $verb . ' names groups, not rules');
				$this->assertNotContains($group, ['public', 'authenticated'], $slug . '.' . $verb);
			}
		}

		foreach (['update', 'delete'] as $verb) {
			$this->assertNotContains('dossiq-medewerkers', $block[$verb], $slug . '.' . $verb . ' is for the roles that own the data');
		}
	}//end testNoVerbIsOpenToEverybody()

	/**
	 * Every group named exists on the server: dossiq provisions it, or it is
	 * Nextcloud's own `admin`. A group nobody has matches nobody.
	 *
	 * @return void
	 */
	public function testEveryNamedGroupIsProvisioned(): void {
		$known = array_merge(ProvisionAssignedGroups::ASSIGNED_GROUPS, ['admin', 'dossiq-background-service']);
		foreach (self::EXPECTED as $slug => $verbs) {
			foreach ($verbs as $verb => $groups) {
				foreach ($groups as $group) {
					$this->assertContains($group, $known, $slug . '.' . $verb . ' names ' . $group);
				}
			}
		}
	}//end testEveryNamedGroupIsProvisioned()

	/**
	 * The register version moves past the one that shipped without the
	 * blocks, because OpenRegister re-imports a schema only for a newer one.
	 *
	 * @return void
	 */
	public function testTheRegisterVersionMoves(): void {
		$this->assertTrue(version_compare((string)$this->config['info']['version'], '0.20.55', '>'));
	}//end testTheRegisterVersionMoves()
}//end class
