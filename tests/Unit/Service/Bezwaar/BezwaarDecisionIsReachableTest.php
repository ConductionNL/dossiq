<?php

/**
 * The decision on an objection can be asked for, and it stores its link.
 *
 * `DecisionService` has held `draft()`, `publish()` and `applyToBezwaar()`
 * since the bezwaar lifecycle shipped, with the whole Awb 7:11 disposition
 * matrix behind them, and nothing called any of them: no route, no listener,
 * no component. A jurist could hold a hearing, record its minutes and read the
 * advisory opinion, and then had nowhere to write the decision those three
 * steps exist to produce.
 *
 * 🔴 SUPPLYING THE CALLER FOUND A DEFECT THE SERVICE COULD NEVER HAVE SURVIVED.
 * `draft()` wrote the link to the objection under `objectionProceeding`, which
 * is the name of the SCHEMA it `$ref`s and not of the property. The schema
 * declares `bezwaar`, and declares it REQUIRED. An undeclared key is dropped by
 * OpenRegister in silence, so every draft would have stored a decision
 * belonging to no objection, and `publish()` would then have read an empty id
 * and raised a Decision against nothing. Nothing reported it because nothing
 * ever ran it. `bacAdviceRequest` had the identical defect and needed a repair
 * step over live rows; this one is caught before a row exists.
 *
 * So the first test here is not about the endpoint. It is about the key.
 *
 * MUTATION-CHECKED 2026-09-18: putting `objectionProceeding` back in `draft()`
 * reddens testTheDraftStoresTheLinkUnderTheDeclaredName; dropping the
 * `caseIdForObjection` guard from the controller reddens
 * testSomebodyElsesObjectionIsRefused. Restored after.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
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
 * @spec openspec/specs/bezwaar-decision/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Controller\BezwaarDecisionController;
use OCA\Dossiq\Service\Bezwaar\DecisionService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The link the draft writes, and the guard the endpoint applies.
 *
 * @covers \OCA\Dossiq\Controller\BezwaarDecisionController
 * @uses \OCA\Dossiq\Service\Bezwaar\DecisionService
 * @uses \OCA\Dossiq\Service\CaseAccessGuard
 *
 * @spec openspec/specs/bezwaar-decision/spec.md
 */
class BezwaarDecisionIsReachableTest extends TestCase {

