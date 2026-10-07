<?php

/**
 * The queue asks OpenRegister for open cases in the form OpenRegister answers.
 *
 * Measured live on NC 35 with OpenRegister a9b55289e (2026-10-08): a search
 * with `isFinalStatus => false` returns no rows, while `isFinalStatus => 0`
 * returns the open cases. AssignedCasesSource sent `false`, so nobody's queue
 * and nobody's daily digest ever held an assigned case. The register double
 * here answers a boolean filter the way OpenRegister does, with nothing.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Queue\Source\AssignedCasesSource;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * A boolean filter finds nothing in OpenRegister, so the source must not send one.
 *
 * @covers \OCA\Dossiq\Service\Queue\Source\AssignedCasesSource
 * @uses \OCA\Dossiq\Service\Queue\QueueItem
 * @uses \OCA\Dossiq\Service\Queue\Source\RegisterBackedSource
 * @uses \OCA\Dossiq\Service\Termijn\TermRearm
 */
final class OpenCaseFilterTest extends TestCase {

	/**
	 * An open case assigned to alice reaches her queue.
	 *
	 * @return void
	 */
	public function testAnOpenAssignedCaseIsFound(): void {
		$register = new class {
			/**
			 * The filters the source sent.
			 *
			 * @var array<string, mixed>
			 */
			public array $asked = [];

			/**
			 * Answers like OpenRegister: a boolean filter value matches no row.
			 *
			 * @param string               $registerSlug The register.
			 * @param string               $schemaSlug   The schema.
			 * @param array<string, mixed> $filters      The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
				$this->asked = $filters;
				foreach ($filters as $value) {
					if (is_bool($value) === true) {
						return [];
					}
				}

				return [['id' => 'case-1', 'title' => 'Zaak van alice', 'assignee' => 'alice', 'isFinalStatus' => false]];
			}//end searchObjectsBySlug()
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				default => $default,
			}
		);

		$items = (new AssignedCasesSource(settings: $settings, l10n: $this->createMock(IL10N::class)))->itemsFor(userId: 'alice');

		$this->assertCount(1, $items, 'The open case was not found: the filter was '.json_encode($register->asked));
		$this->assertSame('Zaak van alice', $items[0]->title);
	}//end testAnOpenAssignedCaseIsFound()
}//end class
