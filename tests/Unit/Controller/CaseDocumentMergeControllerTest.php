<?php

/**
 * Merging a case's documents into one PDF goes through filinq, after the
 * caller's right to add to the case is checked.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 * @spec openspec/specs/documents-merge-pdf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseDocumentMergeController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\FilinqMergeClient;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\CaseDocumentMergeController
 */
class CaseDocumentMergeControllerTest extends TestCase {

	/**
	 * The controller over a merge client, for a user who may or may not add to the case.
	 *
	 * @param FilinqMergeClient&MockObject $merges The filinq client.
	 * @param bool                         $mutate Whether the user may add to the case.
	 *
	 * @return CaseDocumentMergeController
	 */
	private function controller(FilinqMergeClient&MockObject $merges, bool $mutate = true): CaseDocumentMergeController {
		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturn(value: $mutate);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(value: $this->createMock(originalClassName: IUser::class));
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(argumentIndex: 0);

		return new CaseDocumentMergeController(
			request: $this->createMock(originalClassName: IRequest::class),
			caseAccessGuard: $guard,
			userSession: $session,
			merges: $merges,
			l10n: $l10n,
		);
	}//end controller()

	/**
	 * The files go to filinq in the order chosen, with the case as host.
	 *
	 * @return void
	 */
	public function testMergesTheChosenFilesThroughFilinq(): void {
		$merges = $this->createMock(originalClassName: FilinqMergeClient::class);
		$merges->expects($this->once())->method('merge')
			->with([11, 12], 'Stukken.pdf', 'case-1')
			->willReturn(value: ['status' => 'done', 'resultFileId' => 99]);

		$response = $this->controller(merges: $merges)->merge(caseId: 'case-1', fileIds: ['11', 12, 'x', 0], name: 'Stukken.pdf');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: 99, actual: $response->getData()['resultFileId']);
	}//end testMergesTheChosenFilesThroughFilinq()

	/**
	 * A caller who may not add to the case is refused before filinq is asked.
	 *
	 * @return void
	 */
	public function testRefusesACallerWhoMayNotAddToTheCase(): void {
		$merges = $this->createMock(originalClassName: FilinqMergeClient::class);
		$merges->expects($this->never())->method('merge');

		$response = $this->controller(merges: $merges, mutate: false)->merge(caseId: 'case-1', fileIds: [11, 12]);

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testRefusesACallerWhoMayNotAddToTheCase()

	/**
	 * Without filinq the answer says so.
	 *
	 * @return void
	 */
	public function testSaysSoWhenFilinqIsAbsent(): void {
		$merges = $this->createMock(originalClassName: FilinqMergeClient::class);
		$merges->method('merge')->willThrowException(exception: new RuntimeException(message: 'filinq_unavailable: merge'));

		$response = $this->controller(merges: $merges)->merge(caseId: 'case-1', fileIds: [11, 12]);

		self::assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		self::assertSame(expected: 'filinq-unavailable', actual: $response->getData()['error']);
	}//end testSaysSoWhenFilinqIsAbsent()

	/**
	 * Fewer than two files is not a merge.
	 *
	 * @return void
	 */
	public function testRefusesFewerThanTwoFiles(): void {
		$merges = $this->createMock(originalClassName: FilinqMergeClient::class);
		$merges->expects($this->never())->method('merge');

		$response = $this->controller(merges: $merges)->merge(caseId: 'case-1', fileIds: [11]);

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
	}//end testRefusesFewerThanTwoFiles()
}//end class