	/**
	 * The link is written under the name the schema declares and requires.
	 *
	 * Asserted against the SHIPPED descriptor rather than a literal, so the
	 * day somebody renames the property the test moves with it instead of
	 * quietly guarding the old name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	public function testTheDraftStoresTheLinkUnderTheDeclaredName(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../../lib/Settings/dossiq_register.json'),
			true
		);
		$schema = $register['components']['schemas']['bezwaarDecision'];

		self::assertContains(
			'bezwaar',
			$schema['required'],
			'The link is required, so a draft that omits it is refused outright.',
		);
		self::assertArrayNotHasKey(
			'objectionProceeding',
			$schema['properties'],
			'`objectionProceeding` is the schema this $refs, not a property on it.',
		);

		$source = (string)file_get_contents(
			__DIR__ . '/../../../../lib/Service/Bezwaar/DecisionService.php'
		);

		self::assertStringContainsString(
			"'bezwaar' => \$objectionId",
			$source,
			'An undeclared key is dropped in silence, so the decision would belong to no objection.',
		);
		self::assertStringNotContainsString(
			"'objectionProceeding' => \$objectionId",
			$source,
		);
	}//end testTheDraftStoresTheLinkUnderTheDeclaredName()

	/**
	 * An objection whose case the caller does not handle is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	public function testSomebodyElsesObjectionIsRefused(): void {
		$decisions = $this->createMock(originalClassName: DecisionService::class);
		$decisions->method('caseIdForObjection')->willReturn('case-1');
		$decisions->expects(self::never())->method('draft');

		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturn(false);

		$response = $this->controller(decisions: $decisions, guard: $guard)->draft(objectionId: 'bezwaar-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testSomebodyElsesObjectionIsRefused()

	/**
	 * An objection that cannot be resolved to a case denies.
	 *
	 * The posture every other bezwaar surface takes, and the one that matters:
	 * a guard that passed on an unresolvable id would be no guard at all,
	 * because an attacker picks the id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	public function testAnUnresolvableObjectionDenies(): void {
		$decisions = $this->createMock(originalClassName: DecisionService::class);
		$decisions->method('caseIdForObjection')->willReturn(null);
		$decisions->expects(self::never())->method('draft');

		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturn(true);

		$response = $this->controller(decisions: $decisions, guard: $guard)->draft(objectionId: 'nonsense');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAnUnresolvableObjectionDenies()

	/**
	 * A handler of the case drafts, and the service is actually asked.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	public function testAHandlerDraftsTheDecision(): void {
		$decisions = $this->createMock(originalClassName: DecisionService::class);
		$decisions->method('caseIdForObjection')->willReturn('case-1');
		$decisions->expects(self::once())
			->method('draft')
			->with('bezwaar-1', ['dispositionType' => 'gegrond', 'reasoning' => 'Omdat'])
			->willReturn(['id' => 'decision-1', 'status' => 'draft']);

		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturn(true);

		$response = $this->controller(
			decisions: $decisions,
			guard: $guard,
			param: ['dispositionType' => 'gegrond', 'reasoning' => 'Omdat'],
		)->draft(objectionId: 'bezwaar-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('draft', $response->getData()['status']);
	}//end testAHandlerDraftsTheDecision()

	/**
	 * A draft with no body is refused before the service is asked.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	public function testADraftWithNoDecisionBodyIsRefused(): void {
		$decisions = $this->createMock(originalClassName: DecisionService::class);
		$decisions->method('caseIdForObjection')->willReturn('case-1');
		$decisions->expects(self::never())->method('draft');

		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturn(true);

		$response = $this->controller(decisions: $decisions, guard: $guard, param: null)
			->draft(objectionId: 'bezwaar-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testADraftWithNoDecisionBodyIsRefused()

	/**
	 * Publishing resolves the case through the decision's own objection.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	public function testPublishingIsGuardedThroughTheDecisionsObjection(): void {
		$decisions = $this->createMock(originalClassName: DecisionService::class);
		$decisions->method('caseIdForDecision')->willReturn('case-1');
		$decisions->expects(self::once())
			->method('publish')
			->with('decision-1')
			->willReturn(['id' => 'decision-1', 'decisionRef' => 'decidiq-7']);

		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturn(true);

		$response = $this->controller(decisions: $decisions, guard: $guard)->publish(decisionId: 'decision-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			'decidiq-7',
			$response->getData()['decisionRef'],
			'dossiq does not author the besluit; it answers the reference it raised.',
		);
	}//end testPublishingIsGuardedThroughTheDecisionsObjection()

	/**
	 * The controller under test.
	 *
	 * @param DecisionService $decisions The service double.
	 * @param CaseAccessGuard $guard     The guard double.
	 * @param mixed           $param     What the request answers for `decision`.
	 *
	 * @return BezwaarDecisionController The controller.
	 */
	private function controller(
		DecisionService $decisions,
		CaseAccessGuard $guard,
		mixed $param = ['dispositionType' => 'gegrond'],
	): BezwaarDecisionController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturn($param);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('jurist');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new BezwaarDecisionController(
			appName: 'dossiq',
			request: $request,
			decisions: $decisions,
			accessGuard: $guard,
			userSession: $session,
		);
	}//end controller()

	/**
	 * The two guarded verbs are the two the service exposes to a person.
	 *
	 * `applyToBezwaar()` is deliberately NOT routed: it is the consequence of a
	 * concluded decidiq decision and belongs to the listener that hears it, not
	 * to a person pressing a button. Asserted so that "why is there no endpoint
	 * for it" has an answer in the suite rather than in somebody's memory.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	public function testApplyToBezwaarIsNotAnEndpoint(): void {
		$methods = array_map(
			static function (\ReflectionMethod $method): string {
				return $method->getName();
			},
			(new ReflectionClass(BezwaarDecisionController::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
		);

		self::assertContains('draft', $methods);
		self::assertContains('publish', $methods);
		self::assertNotContains('applyToBezwaar', $methods);
	}//end testApplyToBezwaarIsNotAnEndpoint()
}//end class
