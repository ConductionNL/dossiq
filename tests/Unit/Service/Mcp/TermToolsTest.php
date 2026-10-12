<?php

/**
 * Unit tests for the curated term tools (extend, pause, resume).
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Mcp
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Mcp;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\Mcp\TermTools;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The term tools load the term, check the CASE may be changed by the caller,
 * and call the term services the TermijnController calls.
 */
class TermToolsTest extends TestCase {

	use MakesCaseDateNormaliser;

	private IUserSession&MockObject $session;

	private CaseAccessGuard&MockObject $guard;

	private TermijnService&MockObject $terms;

	private DeadlineExtensionService&MockObject $extension;

	private DeadlinePauseService&MockObject $pause;

	private TermTools $tools;

	protected function setUp(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->terms = $this->createMock(TermijnService::class);
		$this->extension = $this->createMock(DeadlineExtensionService::class);
		$this->pause = $this->createMock(DeadlinePauseService::class);
		$this->tools = new TermTools(
			userSession: $this->session,
			caseAccess: $this->guard,
			terms: $this->terms,
			extension: $this->extension,
			pause: $this->pause,
			dates: $this->caseDates(),
		);
	}//end setUp()

	/**
	 * A signed-in caller.
	 *
	 * @return IUser
	 */
	private function signIn(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('henk');
		$this->session->method('getUser')->willReturn($user);

		return $user;
	}//end signIn()

	public function testNoSessionChangesNothing(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->extension->expects($this->never())->method('requestExtension');

		$this->assertSame('not_authenticated', $this->tools->extendDeadline(deadlineId: 'd-1', rationale: 'r', newEndDate: '2026-12-01')['error']);
	}//end testNoSessionChangesNothing()

	public function testAnUnknownTermIsNotFound(): void {
		$this->signIn();
		$this->terms->method('getTermijnInstance')->willReturn(null);
		$this->pause->expects($this->never())->method('registerPauze');

		$this->assertSame('deadline_not_found', $this->tools->pauseDeadline(deadlineId: 'd-404', durationDays: 14, rationale: 'r')['error']);
	}//end testAnUnknownTermIsNotFound()

	public function testATermOnACaseTheCallerMayNotChangeIsRefused(): void {
		$user = $this->signIn();
		$this->terms->method('getTermijnInstance')->willReturn(['id' => 'd-1', 'case' => 'c-1']);
		$this->guard->expects($this->once())->method('hasCaseMutationAccess')->with('c-1', $user)->willReturn(false);
		$this->pause->expects($this->never())->method('resumeAfterPauze');

		$this->assertSame('forbidden', $this->tools->resumeDeadline(deadlineId: 'd-1')['error']);
	}//end testATermOnACaseTheCallerMayNotChangeIsRefused()

	public function testExtendNormalisesTheDateAndCallsTheExtensionService(): void {
		$this->signIn();
		$this->terms->method('getTermijnInstance')->willReturn(['id' => 'd-1', 'case' => 'c-1']);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->extension->expects($this->once())->method('requestExtension')
			->with('d-1', 'Advies van de commissie nodig', '2026-12-01', '')
			->willReturn(['id' => 'd-1', 'extensionCount' => 1]);

		$result = $this->tools->extendDeadline(deadlineId: 'd-1', rationale: 'Advies van de commissie nodig', newEndDate: '2026-12-01T10:00:00+01:00');

		$this->assertSame(1, $result['extensionCount']);
	}//end testExtendNormalisesTheDateAndCallsTheExtensionService()

	public function testAnUnreadableDateIsRefusedBeforeTheService(): void {
		$this->signIn();
		$this->terms->method('getTermijnInstance')->willReturn(['id' => 'd-1', 'case' => 'c-1']);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->extension->expects($this->never())->method('requestExtension');

		$this->assertSame('invalid_date', $this->tools->extendDeadline(deadlineId: 'd-1', rationale: 'r', newEndDate: 'next tuesday-ish')['error']);
	}//end testAnUnreadableDateIsRefusedBeforeTheService()

	public function testPauseCallsThePauseServiceWithTheReason(): void {
		$this->signIn();
		$this->terms->method('getTermijnInstance')->willReturn(['id' => 'd-1', 'case' => 'c-1']);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->pause->expects($this->once())->method('registerPauze')
			->with('d-1', 14, 'Aanvulling gevraagd', '', 'awaiting-applicant')
			->willReturn(['id' => 'd-1', 'status' => 'paused']);

		$this->assertSame('paused', $this->tools->pauseDeadline(deadlineId: 'd-1', durationDays: 14, rationale: 'Aanvulling gevraagd', pauseReason: 'awaiting-applicant')['status']);
	}//end testPauseCallsThePauseServiceWithTheReason()

	public function testAServiceRefusalIsAnEnvelope(): void {
		$this->signIn();
		$this->terms->method('getTermijnInstance')->willReturn(['id' => 'd-1', 'case' => 'c-1']);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->pause->method('registerPauze')->willThrowException(new RuntimeException('Pause duration must be positive (AWB 4:5)'));

		$this->assertSame(
			['error' => 'refused', 'message' => 'Pause duration must be positive (AWB 4:5)'],
			$this->tools->pauseDeadline(deadlineId: 'd-1', durationDays: 0, rationale: 'r')
		);
	}//end testAServiceRefusalIsAnEnvelope()

	public function testResumePassesTheParsedDateOrNone(): void {
		$this->signIn();
		$this->terms->method('getTermijnInstance')->willReturn(['id' => 'd-1', 'case' => ['id' => 'c-1']]);
		$this->guard->method('hasCaseMutationAccess')->with('c-1')->willReturn(true);
		$seen = [];
		$this->pause->method('resumeAfterPauze')->willReturnCallback(
			function (string $id, ?DateTimeImmutable $when) use (&$seen): array {
				$seen[] = $when?->format('Y-m-d');
				return ['id' => $id, 'status' => 'running'];
			}
		);

		$this->tools->resumeDeadline(deadlineId: 'd-1');
		$this->tools->resumeDeadline(deadlineId: 'd-1', resumeDate: '2026-11-03');

		$this->assertSame([null, '2026-11-03'], $seen);
	}//end testResumePassesTheParsedDateOrNone()
}//end class
