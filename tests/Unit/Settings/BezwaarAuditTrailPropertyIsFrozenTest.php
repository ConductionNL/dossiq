<?php

/**
 * Both schemas describe their embedded auditTrail as the frozen record it now is.
 *
 * New entries are rows on OpenRegister's audit trail of the record. The array
 * keeps what was written before, and was copied onto that trail. A description
 * that still says "append-only" invites someone to append.
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
 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class BezwaarAuditTrailPropertyIsFrozenTest extends TestCase {
	/**
	 * In both descriptors, hearingSession and bacAdviceRequest call the array frozen (REQ-BAT-004).
	 *
	 * @return void
	 */
	public function testBothSchemasDescribeTheArrayAsFrozen(): void {
		foreach (['lib/Settings/dossiq_register.json', 'lib/Settings/dossiq_mock_register.json'] as $descriptor) {
			$schemas = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/'.$descriptor), true)['components']['schemas'] ?? [];
			foreach (['hearingSession', 'bacAdviceRequest'] as $schema) {
				$description = (string) ($schemas[$schema]['properties']['auditTrail']['description'] ?? '');
				$where = $descriptor.' '.$schema;

				$this->assertNotSame('', $description, $where.' must describe auditTrail');
				$this->assertStringNotContainsStringIgnoringCase('append-only', $description, $where);
				$this->assertStringContainsString('kept as written', $description, $where);
				$this->assertStringContainsString("copied onto OpenRegister's audit trail", $description, $where);
			}
		}
	}//end testBothSchemasDescribeTheArrayAsFrozen()
}//end class
