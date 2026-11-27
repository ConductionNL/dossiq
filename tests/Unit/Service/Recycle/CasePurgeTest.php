<?php

/**
 * CasePurge Unit Tests
 *
 * A destroyed case is deleted permanently from the configured register and
 * case schema; without OpenRegister the purge is refused.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Recycle
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Recycle;

use OCA\Dossiq\Service\Recycle\CasePurge;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for CasePurge.
 *
 * @covers \OCA\Dossiq\Service\Recycle\CasePurge
 */
final class CasePurgeTest extends TestCase {

	/**
	 * Settings answering the given object service and the configured register and case schema.
	 *
	 * @param object|null $objectService The object service, or null without OpenRegister.
	 *
	 * @return SettingsService
	 */
	private function settings(?object $objectService): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? ''
		);
		return $settings;
	}//end settings()

	/**
	 * The case is deleted permanently, by uuid, in the configured register and case schema.
	 *
	 * @return void
	 */
	public function testTheCaseIsDeletedPermanently(): void {
		$store = new class {
			/**
			 * The deletions, in order.
			 *
			 * @var array<int, ObjectDeletion>
			 */
			public array $deletions = [];

			/**
			 * Record the deletion.
			 *
			 * @param ObjectDeletion $deletion What to delete.
			 *
			 * @return bool
			 */
			public function deleteObject(ObjectDeletion $deletion): bool {
				$this->deletions[] = $deletion;
				return true;
			}
		};

		(new CasePurge(settingsService: $this->settings(objectService: $store)))->delete(caseId: 'case-9');

		$this->assertCount(expectedCount: 1, haystack: $store->deletions);
		$deletion = $store->deletions[0];
		$this->assertSame(
			expected: ['case-9', 'dossiq', 'case', true],
			actual: [$deletion->uuid, $deletion->target->register, $deletion->target->schema, $deletion->permanent]
		);
	}//end testTheCaseIsDeletedPermanently()

	/**
	 * Without OpenRegister the purge is refused.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterThePurgeIsRefused(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'openregister_unavailable');

		(new CasePurge(settingsService: $this->settings(objectService: null)))->delete(caseId: 'case-9');
	}//end testWithoutOpenRegisterThePurgeIsRefused()
}//end class
