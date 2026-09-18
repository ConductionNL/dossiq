<?php

/**
 * The door to the version chain and to moving a case along it.
 *
 * 🔴 EVERY ASSERTION ABOUT THE MOVE IS ABOUT THE LEAST PRIVILEGED PRINCIPAL
 * THAT SHOULD BE REFUSED, not about the administrator who would succeed at
 * anything. Moving somebody else's case onto another version of its type
 * rewrites the statuses and the fields that case is governed by, and
 * `#[NoAdminRequired]` with no per-object check is exactly the IDOR shape
 * gate-6 exists for. So the first question asked of both case endpoints is
 * whether a caller the guard refuses reaches the service at all.
 *
 * The chain READ is deliberately not guarded per object, and that is asserted
 * too: it answers the case type catalogue, which the new-case form already
 * shows to ordinary users, and OpenRegister applies its own read authority
 * underneath. A test that only ever checks for refusals cannot tell a guarded
 * endpoint from one nobody can reach.
 *
 * The work behind the door is asserted in
 * `tests/Unit/Service/CaseType/CaseVersionMoveTest.php`; here the question is
 * only whether the refusal reaches the caller intact and whether the act is
 * passed on unchanged.
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
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseVersionController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseType\CaseTypeVersionChain;
use OCA\Dossiq\Service\CaseType\CaseTypeVersionWindow;
use OCA\Dossiq\Service\CaseType\CaseVersionMove;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The chain, the deprecate, the options and the move, each behind its guard.
 *
 * @covers \OCA\Dossiq\Controller\CaseVersionController
 *
 * @uses \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */
class CaseVersionControllerTest extends TestCase {

	/**
	 * The chain reader.
	 *
	 * @var CaseTypeVersionChain&MockObject
	 */
	private CaseTypeVersionChain $chain;

	/**
	 * What a move would change, and the move.
	 *
	 * @var CaseVersionMove&MockObject
	 */
	private CaseVersionMove $move;

	/**
	 * When a version starts and stops being offered.
	 *
	 * @var CaseTypeVersionWindow&MockObject
	 */
	private CaseTypeVersionWindow $window;

	/**
	 * The per-case guard, which fails closed.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The request, answering the posted body.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The posted body, per test.
	 *
	 * @var array<string, mixed>
	 */
	private array $body = [];

	/**
	 * A signed-in handler, a guard that allows, and an empty body.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->chain = $this->createMock(originalClassName: CaseTypeVersionChain::class);
		$this->move = $this->createMock(originalClassName: CaseVersionMove::class);
		$this->window = $this->createMock(originalClassName: CaseTypeVersionWindow::class);
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->request = $this->createMock(originalClassName: IRequest::class);

		$this->signedInAs(uid: 'jan');
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->body[$key] ?? $default)
		);
	}//end setUp()

	/**
	 * The controller under test, over the current doubles.
	 *
	 * @return CaseVersionController The controller.
	 */
	private function controller(): CaseVersionController {
		return new CaseVersionController(
			appName: 'dossiq',
			request: $this->request,
			chain: $this->chain,
			move: $this->move,
			window: $this->window,
			accessGuard: $this->guard,
			userSession: $this->userSession,
			logger: new NullLogger(),
		);
	}//end controller()

	/**
	 * Put a signed-in user in the session.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signedInAs(string $uid): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signedInAs()

	/**
	 * The chain answers every version of the case type.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md#requirement-the-case-type-page-shows-its-version-chain-req-zv-04
	 */
	public function testTheChainAnswersTheVersions(): void {
		$this->chain->method('versionsOf')->willReturn(
			[['id' => 'ct-2', 'version' => 2], ['id' => 'ct-1', 'version' => 1]]
		);

		$response = $this->controller()->chain(id: 'ct-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertCount(2, $response->getData()['versions']);
	}//end testTheChainAnswersTheVersions()

	/**
	 * A case type nothing can read answers 404, not an empty chain.
	 *
	 * An empty list would read as "this case type has no versions", which is
	 * not a thing that can be true: a case type is at least a version of itself.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md#requirement-the-case-type-page-shows-its-version-chain-req-zv-04
	 */
	public function testAnUnreadableCaseTypeAnswersNotFound(): void {
		$this->chain->method('versionsOf')->willReturn([]);

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->chain(id: 'nope')->getStatus());
	}//end testAnUnreadableCaseTypeAnswersNotFound()

	/**
	 * 🔴 A caller the per-case guard refuses never reaches the move service.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md#requirement-a-running-case-moves-to-another-version-only-as-a-named-act-req-zv-09
	 */
	public function testACallerWithoutCaseAccessCannotMoveTheCase(): void {
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->move->expects(self::never())->method('move');
		$this->body = ['target' => 'ct-2', 'reason' => 'Nieuwe regels'];

		$response = $this->controller()->moveToVersion(caseId: 'case-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('case-access-denied', $response->getData()['error']);
	}//end testACallerWithoutCaseAccessCannotMoveTheCase()

	/**
	 * 🔴 The same guard stands in front of the READ of a case's options.
	 *
	 * The list of versions a case could move to names the case types it runs
	 * under, so an unguarded read is an enumeration of somebody else's work.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md#requirement-a-running-case-moves-to-another-version-only-as-a-named-act-req-zv-09
	 */
	public function testACallerWithoutCaseAccessCannotReadTheOptions(): void {
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->move->expects(self::never())->method('options');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->options(caseId: 'case-1')->getStatus());
	}//end testACallerWithoutCaseAccessCannotReadTheOptions()

