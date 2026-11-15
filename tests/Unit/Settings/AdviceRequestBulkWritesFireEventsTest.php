<?php

/**
 * The advice request schema asks OpenRegister to fire the save events on bulk writes.
 *
 * DeliberationStartGuard listens to ObjectCreatingEvent and ObjectUpdatingEvent.
 * A bulk save, an import or a flow write fires them only when the schema's
 * `configuration.fireEventsOnBulkWrites` is true, and nothing in the schema
 * itself shows that the guard depends on it.
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
 * @spec openspec/changes/creating-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class AdviceRequestBulkWritesFireEventsTest extends TestCase {
	/**
	 * The register declares fireEventsOnBulkWrites as true on bacAdviceRequest.
	 *
	 * @return void
	 */
	public function testTheAdviceRequestSchemaFiresEventsOnBulkWrites(): void {
		$register = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/lib/Settings/dossiq_register.json'), true);
		$configuration = ($register['components']['schemas']['bacAdviceRequest']['configuration'] ?? []);

		$this->assertSame(
			expected: true,
			actual: ($configuration['fireEventsOnBulkWrites'] ?? null),
			message: 'bacAdviceRequest must set configuration.fireEventsOnBulkWrites to true'
		);
	}//end testTheAdviceRequestSchemaFiresEventsOnBulkWrites()
}//end class
