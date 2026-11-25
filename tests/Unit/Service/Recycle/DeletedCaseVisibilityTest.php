<?php

/**
 * Unit tests for who may see a deleted case in the recycle lens.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Recycle
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Recycle;

use OCA\Dossiq\Service\Recycle\DeletedCaseVisibility;
use OCA\Dossiq\Service\Recycle\DeletionWindowReader;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Recycle\DeletedCaseVisibility
 * @uses   \OCA\Dossiq\Service\Recycle\DeletionWindowReader
 */
class DeletedCaseVisibilityTest extends TestCase {

	/**
	 * A deleted row, deleted by Anna and assigned to Bram.
	 *
	 * @return object The row.
	 */
	private function row(): object {
		return new class {
			/**
			 * The deletion marker.
			 *
			 * @return array<string, string> The marker.
			 */
			public function getDeleted(): array {
				return ['deletedBy' => 'anna'];
			}

			/**
			 * The payload.
			 *
			 * @return array<string, string> The payload.
			 */
			public function getObject(): array {
				return ['assignee' => 'bram'];
			}
		};
	}//end row()

	/**
	 * The admin, the deleter and the assignee see the case; anyone else and an unknown caller do not.
	 *
	 * @return void
	 */
	public function testOnlyTheAdminTheDeleterAndTheAssigneeSeeIt(): void {
		$visibility = new DeletedCaseVisibility(
			windowReader: new DeletionWindowReader(
				settingsService: $this->createMock(originalClassName: SettingsService::class),
				logger: $this->createMock(originalClassName: LoggerInterface::class)
			)
		);

		self::assertTrue(condition: $visibility->maySee(entity: $this->row(), userId: 'carla', isAdmin: true));
		self::assertTrue(condition: $visibility->maySee(entity: $this->row(), userId: 'anna', isAdmin: false));
		self::assertTrue(condition: $visibility->maySee(entity: $this->row(), userId: 'bram', isAdmin: false));
		self::assertFalse(condition: $visibility->maySee(entity: $this->row(), userId: 'carla', isAdmin: false));
		self::assertFalse(condition: $visibility->maySee(entity: $this->row(), userId: '', isAdmin: false));
	}//end testOnlyTheAdminTheDeleterAndTheAssigneeSeeIt()
}//end class
