<?php

/**
 * The local tenant admin store and its surface are gone.
 *
 * `TenantSaasService` and `TenantSaasController` administered dossiq's own
 * `tenant` schema. The tenant is OpenRegister's Organisation now, its status
 * is OpenRegister's TenantLifecycleService, and the `tenant` schema stays only
 * as the read-only anchor of the tenant audit trail. So nothing names the two
 * classes, no route reaches them, and the one write left on the schema is
 * the anchor creation. No manifest page administers tenants either (step 5).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
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
 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
class NoRetiredTenantStoreTest extends TestCase {
	/**
	 * The repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__.'/../../..';

	/**
	 * The only file allowed to write a tenant object, and the call it may make.
	 *
	 * @var array<string, string>
	 */
	private const ANCHOR_WRITER = ['lib/Service/TenantService.php' => 'saveObject'];

	/**
	 * Every PHP file under lib/, relative to the root.
	 *
	 * @return array<int, string> The paths.
	 */
	private function libFiles(): array {
		$files = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/lib'));
		foreach ($iterator as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'php') {
				$files[] = 'lib/'.ltrim(substr($file->getPathname(), strlen(self::ROOT.'/lib')), '/');
			}
		}

		sort($files);
		return $files;
	}//end libFiles()

	/**
	 * The files under lib/ that name a class.
	 *
	 * @param string $class The short class name.
	 *
	 * @return array<int, string> The paths.
	 */
	private function filesNaming(string $class): array {
		$hits = [];
		foreach ($this->libFiles() as $path) {
			if (preg_match('/\b'.$class.'\b/', (string) file_get_contents(self::ROOT.'/'.$path)) === 1) {
				$hits[] = $path;
			}
		}

		return $hits;
	}//end filesNaming()

	/**
	 * No class under lib/ is or names TenantSaasService (REQ-TOO-004).
	 *
	 * @return void
	 */
	public function testNoClassUnderLibNamesTenantSaasService(): void {
		$this->assertFileDoesNotExist(self::ROOT.'/lib/Service/TenantSaasService.php');
		$this->assertSame([], $this->filesNaming(class: 'TenantSaasService'));
	}//end testNoClassUnderLibNamesTenantSaasService()

	/**
	 * No route names the TenantSaasController, and the controller is gone (REQ-TOO-004).
	 *
	 * @return void
	 */
	public function testNoRouteNamesTheTenantSaasController(): void {
		$this->assertFileDoesNotExist(self::ROOT.'/lib/Controller/TenantSaasController.php');
		$this->assertSame([], $this->filesNaming(class: 'TenantSaasController'));

		$routes = (string) file_get_contents(self::ROOT.'/appinfo/routes.php');
		$this->assertStringNotContainsString("'tenantSaas#", $routes);
	}//end testNoRouteNamesTheTenantSaasController()

	/**
	 * The only write on the tenant schema is the anchor creation, and nothing deletes one (REQ-TOO-002).
	 *
	 * A write is a `saveObject(` or `deleteObject(` call whose arguments name
	 * the tenant schema, by the literal slug or by a tenant schema constant.
	 *
	 * @return void
	 */
	public function testOnlyTheAnchorCreationWritesATenantObjectAndNothingDeletesOne(): void {
		$found = [];
		foreach ($this->libFiles() as $path) {
			$source = (string) file_get_contents(self::ROOT.'/'.$path);
			preg_match_all('/(saveObject|deleteObject)\s*\((.{0,600}?)\)\s*;/s', $source, $calls, PREG_SET_ORDER);
			foreach ($calls as $call) {
				if (preg_match("/schema:\s*(?:'tenant'|self::SCHEMA_TENANT|self::TENANT_SCHEMA\w*)\b/", $call[2]) === 1) {
					$found[] = $path.' '.$call[1];
				}
			}
		}

		$expected = [];
		foreach (self::ANCHOR_WRITER as $path => $verb) {
			$expected[] = $path.' '.$verb;
		}

		$this->assertSame($expected, $found);
	}//end testOnlyTheAnchorCreationWritesATenantObjectAndNothingDeletesOne()

	/**
	 * No manifest page, menu entry or deep link administers tenants (REQ-TOO-004, step 5).
	 *
	 * @return void
	 */
	public function testNoManifestPageOrMenuNamesTheTenantsRoute(): void {
		foreach (['src/manifest.json', 'src/menu-layout.json', 'src/menu-layout.simple.json'] as $file) {
			$source = (string) file_get_contents(self::ROOT.'/'.$file);
			foreach (['"Tenants"', '"TenantDetail"', '"TenantsMenu"', '/settings/tenants'] as $needle) {
				$this->assertStringNotContainsString($needle, $source, $file.' still names '.$needle);
			}
		}
	}//end testNoManifestPageOrMenuNamesTheTenantsRoute()
}//end class
