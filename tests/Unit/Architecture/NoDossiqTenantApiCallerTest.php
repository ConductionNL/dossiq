<?php

/**
 * Guard: no frontend file calls dossiq's retired tenant API (REQ-TAO-005).
 *
 * `/apps/dossiq/api/tenants` is gone; its endpoints are OpenRegister's
 * organisation endpoints now. On 2026-10-08 nothing under `src/` called it.
 * This fails the day something does, before it reaches a 404 in a browser.
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
 * @spec openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
class NoDossiqTenantApiCallerTest extends TestCase {
	/**
	 * No file under src/ names /apps/dossiq/api/tenants.
	 *
	 * Matched without the leading `/apps`, so a call built from
	 * `generateUrl('/apps/dossiq') + '/api/tenants'` is caught by its second half.
	 *
	 * @return void
	 */
	public function testNoFileUnderSrcCallsTheDossiqTenantApi(): void {
		$root     = dirname(__DIR__, 3).'/src';
		$scanned  = 0;
		$callers  = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		foreach ($iterator as $file) {
			if ($file->isFile() === false || in_array($file->getExtension(), ['js', 'ts', 'vue', 'json'], true) === false) {
				continue;
			}

			$scanned++;
			$source = (string) file_get_contents($file->getPathname());
			if (str_contains($source, 'dossiq/api/tenants') === true || preg_match("#['\"`]/api/tenants#", $source) === 1) {
				$callers[] = substr($file->getPathname(), strlen($root) + 1);
			}
		}

		self::assertGreaterThan(0, $scanned, 'the scan found no frontend file, so it proves nothing');
		self::assertSame([], $callers, 'a frontend file calls the retired dossiq tenant API (REQ-TAO-005)');
	}//end testNoFileUnderSrcCallsTheDossiqTenantApi()
}//end class
