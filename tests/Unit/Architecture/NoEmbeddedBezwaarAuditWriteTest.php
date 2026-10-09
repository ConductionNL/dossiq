<?php

/**
 * Structural guard: no code writes a bezwaar record's embedded auditTrail.
 *
 * Every Awb and AVG entry of the bezwaar procedure is a row on OpenRegister's
 * hash-chained audit trail of its record (REQ-BAT-001). The `auditTrail`
 * array on `hearingSession` and `bacAdviceRequest` is the frozen record of
 * what was written before; nothing may append to it again, because anyone who
 * may write the record may edit it.
 *
 * Comment lines are skipped the way gate 23's `_code_lines` skips them.
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
 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
class NoEmbeddedBezwaarAuditWriteTest extends TestCase {
	/**
	 * What a write of the embedded array looks like in code.
	 *
	 * @var array<int, string>
	 */
	private const WRITES = [
		"/\\['auditTrail'\\]\\s*=(?!=)/",
		"/'auditTrail'\\s*=>/",
		'/auditTrail->append\\(/',
	];

	/**
	 * No code line under lib/ writes the auditTrail key, and nothing can append.
	 *
	 * @return void
	 */
	public function testNoBezwaarServiceWritesTheAuditTrailProperty(): void {
		$root     = dirname(__DIR__, 3);
		$hits     = [];
		$scanned  = 0;
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/lib'));
		foreach ($iterator as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$scanned++;
			foreach (file($file->getPathname()) ?: [] as $index => $line) {
				$trimmed = ltrim($line);
				if (str_starts_with($trimmed, '*') === true || str_starts_with($trimmed, '//') === true || str_starts_with($trimmed, '/*') === true) {
					continue;
				}

				foreach (self::WRITES as $pattern) {
					if (preg_match($pattern, $line) === 1) {
						$hits[] = substr($file->getPathname(), strlen($root) + 1).':'.($index + 1);
					}
				}
			}
		}//end foreach

		$this->assertGreaterThan(0, $scanned, 'the scan found no PHP file under lib/, so it proves nothing');
		$this->assertSame([], $hits, 'code under lib/ writes an embedded bezwaar auditTrail (REQ-BAT-001)');

		$writer = (string) file_get_contents($root.'/lib/Service/Bezwaar/BezwaarAuditTrail.php');
		$this->assertDoesNotMatchRegularExpression('/public function append\\(/', $writer, 'BezwaarAuditTrail must not offer append()');
	}//end testNoBezwaarServiceWritesTheAuditTrailProperty()
}//end class
