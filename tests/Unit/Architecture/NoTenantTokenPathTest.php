<?php

/**
 * Structural guard: no token decides or checks the tenant, and dossiq routes no tenant API.
 *
 * Since the session decides the tenant, `TenantJwtService` only validated
 * tokens minted elsewhere and `TenantClaimValidationMiddleware` returned early
 * without a Bearer token. Ruben decided on 2026-10-08 (Q2) that the three
 * token classes go. The `jwt_signing_secret` key stays, because
 * `PortalAssertionVerifier` reads it. Q5 moved `TenantController`'s endpoints
 * to OpenRegister's organisation endpoints.
 *
 * Comments count: a docblock that names a deleted class sends the reader to
 * code that is not there.
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
 * @spec openspec/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * None of the three token classes, and no TenantController, under lib/.
 *
 * @coversNothing
 */
class NoTenantTokenPathTest extends TestCase {
	/**
	 * The repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__.'/../../..';

	/**
	 * The three classes of the tenant token path (Q2).
	 *
	 * @var array<int, string>
	 */
	private const TOKEN_CLASSES = [
		'TenantJwtService',
		'TenantClaimValidationMiddleware',
		'TenantClaimMismatchException',
	];

	/**
	 * No file under lib/ is one of, or names, the three token classes; the secret stays.
	 *
	 * @return void
	 */
	public function testNoneOfTheThreeTokenClassesIsNamedUnderLib(): void {
		$this->assertSame([], $this->filesNaming(classes: self::TOKEN_CLASSES), 'a token class is still named under lib/ (REQ-TAO-001)');

		$verifier = (string) file_get_contents(self::ROOT.'/lib/Portal/PortalAssertionVerifier.php');
		$this->assertStringContainsString("'jwt_signing_secret'", $verifier, 'PortalAssertionVerifier must still read jwt_signing_secret');
	}//end testNoneOfTheThreeTokenClassesIsNamedUnderLib()

	/**
	 * TenantController is not under lib/, and nothing under lib/ names it.
	 *
	 * @return void
	 */
	public function testTenantControllerIsNotUnderLib(): void {
		$this->assertFileDoesNotExist(self::ROOT.'/lib/Controller/TenantController.php');
		$this->assertSame([], $this->filesNaming(classes: ['TenantController']), 'TenantController is still named under lib/ (REQ-TAO-005)');
	}//end testTenantControllerIsNotUnderLib()

	/**
	 * Every PHP file under lib/ that is, or names, one of the classes.
	 *
	 * A class name is matched as a whole word, so `TenantController` does not
	 * match `TenantControllerContract`.
	 *
	 * @param array<int, string> $classes The class short names.
	 *
	 * @return array<int, string> "<path> names <class>" lines.
	 */
	private function filesNaming(array $classes): array {
		$hits     = [];
		$scanned  = 0;
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/lib'));
		foreach ($iterator as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$scanned++;
			$relative = substr($file->getPathname(), strlen(self::ROOT) + 1);
			$source   = (string) file_get_contents($file->getPathname());
			foreach ($classes as $class) {
				if (preg_match('/\b'.preg_quote($class, '/').'\b/', $source) === 1) {
					$hits[] = $relative.' names '.$class;
				}
			}
		}

		$this->assertGreaterThan(0, $scanned, 'the scan found no PHP file under lib/, so it proves nothing');
		sort($hits);

		return $hits;
	}//end filesNaming()
}//end class
