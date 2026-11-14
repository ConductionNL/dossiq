<?php

/**
 * FileRestrictionAdmitListener unit tests
 *
 * OpenRegister asks, per restricted file, whether dossiq admits a person who
 * is not on the file's restriction. Dossiq admits only the handler of a case
 * the file's document belongs to; an unknown person, a file that is no
 * document, a foreign event and any failure admit nobody.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\FileRestrictionAdmitListener;
use OCA\Dossiq\Service\Zaakdossier\DocumentAccessExceptionRule;
use OCA\Dossiq\Service\Zaakdossier\FileDocumentLookup;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the restricted-file admit answer.
 *
 * @covers \OCA\Dossiq\Listener\FileRestrictionAdmitListener
 */
class FileRestrictionAdmitListenerTest extends TestCase {

	/**
	 * The mocked file-to-document lookup.
	 *
	 * @var FileDocumentLookup|MockObject
	 */
	private FileDocumentLookup $files;

	/**
	 * The mocked handler rule.
	 *
	 * @var DocumentAccessExceptionRule|MockObject
	 */
	private DocumentAccessExceptionRule $rule;

	/**
	 * The mocked user manager.
	 *
	 * @var IUserManager|MockObject
	 */
	private IUserManager $users;

	/**
	 * Build the collaborators: hanna exists, file 42 is doc-offerte.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->files = $this->createMock(FileDocumentLookup::class);
		$this->files->method('recordIdOfFile')->willReturnCallback(
			static fn (int $fileId): string => ($fileId === 42 ? 'doc-offerte' : '')
		);
		$this->rule = $this->createMock(DocumentAccessExceptionRule::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid !== 'hanna') {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			}
		);
	}//end setUp()

	/**
	 * The handler of the document's case is admitted, asked about that document.
	 *
	 * @return void
	 */
	public function testTheCaseHandlerIsAdmitted(): void {
		$this->rule->expects($this->once())->method('handlesACaseOf')->with(
			$this->callback(static fn (IUser $user): bool => $user->getUID() === 'hanna'),
			'doc-offerte'
		)->willReturn(true);

		$event = $this->admitEvent(fileId: 42, uid: 'hanna');
		$this->listener()->handle($event);

		$this->assertTrue($event->isAdmitted());
	}//end testTheCaseHandlerIsAdmitted()

	/**
	 * Someone who does not handle the case is not admitted.
	 *
	 * @return void
	 */
	public function testANonHandlerIsNotAdmitted(): void {
		$this->rule->method('handlesACaseOf')->willReturn(false);

		$event = $this->admitEvent(fileId: 42, uid: 'hanna');
		$this->listener()->handle($event);

		$this->assertFalse($event->isAdmitted());
	}//end testANonHandlerIsNotAdmitted()

	/**
	 * A person the user manager does not know is refused before any lookup.
	 *
	 * @return void
	 */
	public function testAnUnknownPersonIsNotAdmitted(): void {
		$this->files->expects($this->never())->method('recordIdOfFile');
		$this->rule->expects($this->never())->method('handlesACaseOf');

		$event = $this->admitEvent(fileId: 42, uid: 'ghost');
		$this->listener()->handle($event);

		$this->assertFalse($event->isAdmitted());
	}//end testAnUnknownPersonIsNotAdmitted()

	/**
	 * A file that belongs to no document never reaches the rule.
	 *
	 * @return void
	 */
	public function testAFileOfNoDocumentIsNotAdmitted(): void {
		$this->rule->expects($this->never())->method('handlesACaseOf');

		$event = $this->admitEvent(fileId: 99, uid: 'hanna');
		$this->listener()->handle($event);

		$this->assertFalse($event->isAdmitted());
	}//end testAFileOfNoDocumentIsNotAdmitted()

	/**
	 * A failing rule admits nobody and does not escape.
	 *
	 * @return void
	 */
	public function testAFailingRuleAdmitsNobody(): void {
		$this->rule->method('handlesACaseOf')->willThrowException(new RuntimeException('register down'));

		$event = $this->admitEvent(fileId: 42, uid: 'hanna');
		$this->listener()->handle($event);

		$this->assertFalse($event->isAdmitted());
	}//end testAFailingRuleAdmitsNobody()

	/**
	 * Any other event is ignored without looking anything up.
	 *
	 * @return void
	 */
	public function testAForeignEventIsIgnored(): void {
		$this->users->expects($this->never())->method('get');

		$this->listener()->handle(new Event());
	}//end testAForeignEventIsIgnored()

	/**
	 * The listener over the shared collaborators.
	 *
	 * @return FileRestrictionAdmitListener
	 */
	private function listener(): FileRestrictionAdmitListener {
		return new FileRestrictionAdmitListener(files: $this->files, rule: $this->rule, userManager: $this->users);
	}//end listener()

	/**
	 * OpenRegister's event, as a double carrying the same methods.
	 *
	 * @param int    $fileId The file.
	 * @param string $uid    The person asking.
	 *
	 * @return Event
	 */
	private function admitEvent(int $fileId, string $uid): Event {
		if (class_exists(FileRestrictionAdmitListener::EVENT) === false) {
			eval('namespace OCA\OpenRegister\Event; class FileRestrictionAdmitEvent extends \OCP\EventDispatcher\Event {'
				. ' private bool $admitted = false;'
				. ' public function __construct(private int $fileId, private string $userId, private array $restriction = []) {}'
				. ' public function getFileId(): int { return $this->fileId; }'
				. ' public function getUserId(): string { return $this->userId; }'
				. ' public function admit(): void { $this->admitted = true; }'
				. ' public function isAdmitted(): bool { return $this->admitted; } }');
		}

		$class = FileRestrictionAdmitListener::EVENT;

		return new $class($fileId, $uid, ['users' => ['pieter']]);
	}//end admitEvent()
}//end class