	/**
	 * An anonymous caller is refused before anything else is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md#requirement-a-running-case-moves-to-another-version-only-as-a-named-act-req-zv-09
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->move->expects(self::never())->method('move');

		self::assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller()->moveToVersion(caseId: 'case-1')->getStatus()
		);
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * The preview rides along only when a target is named.
	 *
	 * One endpoint answers the picker and the preview, because the dialog asks
	 * both questions about the same case, and a second round trip would let the
	 * two answers come from different moments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md#requirement-a-running-case-moves-to-another-version-only-as-a-named-act-req-zv-09
	 */
	public function testThePreviewIsOnlyAskedForWhenATargetIsNamed(): void {
		$this->move->method('options')->willReturn(['current' => [], 'targets' => []]);
		$this->move->expects(self::never())->method('preview');

		self::assertArrayNotHasKey('preview', $this->controller()->options(caseId: 'case-1')->getData());
	}//end testThePreviewIsOnlyAskedForWhenATargetIsNamed()

	/**
	 * A named target brings its preview back on the same answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md#requirement-a-running-case-moves-to-another-version-only-as-a-named-act-req-zv-09
	 */
	public function testANamedTargetBringsItsPreview(): void {
		$this->move->method('options')->willReturn(['current' => [], 'targets' => []]);
		$this->move->expects(self::once())
			->method('preview')
			->with(caseId: 'case-1', targetCaseTypeId: 'ct-2')
			->willReturn(['canMove' => true]);
		$this->body = ['target' => 'ct-2'];

		self::assertTrue($this->controller()->options(caseId: 'case-1')->getData()['preview']['canMove']);
	}//end testANamedTargetBringsItsPreview()

	/**
	 * 🔴 A refusal reaches the caller with its own status and its sentence.
	 *
	 * The sentence names the status the target version does not have, and that
	 * name is the only thing the person reading it can act on. Replacing it
	 * with a generic line here is how a usable refusal becomes "could not
	 * complete the request".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md#requirement-a-running-case-moves-to-another-version-only-as-a-named-act-req-zv-09
	 */
	public function testARefusalKeepsItsSentenceAndItsStatus(): void {
		$this->move->method('move')->willThrowException(
			new RefusedException(
				rule: 'status-does-not-exist-in-target-version',
				sentence: 'Version 2 has no status called "Ingetrokken", so this case has nowhere to land.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			)
		);
		$this->body = ['target' => 'ct-2', 'reason' => 'Nieuwe regels'];

		$response = $this->controller()->moveToVersion(caseId: 'case-1');

		self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $response->getStatus());
		self::assertStringContainsString('Ingetrokken', $response->getData()['message']);
	}//end testARefusalKeepsItsSentenceAndItsStatus()

	/**
	 * The target, the reason and the caller reach the service unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md#requirement-a-running-case-moves-to-another-version-only-as-a-named-act-req-zv-09
	 */
	public function testTheActReachesTheServiceUnchanged(): void {
		$this->move->expects(self::once())
			->method('move')
			->with(
				caseId: 'case-1',
				targetCaseTypeId: 'ct-2',
				reason: 'Nieuwe regels',
				actorUid: 'jan',
			)
			->willReturn(['moved' => true]);
		$this->body = ['target' => 'ct-2', 'reason' => 'Nieuwe regels'];

		self::assertSame(Http::STATUS_OK, $this->controller()->moveToVersion(caseId: 'case-1')->getStatus());
	}//end testTheActReachesTheServiceUnchanged()

	/**
	 * A refused deprecate answers 422 with the finding as the message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md#requirement-new-version-and-deprecate-are-actions-on-the-page-req-zv-05
	 */
	public function testARefusedDeprecateCarriesItsFinding(): void {
		$this->window->method('deprecate')->willReturn(
			[
				'deprecated' => false,
				'findings' => ['This is the version new cases are filed under. Publish its successor first.'],
				'validUntil' => null,
			]
		);

		$response = $this->controller()->deprecate(id: 'ct-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertStringContainsString('successor', $response->getData()['message']);
	}//end testARefusedDeprecateCarriesItsFinding()

	/**
	 * A deprecate that ran answers the day the version was closed on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md#requirement-new-version-and-deprecate-are-actions-on-the-page-req-zv-05
	 */
	public function testADeprecateThatRanAnswersTheClosingDay(): void {
		$this->window->method('deprecate')->willReturn(
			['deprecated' => true, 'findings' => [], 'validUntil' => '2026-09-16']
		);

		$response = $this->controller()->deprecate(id: 'ct-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('2026-09-16', $response->getData()['validUntil']);
	}//end testADeprecateThatRanAnswersTheClosingDay()
}//end class
