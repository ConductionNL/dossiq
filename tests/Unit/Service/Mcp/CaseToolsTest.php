<?php

/**
 * CaseTools checks the caller may read the case before it lists transitions.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Mcp;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Mcp\CaseTools;
use OCA\Dossiq\Service\StatusTransitionService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The case-scoped curated tools.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
 */
class CaseToolsTest extends TestCase {

	/**
	 * Session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * Case access guard mock.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * Transition engine mock.
	 *
	 * @var StatusTransitionService&MockObject
	 */
	private StatusTransitionService $transitions;

	/**
	 * The subject.
	 *
	 * @var CaseTools
	 */
	private CaseTools $tools;

	/**
	 * Build the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->transitions = $this->createMock(StatusTransitionService::class);
		$this->tools = new CaseTools(
			userSession: $this->session,
			caseAccess: $this->guard,
			transitions: $this->transitions,
		);
	}//end setUp()

	/**
	 * Without a session nothing is read.
	 *
	 * @return void
	 */
	public function testNoSessionReadsNothing(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->transitions->expects($this->never())->method('getAvailableTransitions');

		$this->assertSame('not_authenticated', $this->tools->listAvailableTransitions(caseId: 'c-1')['error']);
	}//end testNoSessionReadsNothing()

	/**
	 * A case the caller may not read does not even give away its status.
	 *
	 * @return void
	 */
	public function testACaseTheCallerMayNotReadIsRefused(): void {
		$user = $this->user(uid: 'henk');
		$this->guard->expects($this->once())->method('hasCaseReadAccess')->with('c-1', $user)->willReturn(false);
		$this->transitions->expects($this->never())->method('getAvailableTransitions');

		$this->assertSame('forbidden', $this->tools->listAvailableTransitions(caseId: 'c-1')['error']);
	}//end testACaseTheCallerMayNotReadIsRefused()

	/**
	 * A readable case lists the transitions the engine allows THIS caller.
	 *
	 * @return void
	 */
	public function testAReadableCaseListsTheCallersTransitions(): void {
		$this->user(uid: 'henk');
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$answer = ['transitions' => [['to' => 's-2']], 'current' => ['id' => 's-1']];
		$this->transitions->expects($this->once())->method('getAvailableTransitions')
			->with('c-1', 'henk')
			->willReturn($answer);

		$this->assertSame($answer, $this->tools->listAvailableTransitions(caseId: 'c-1'));
	}//end testAReadableCaseListsTheCallersTransitions()

	/**
	 * Put a user in the session.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser&MockObject
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
		return $user;
	}//end user()
}//end class
