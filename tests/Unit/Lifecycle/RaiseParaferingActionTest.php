<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Lifecycle
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Lifecycle;

use OCA\Dossiq\Lifecycle\RaiseParaferingAction;
use OCA\Dossiq\Service\Parafeer\ParaferingRaiseService;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The voorstel's own "Start parafering" now reaches the engine.
 *
 * 🔴 IT DID NOT, AND NOTHING SAID SO. The `proposal` schema's
 * `startParafering` transition (draft → in_parafering) shipped with a guard and
 * NO action, so taking it moved the status and did nothing else: no chain held
 * at the decision app, no `approvalRouteId`, and no engine that would ever move
 * the voorstel on. `geaccordeerd` is written only by the conclusion recorder,
 * which only ever hears from a chain that was raised — so from `in_parafering`
 * the terminal status was unreachable, on the surface an operator actually
 * uses.
 *
 * The three shipped besluitvorming workflowTemplates DO carry
 * `besluitvormingActivate` on their transition into Parafering (dossiq#1729),
 * and a fresh rig shows all three seeded, active and non-draft — so that path
 * is live and was never the problem. Both doors are live; only one was wired.
 *
 * @covers \OCA\Dossiq\Lifecycle\RaiseParaferingAction
 */
class RaiseParaferingActionTest extends TestCase {

	/**
	 * The declaration in the shipped register names this handler.
	 *
	 * The sweep asserts it found the transition before asserting anything about
	 * it: a renamed transition would otherwise make this test pass by finding
	 * nothing to check.
	 *
	 * @return void
	 */
	public function testTheShippedTransitionDeclaresThisAction(): void {
		$register = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/dossiq_register.json'),
			true
		);

		$transitions = ($register['components']['schemas']['proposal']['configuration']['x-openregister-lifecycle']['transitions'] ?? []);
		self::assertIsArray($transitions);
		self::assertArrayHasKey(
			'startParafering',
			$transitions,
			'The sweep found no startParafering transition on the proposal schema — the query is broken, '
			. 'not the data clean'
		);

		$actions = ($transitions['startParafering']['actions'] ?? []);
		self::assertNotEmpty(
			$actions,
			'startParafering declares no actions, so taking it parks the voorstel in in_parafering with no '
			. 'chain raised anywhere and geaccordeerd unreachable'
		);

		$names = array_map(static fn (array $action): string => (string)($action['action'] ?? ''), $actions);
		self::assertContains(
			RaiseParaferingAction::class,
			$names,
			'The declared action must name a handler this app registers; OpenRegister\'s '
			. 'LifecycleActionRegistry fails loudly on one it cannot resolve'
		);
	}

	/**
	 * The handler implements the interface OpenRegister resolves it as.
	 *
	 * A declared action whose service does not implement the interface is
	 * refused at run time, which would abort the save it was meant to complete.
	 *
	 * @return void
	 */
	public function testTheHandlerImplementsTheEnginesInterface(): void {
		self::assertInstanceOf(
			LifecycleActionInterface::class,
			$this->action(raised: ['approvalRouteId' => 'route-1'])
		);
	}

	/**
	 * Taking the transition raises the chain and records it on the payload.
	 *
	 * The record is RETURNED rather than saved: OpenRegister runs the action
	 * inside the save that is already in flight and threads the return value
	 * into the payload it writes. A nested save would land before the outer
	 * one and be overwritten by it.
	 *
	 * @return void
	 */
	public function testItRaisesTheChainAndFoldsTheRecordIntoThePayload(): void {
		$action = $this->action(
			raised: [
				'status' => 'in_parafering',
				'currentStep' => 1,
				'routeSnapshot' => '[]',
				'approvalRouteId' => 'route-1',
			]
		);

		$result = $action->execute(
			objectData: ['id' => 'v-1', 'status' => 'in_parafering', 'subject' => 'Kapvergunning'],
			previousData: ['id' => 'v-1', 'status' => 'draft'],
			parameters: [],
			actionName: RaiseParaferingAction::class
		);

		self::assertSame('route-1', $result['approvalRouteId']);
		self::assertSame(1, $result['currentStep']);
		self::assertSame('Kapvergunning', $result['subject'], 'The rest of the payload must survive the merge');
	}

	/**
	 * A payload that already carries a route id is the case path's own write.
	 *
	 * `ParaferingRaiseService::activate()` writes `status = in_parafering`
	 * itself, so the workflowTemplate path's raise is ALSO a draft →
	 * in_parafering transition and fires this action. Raising again would hold
	 * a second chain at the decision app for one voorstel.
	 *
	 * @return void
	 */
	public function testItDoesNotRaiseASecondChainForTheCasePathsOwnWrite(): void {
		$calls = 0;
		$action = $this->action(raised: ['approvalRouteId' => 'route-2'], calls: $calls);

		$result = $action->execute(
			objectData: ['id' => 'v-1', 'status' => 'in_parafering', 'approvalRouteId' => 'route-1'],
			previousData: ['id' => 'v-1', 'status' => 'draft'],
			parameters: [],
			actionName: RaiseParaferingAction::class
		);

		self::assertSame(0, $calls, 'The chain was already held; raising again duplicates it');
		self::assertSame('route-1', $result['approvalRouteId']);
	}

	/**
	 * A refused raise throws, so the transition is aborted.
	 *
	 * Fail closed: a voorstel that cannot enter parafering must not appear to
	 * have entered it. This is the same refusal `activate()` makes on the case
	 * path, on the door that did not have one.
	 *
	 * @return void
	 */
	public function testARefusedRaiseAbortsTheTransition(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No parafeerroute');

		$this->action(raised: null)->execute(
			objectData: ['id' => 'v-1', 'status' => 'in_parafering'],
			previousData: ['id' => 'v-1', 'status' => 'draft'],
			parameters: [],
			actionName: RaiseParaferingAction::class
		);
	}

	/**
	 * A payload with no identifier is refused rather than silently skipped.
	 *
	 * @return void
	 */
	public function testAPayloadWithNoIdIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('carries no id');

		$this->action(raised: ['approvalRouteId' => 'route-1'])->execute(
			objectData: ['status' => 'in_parafering'],
			previousData: ['status' => 'draft'],
			parameters: [],
			actionName: RaiseParaferingAction::class
		);
	}

	/**
	 * The identifier is read from `@self` when the payload carries it there.
	 *
	 * @return void
	 */
	public function testItReadsTheIdentifierFromSelf(): void {
		$seen = '';
		$action = $this->action(raised: ['approvalRouteId' => 'route-1'], seenId: $seen);

		$action->execute(
			objectData: ['@self' => ['id' => 'v-9'], 'status' => 'in_parafering'],
			previousData: ['@self' => ['id' => 'v-9'], 'status' => 'draft'],
			parameters: [],
			actionName: RaiseParaferingAction::class
		);

		self::assertSame('v-9', $seen);
	}

	/**
	 * Build the handler over a fake raise service.
	 *
	 * @param array<string, mixed>|null $raised The fields the raise returns, or null to refuse.
	 * @param integer $calls Call counter, by reference.
	 * @param string $seenId The proposal id the service was asked about, by reference.
	 *
	 * @return RaiseParaferingAction The handler.
	 */
	private function action(?array $raised, int &$calls = 0, string &$seenId = ''): RaiseParaferingAction {
		$raiseService = $this->getMockBuilder(ParaferingRaiseService::class)
			->disableOriginalConstructor()
			->onlyMethods(['raiseFields'])
			->getMock();

		$raiseService->method('raiseFields')->willReturnCallback(
			static function (array $proposal, string $proposalId, string $proposalSchema) use ($raised, &$calls, &$seenId): array {
				$calls++;
				$seenId = $proposalId;

				if ($raised === null) {
					throw new RuntimeException(
						'No parafeerroute is configured for this case type, so the voorstel cannot enter parafering: '
						. $proposalId
					);
				}

				return $raised;
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('proposal');

		return new RaiseParaferingAction(
			raiseService: $raiseService,
			settingsService: $settings,
			logger: new NullLogger()
		);
	}
}//end class
