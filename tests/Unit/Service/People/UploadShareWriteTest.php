<?php

/**
 * UploadShareWrite Unit Tests
 *
 * The share a file request is sent as: an e-mail share of the case folder
 * that may only create, owned by the folder's owner (the caller when the
 * folder has none), with the note only when there is one; its token answered,
 * and a refusal of the share manager reported as a 500.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
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

namespace OCA\Dossiq\Tests\Unit\Service\People;

use DateTime;
use OCA\Dossiq\Service\People\UploadRequest;
use OCA\Dossiq\Service\People\UploadShareWrite;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for UploadShareWrite.
 *
 * @covers \OCA\Dossiq\Service\People\UploadShareWrite
 * @uses   \OCA\Dossiq\Service\People\UploadRequest
 */
final class UploadShareWriteTest extends TestCase {

	/**
	 * What was set on the share, by setter.
	 *
	 * @var array<string, mixed>
	 */
	private array $set = [];

	/**
	 * A share that records what is set on it and answers a token.
	 *
	 * @return IShare
	 */
	private function share(): IShare {
		$share = $this->createMock(originalClassName: IShare::class);
		$setters = ['setNode', 'setShareType', 'setSharedWith', 'setSharedBy', 'setShareOwner', 'setPermissions', 'setExpirationDate', 'setNote'];
		foreach ($setters as $setter) {
			$share->method($setter)->willReturnCallback(
				function (mixed $value) use ($setter, $share): IShare {
					$this->set[lcfirst(string: substr(string: $setter, offset: 3))] = $value;
					return $share;
				}
			);
		}

		$share->method('getToken')->willReturn('tok-1');
		return $share;
	}//end share()

	/**
	 * A checked request over a folder with or without an owner.
	 *
	 * @param string|null $folderOwner The folder owner's uid, or null for none.
	 * @param string      $note        The note.
	 *
	 * @return UploadRequest
	 */
	private function request(?string $folderOwner, string $note): UploadRequest {
		$caller = $this->createMock(originalClassName: IUser::class);
		$caller->method('getUID')->willReturn('behandelaar');
		$owner = null;
		if ($folderOwner !== null) {
			$owner = $this->createMock(originalClassName: IUser::class);
			$owner->method('getUID')->willReturn($folderOwner);
		}

		$folder = $this->createMock(originalClassName: Folder::class);
		$folder->method('getOwner')->willReturn($owner);

		$request = new UploadRequest();
		$request->folder = $folder;
		$request->email = 'piet@example.nl';
		$request->user = $caller;
		$request->expires = new DateTime('2026-12-11');
		$request->note = $note;
		return $request;
	}//end request()

	/**
	 * The share is a create-only e-mail share of the folder, owned by its owner, and its token is answered.
	 *
	 * @return void
	 */
	public function testTheShareIsACreateOnlyEmailShareAndItsTokenIsAnswered(): void {
		$share = $this->share();
		$shares = $this->createMock(originalClassName: IShareManager::class);
		$shares->method('newShare')->willReturn($share);
		$shares->expects($this->once())->method('createShare')->with($share)->willReturn($share);
		$request = $this->request(folderOwner: 'admin', note: 'The lease, please');

		$token = (new UploadShareWrite(shares: $shares))->send(request: $request);

		$this->assertSame(expected: 'tok-1', actual: $token);
		$this->assertSame(expected: $request->folder, actual: $this->set['node']);
		$this->assertSame(expected: IShare::TYPE_EMAIL, actual: $this->set['shareType']);
		$this->assertSame(expected: 'piet@example.nl', actual: $this->set['sharedWith']);
		$this->assertSame(expected: 'behandelaar', actual: $this->set['sharedBy']);
		$this->assertSame(expected: 'admin', actual: $this->set['shareOwner']);
		$this->assertSame(expected: Constants::PERMISSION_CREATE, actual: $this->set['permissions']);
		$this->assertSame(expected: $request->expires, actual: $this->set['expirationDate']);
		$this->assertSame(expected: 'The lease, please', actual: $this->set['note']);
	}//end testTheShareIsACreateOnlyEmailShareAndItsTokenIsAnswered()

	/**
	 * A folder without an owner is shared as the caller's, and no note means none on the share.
	 *
	 * @return void
	 */
	public function testAnOwnerlessFolderIsSharedAsTheCallersWithoutANote(): void {
		$share = $this->share();
		$shares = $this->createMock(originalClassName: IShareManager::class);
		$shares->method('newShare')->willReturn($share);
		$shares->method('createShare')->willReturn($share);

		(new UploadShareWrite(shares: $shares))->send(request: $this->request(folderOwner: null, note: ''));

		$this->assertSame(expected: 'behandelaar', actual: $this->set['shareOwner']);
		$this->assertArrayNotHasKey(key: 'note', array: $this->set);
	}//end testAnOwnerlessFolderIsSharedAsTheCallersWithoutANote()

	/**
	 * A share the manager refuses is reported as a 500 naming the reason.
	 *
	 * @return void
	 */
	public function testARefusedShareIsReported(): void {
		$shares = $this->createMock(originalClassName: IShareManager::class);
		$shares->method('newShare')->willReturn($this->share());
		$shares->method('createShare')->willThrowException(new RuntimeException('sharing by mail is disabled'));

		try {
			(new UploadShareWrite(shares: $shares))->send(request: $this->request(folderOwner: 'admin', note: ''));
			$this->fail(message: 'a refused share must be reported');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 500, actual: $e->getCode());
			$this->assertSame(expected: 'The file request could not be sent: sharing by mail is disabled', actual: $e->getMessage());
		}
	}//end testARefusedShareIsReported()
}//end class
