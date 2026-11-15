<?php

/**
 * Who may read or change the document review of a case.
 *
 * Golden over DocumentReviewController before its endpoints share their
 * gates: no session user is 401 with nothing asked; summary, batches and
 * pagesSeen need read access to the case, relevance and createBatch need
 * mutation access, and without it the call is 403 with the no-access
 * sentence and nothing read or written.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DocumentReviewController;
use OCA\Dossiq\Review\BatchSelection;
use OCA\Dossiq\Review\ReviewBatches;
use OCA\Dossiq\Review\ReviewSummary;
use OCA\Dossiq\Review\ReviewWrites;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The review endpoints' refusals.
 *
 * @covers \OCA\Dossiq\Controller\DocumentReviewController
 *
 * @spec openspec/specs/case-management/spec.md
 */
class DocumentReviewGateGoldenTest extends TestCase {
	/**
	 * The access guard double.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The review doubles, by name.
	 *
	 * @var array<string, MockObject>
	 */
	private array $review = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->review = [
			'writes' => $this->createMock(originalClassName: ReviewWrites::class),
			'summary' => $this->createMock(originalClassName: ReviewSummary::class),
			'batches' => $this->createMock(originalClassName: ReviewBatches::class),
			'selection' => $this->createMock(originalClassName: BatchSelection::class),
		];
	}//end setUp()

	/**
	 * Every endpoint with the access it needs.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function endpoints(): array {
		return [
			'summary' => ['summary', 'hasCaseReadAccess'],
			'relevance' => ['relevance', 'hasCaseMutationAccess'],
			'batches' => ['batches', 'hasCaseReadAccess'],
			'create batch' => ['createBatch', 'hasCaseMutationAccess'],
			'pages seen' => ['pagesSeen', 'hasCaseReadAccess'],
		];
	}//end endpoints()

	/**
	 * Without a session user the call is 401 and nothing is asked.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testWithoutAUserTheCallIsUnauthenticated(string $endpoint): void {
		$this->session->method('getUser')->willReturn(null);
		$this->guard->expects(self::never())->method(self::anything());
		$this->expectNoReviewWork();

		self::assertSame(expected: [401, ['error' => 'not-authenticated']], actual: $this->answer(endpoint: $endpoint));
	}//end testWithoutAUserTheCallIsUnauthenticated()

	/**
	 * Without the access the endpoint needs the call is 403 and nothing is read or written.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $access   The access it needs.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testWithoutTheNeededAccessTheCallIsForbidden(string $endpoint, string $access): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$this->session->method('getUser')->willReturn($user);
		$this->guard->expects(self::once())->method($access)->with('c-1', $user)->willReturn(false);
		$other = ['hasCaseReadAccess' => 'hasCaseMutationAccess', 'hasCaseMutationAccess' => 'hasCaseReadAccess'][$access];
		$this->guard->expects(self::never())->method($other);
		$this->expectNoReviewWork();

		self::assertSame(
			expected: [403, ['error' => 'no-case-access', 'message' => 'You do not have access to this case']],
			actual: $this->answer(endpoint: $endpoint)
		);
	}//end testWithoutTheNeededAccessTheCallIsForbidden()

	/**
	 * With the access the endpoint needs, the call goes past the gate.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $access   The access it needs.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testWithTheNeededAccessTheCallIsServed(string $endpoint, string $access): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('sanne');
		$this->session->method('getUser')->willReturn($user);
		$this->guard->expects(self::once())->method($access)->with('c-1', $user)->willReturn(true);
		$this->review['summary']->method('forCase')->willReturn(['documents' => 0]);
		$this->review['summary']->method('assessed')->willReturn([]);
		$this->review['batches']->method('forCase')->willReturn([]);
		$this->review['batches']->method('taken')->willReturn([]);
		$this->review['batches']->method('create')->willReturn(['id' => 'b-1']);
		$this->review['selection']->method('select')->willReturn([]);
		$this->review['writes']->method('mark')->willReturn(['marked' => true]);
		$this->review['writes']->method('pagesSeen')->willReturn(['seen' => true]);

		[$status] = $this->answer(endpoint: $endpoint);

		self::assertContains(needle: $status, haystack: [200, 201]);
	}//end testWithTheNeededAccessTheCallIsServed()

	/**
	 * No review collaborator is asked anything.
	 *
	 * @return void
	 */
	private function expectNoReviewWork(): void {
		foreach ($this->review as $double) {
			$double->expects(self::never())->method(self::anything());
		}
	}//end expectNoReviewWork()

	/**
	 * The status and data of one endpoint's answer for case c-1.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return array{0: int, 1: mixed} Status and data.
	 */
	private function answer(string $endpoint): array {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$controller = new DocumentReviewController(
			request: $this->createMock(originalClassName: IRequest::class),
			writes: $this->review['writes'],
			summary: $this->review['summary'],
			batches: $this->review['batches'],
			selection: $this->review['selection'],
			caseAccessGuard: $this->guard,
			userSession: $this->session,
			l10n: $l10n,
		);
		$arguments = ['id' => 'c-1'];
		if (in_array($endpoint, ['relevance', 'pagesSeen'], true) === true) {
			$arguments['documentRef'] = 'd-1';
		}

		$response = $controller->$endpoint(...$arguments);

		return [$response->getStatus(), $response->getData()];
	}//end answer()
}//end class
