<?php

/**
 * ReadsQueueCaseSchema — the case schema a queue source reads.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ReadsQueueCaseSchema;
use PHPUnit\Framework\TestCase;

/**
 * The configured schema, trimmed, or case.
 *
 * @covers \OCA\Dossiq\Service\Support\ReadsQueueCaseSchema
 */
final class ReadsQueueCaseSchemaTest extends TestCase {

	/**
	 * The schema as the settings answer it.
	 *
	 * @param string $configured The configured value.
	 *
	 * @return string
	 */
	private function schemaFor(string $configured): string {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->with('case_schema', 'case')->willReturn($configured);
		$subject = new class($settings) {
			use ReadsQueueCaseSchema {
				caseSchema as public;
			}

			/**
			 * Constructor.
			 *
			 * @param SettingsService $settings The settings.
			 */
			public function __construct(protected SettingsService $settings) {
			}
		};

		return $subject->caseSchema();
	}//end schemaFor()

	/**
	 * A set schema is trimmed, a blank one falls back to case.
	 *
	 * @return void
	 */
	public function testSchema(): void {
		self::assertSame('zaak', $this->schemaFor(' zaak '));
		self::assertSame('case', $this->schemaFor('  '));
	}//end testSchema()
}//end class
