<?php

/**
 * ScanVerdictController Unit Tests.
 *
 * Pins that a scan verdict is only read for a file the signed-in user can
 * reach in their own folder, and that every other case looks like a 404.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ScanVerdictController;
use OCA\Dossiq\Service\Documents\ScanVerdictReader;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for ScanVerdictController.
 *
 * @covers \OCA\Dossiq\Controller\ScanVerdictController
 */
class ScanVerdictControllerTest extends TestCase {

	/**
	 * The verdict reader double.
	 *
	 * @var ScanVerdictReader&MockObject
	 */
	private ScanVerdictReader&MockObject $verdicts;

	/**
	 * The root folder double.
	 *
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder&MockObject $root;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->verdicts = $this->createMock(ScanVerdictReader::class);
		$this->root = $this->createMock(IRootFolder::class);
	}//end setUp()

	/**
	 * Build the controller with alice signed in, or nobody.
	 *
	 * @param bool $signedIn Whether alice is signed in.
	 *
	 * @return ScanVerdictController
	 */
	private function controller(bool $signedIn = true): ScanVerdictController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		return new ScanVerdictController('dossiq', $this->createMock(IRequest::class), $this->verdicts, $this->root, $session);
	}//end controller()

	/**
	 * Without a user the answer is 401 and no folder is opened.
	 *
	 * @return void
	 */
	public function testShowRequiresAUser(): void {
		$this->root->expects($this->never())->method('getUserFolder');
		$this->verdicts->expects($this->never())->method('verdictFor');

		$response = $this->controller(signedIn: false)->show(7);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testShowRequiresAUser()

	/**
	 * A file the user can reach in their own folder gets its verdict.
	 *
	 * @return void
	 */
	public function testAReachableFileGetsItsVerdict(): void {
		$folder = $this->createMock(Folder::class);
		$folder->expects($this->once())->method('getFirstNodeById')->with(7)->willReturn($this->createMock(Node::class));
		$this->root->expects($this->once())->method('getUserFolder')->with('alice')->willReturn($folder);
		$this->verdicts->expects($this->once())->method('verdictFor')->with(7)->willReturn(['verdict' => 'clean']);

		$response = $this->controller()->show(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['verdict' => 'clean'], $response->getData());
	}//end testAReachableFileGetsItsVerdict()

	/**
	 * A non-positive id is a 404 without opening any folder.
	 *
	 * @return void
	 */
	public function testANonPositiveIdIsNotFoundWithoutAFolderLookup(): void {
		$this->root->expects($this->never())->method('getUserFolder');
		$this->verdicts->expects($this->never())->method('verdictFor');
		$controller = $this->controller();

		foreach ([0, -3] as $fileId) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $controller->show($fileId)->getStatus());
		}
	}//end testANonPositiveIdIsNotFoundWithoutAFolderLookup()

	/**
	 * An unreachable file, a non-positive id and a failing folder all answer 404
	 * without reading a verdict.
	 *
	 * @return void
	 */
	public function testEveryOtherFileLooksNotFound(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->willReturn(null);
		$this->root->method('getUserFolder')->willReturnCallback(
			static function () use ($folder): Folder {
				static $calls = 0;
				$calls++;
				if ($calls === 2) {
					throw new RuntimeException('storage gone');
				}

				return $folder;
			}
		);
		$this->verdicts->expects($this->never())->method('verdictFor');
		$controller = $this->controller();

		foreach ([7, 7, 0, -3] as $fileId) {
			$response = $controller->show($fileId);
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame(['error' => 'Not found'], $response->getData());
		}
	}//end testEveryOtherFileLooksNotFound()
}//end class
