<?php

/**
 * CallerReadable Unit Tests
 *
 * A file is looked up in the caller's own folder and handed over only when it
 * is a readable file; a case is readable only when the guard says so, and an
 * empty case id never is.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Documents
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

namespace OCA\Dossiq\Tests\Unit\Service\Documents;

use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Service\Documents\CallerReadable;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CallerReadable.
 *
 * @covers \OCA\Dossiq\Service\Documents\CallerReadable
 */
final class CallerReadableTest extends TestCase {

	/**
	 * The caller, made once per test.
	 *
	 * @var IUser|null
	 */
	private ?IUser $caller = null;

	/**
	 * Only a readable file in the caller's own folder is handed over; a folder or an unreadable file is not.
	 *
	 * @return void
	 */
	public function testOnlyAReadableFileOfTheCallerIsHandedOver(): void {
		$readable = $this->createMock(originalClassName: File::class);
		$readable->method('isReadable')->willReturn(true);
		$unreadable = $this->createMock(originalClassName: File::class);
		$unreadable->method('isReadable')->willReturn(false);
		$folder = $this->createMock(originalClassName: Folder::class);
		$folder->method('getById')->willReturnMap(
			[
				[7, [$this->createMock(originalClassName: Folder::class), $readable]],
				[8, [$unreadable]],
				[9, []],
			]
		);
		$root = $this->createMock(originalClassName: IRootFolder::class);
		$root->expects($this->exactly(count: 3))->method('getUserFolder')->with('anna')->willReturn($folder);
		$calls = new CallerReadable(rootFolder: $root, accessGuard: $this->createMock(originalClassName: CaseAccessGuardInterface::class));

		$this->assertSame(expected: $readable, actual: $calls->file(user: $this->user(), fileId: 7));
		$this->assertNull(actual: $calls->file(user: $this->user(), fileId: 8));
		$this->assertNull(actual: $calls->file(user: $this->user(), fileId: 9));
	}//end testOnlyAReadableFileOfTheCallerIsHandedOver()

	/**
	 * A case is readable as the guard says; an empty id is never asked about.
	 *
	 * @return void
	 */
	public function testACaseIsReadableAsTheGuardSays(): void {
		$guard = $this->createMock(originalClassName: CaseAccessGuardInterface::class);
		$guard->expects($this->exactly(count: 2))->method('hasCaseReadAccess')->willReturnMap(
			[
				['case-1', $this->user(), true],
				['case-2', $this->user(), false],
			]
		);
		$calls = new CallerReadable(rootFolder: $this->createMock(originalClassName: IRootFolder::class), accessGuard: $guard);

		$this->assertTrue(condition: $calls->mayReadCase(user: $this->user(), caseId: 'case-1'));
		$this->assertFalse(condition: $calls->mayReadCase(user: $this->user(), caseId: 'case-2'));
		$this->assertFalse(condition: $calls->mayReadCase(user: $this->user(), caseId: ''));
	}//end testACaseIsReadableAsTheGuardSays()

	/**
	 * The caller, anna; one instance, so a return map matches it.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		if ($this->caller === null) {
			$this->caller = $this->createMock(originalClassName: IUser::class);
			$this->caller->method('getUID')->willReturn('anna');
		}

		return $this->caller;
	}//end user()
}//end class
