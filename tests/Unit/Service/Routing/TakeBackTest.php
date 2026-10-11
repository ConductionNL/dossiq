<?php

/**
 * Tests for CaseRouter: routing writes the holder, and an unaccepted case goes back.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Routing
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use DateTime;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\RoleResolverService;
use OCA\Dossiq\Service\Routing\AreaRouting;
use OCA\Dossiq\Service\Routing\CaseRouter;
use OCA\Dossiq\Service\Routing\RoutingStrategyMissingException;
use OCA\Dossiq\Service\Routing\TakeBackWindow;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A case store with the PATCH seam.
 */
class TakeBackCaseStore {

	/**
	 * Cases by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $cases = [];

	/**
	 * Every patch, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $patches = [];

	/**
	 * Read one case.
	 *
	 * @param string $id       The id.
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, string $register = '', string $schema = ''): ?array {
		return ($this->cases[$id] ?? null);
	}//end find()

	/**
	 * Patch one case.
	 *
	 * @param string               $objectId The id.
	 * @param array<string, mixed> $data     The fields.
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function patchObject(string $objectId, array $data, string $register = '', string $schema = ''): array {
		$this->patches[]        = $data;
		$this->cases[$objectId] = array_merge(($this->cases[$objectId] ?? []), $data);

		return $this->cases[$objectId];
	}//end patchObject()
}//end class

/**
 * Routing and taking back, over a store and a scripted resolver.
 *
 * @spec openspec/specs/role-based-step-routing/spec.md#requirement-work-not-taken-up-returns-to-the-pool-req-rtp-03
 */
class TakeBackTest extends TestCase {

	/**
	 * The rule every case here is routed by: a two-day window.
	 *
	 * @var array<string, mixed>
	 */
	private const RULE = [
		'strategy'      => 'round-robin',
		'roleType'      => 'behandelaar',
		'takeBackAfter' => ['value' => 2, 'unit' => 'businessDays'],
	];

	/**
	 * The store.
	 *
	 * @var TakeBackCaseStore
	 */
	private TakeBackCaseStore $store;

	/**
	 * The resolver.
	 *
	 * @var RoleResolverService&MockObject
	 */
	private RoleResolverService&MockObject $resolver;

	/**
	 * The window.
	 *
	 * @var TakeBackWindow&MockObject
	 */
	private TakeBackWindow&MockObject $window;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store    = new TakeBackCaseStore();
		$this->resolver = $this->createMock(RoleResolverService::class);
		$this->window   = $this->getMockBuilder(TakeBackWindow::class)->disableOriginalConstructor()->onlyMethods(['arm', 'cancel'])->getMock();
		$this->window->method('arm')->willReturn(TakeBackWindow::ARMED);
	}//end setUp()

	/**
	 * The router, at 14 October 09:00.
	 *
	 * @param bool $withStore Whether OpenRegister is there.
	 *
	 * @return CaseRouter
	 */
	private function router(bool $withStore = true): CaseRouter {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($withStore === true ? $this->store : null);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? '');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-14T09:00:00+02:00'));

		return new CaseRouter(
			settingsService: $settings,
			resolver: $this->resolver,
			area: new AreaRouting(),
			window: $this->window,
			time: $time,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end router()

	/**
	 * A case routed to aad on 12 October, never accepted.
	 *
	 * @param array<string, mixed> $routing Overrides on the routing record.
	 * @param array<string, mixed> $case    Overrides on the case.
	 *
	 * @return void
	 */
	private function routedToAad(array $routing = [], array $case = []): void {
		$this->store->cases['case-1'] = array_merge(
			[
				'id'       => 'case-1',
				'assignee' => 'aad',
				'routing'  => array_merge(['rule' => self::RULE, 'routedTo' => 'aad', 'routedAt' => '2026-10-12T09:00:00+02:00'], $routing),
			],
			$case
		);
	}//end routedToAad()

	/**
	 * Routing writes the person as assignee, keeps the rule and the moment, and arms the window.
	 *
	 * @return void
	 */
	public function testRoutingWritesTheHolderAndArmsTheWindow(): void {
		$this->store->cases['case-1'] = ['id' => 'case-1'];
		$this->resolver->method('resolve')->willReturn(['aad']);
		$this->window->expects($this->once())->method('arm')->with('case-1', self::RULE, 'aad');

		$answer = $this->router()->route(caseId: 'case-1', rule: self::RULE + ['id' => 'case-1', '_route' => 'dossiq.routing.route']);

		$this->assertSame(expected: 'aad', actual: $answer['assignee']);
		$this->assertSame(expected: TakeBackWindow::ARMED, actual: $answer['takeBack']);
		$this->assertFalse($answer['areaFallbackUsed']);
		$stored = $this->store->cases['case-1'];
		$this->assertSame(expected: 'aad', actual: $stored['assignee']);
		// The request's own keys are not part of the rule the case keeps.
		$this->assertSame(expected: self::RULE, actual: $stored['routing']['rule']);
		$this->assertSame(expected: '2026-10-14T09:00:00+02:00', actual: $stored['routing']['routedAt']);
		$this->assertArrayNotHasKey(key: 'acceptedAt', array: $stored['routing']);
	}//end testRoutingWritesTheHolderAndArmsTheWindow()

	/**
	 * A rule that finds nobody writes nothing and says so.
	 *
	 * @return void
	 */
	public function testARuleThatFindsNobodyWritesNothing(): void {
		$this->store->cases['case-1'] = ['id' => 'case-1'];
		$this->resolver->method('resolve')->willReturn([]);
		$this->window->expects($this->never())->method('arm');

		$answer = $this->router()->route(caseId: 'case-1', rule: self::RULE);

		$this->assertSame(expected: '', actual: $answer['assignee']);
		$this->assertSame(expected: 'no-candidate', actual: $answer['reason']);
		$this->assertSame(expected: [], actual: $this->store->patches);
	}//end testARuleThatFindsNobodyWritesNothing()

	/**
	 * A strategy nobody registered is a 422 refusal, not a crash.
	 *
	 * @return void
	 */
	public function testAnUnknownStrategyIsRefused(): void {
		$this->store->cases['case-1'] = ['id' => 'case-1'];
		$this->resolver->method('resolve')->willThrowException(new RoutingStrategyMissingException('Routing strategy "lottery" is not registered'));

		try {
			$this->router()->route(caseId: 'case-1', rule: ['strategy' => 'lottery']);
			$this->fail('an unknown strategy must be refused');
		} catch (RefusedException $e) {
			$this->assertSame(expected: RefusedException::STATUS_UNPROCESSABLE, actual: $e->getStatus());
		}

		$this->assertSame(expected: [], actual: $this->store->patches);
	}//end testAnUnknownStrategyIsRefused()

	/**
	 * A case that does not exist, or no OpenRegister, is refused.
	 *
	 * @return void
	 */
	public function testAMissingCaseIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->router(withStore: false)->route(caseId: 'case-1', rule: self::RULE);
	}//end testAMissingCaseIsRefused()

	/**
	 * An unaccepted case goes back to the pool, to another member, and records who, why and who now.
	 *
	 * @return void
	 */
	public function testAnUnacceptedCaseGoesBack(): void {
		$this->routedToAad();
		// The round robin answers aad first; the router asks again for somebody else.
		$this->resolver->method('resolve')->willReturnOnConsecutiveCalls(['aad'], ['bea']);
		$this->resolver->expects($this->atLeastOnce())->method('invalidateCache')->with('case-1');
		$this->window->expects($this->once())->method('arm')->with('case-1', self::RULE, 'bea');

		$outcome = $this->router()->takeBack(caseId: 'case-1', routedTo: 'aad', routedAt: '2026-10-12T09:00:00+02:00');

		$this->assertSame(expected: CaseRouter::TAKEN_BACK, actual: $outcome);
		$stored = $this->store->cases['case-1'];
		$this->assertSame(expected: 'bea', actual: $stored['assignee']);
		$this->assertSame(expected: 'bea', actual: $stored['routing']['routedTo']);
		$this->assertSame(
			expected: [[
				'from'    => 'aad',
				'to'      => 'bea',
				'reason'  => CaseRouter::REASON_NOT_ACCEPTED,
				'at'      => '2026-10-14T09:00:00+02:00',
				'window'  => ['value' => 2, 'unit' => 'businessDays'],
				'outcome' => CaseRouter::TAKEN_BACK,
			]],
			actual: $stored['routingTakeBacks']
		);
	}//end testAnUnacceptedCaseGoesBack()

	/**
	 * Accepting cancels the take-back: an accepted case stays and nothing is recorded.
	 *
	 * @return void
	 */
	public function testAnAcceptedCaseStays(): void {
		$this->routedToAad(routing: ['acceptedAt' => '2026-10-13T08:30:00+02:00']);
		$this->resolver->expects($this->never())->method('resolve');

		$this->assertSame(expected: CaseRouter::NOT_DUE, actual: $this->router()->takeBack(caseId: 'case-1', routedTo: 'aad', routedAt: '2026-10-12T09:00:00+02:00'));
		$this->assertSame(expected: [], actual: $this->store->patches);
	}//end testAnAcceptedCaseStays()

	/**
	 * A case moved on (reassigned, routed again, closed or gone) is not taken back.
	 *
	 * @return void
	 */
	public function testACaseThatMovedOnIsNotTakenBack(): void {
		$this->resolver->expects($this->never())->method('resolve');
		$router = $this->router();

		$this->routedToAad(case: ['assignee' => 'carla']);
		$this->assertSame(expected: CaseRouter::NOT_DUE, actual: $router->takeBack(caseId: 'case-1', routedTo: 'aad', routedAt: '2026-10-12T09:00:00+02:00'));

		$this->routedToAad(routing: ['routedAt' => '2026-10-13T10:00:00+02:00']);
		$this->assertSame(expected: CaseRouter::NOT_DUE, actual: $router->takeBack(caseId: 'case-1', routedTo: 'aad', routedAt: '2026-10-12T09:00:00+02:00'));

		$this->routedToAad(case: ['endDate' => '2026-10-13']);
		$this->assertSame(expected: CaseRouter::NOT_DUE, actual: $router->takeBack(caseId: 'case-1', routedTo: 'aad', routedAt: '2026-10-12T09:00:00+02:00'));

		$this->assertSame(expected: CaseRouter::NOT_DUE, actual: $router->takeBack(caseId: 'case-404', routedTo: 'aad', routedAt: ''));
		$this->assertSame(expected: [], actual: $this->store->patches);
	}//end testACaseThatMovedOnIsNotTakenBack()

	/**
	 * A pool with nobody else keeps the case with its holder, and says so.
	 *
	 * @return void
	 */
	public function testAPoolOfOneKeepsTheCaseAndSaysSo(): void {
		$this->routedToAad();
		$this->resolver->method('resolve')->willReturn(['aad']);
		$this->window->expects($this->never())->method('arm');

		$this->assertSame(expected: CaseRouter::KEPT, actual: $this->router()->takeBack(caseId: 'case-1', routedTo: 'aad', routedAt: '2026-10-12T09:00:00+02:00'));

		$stored = $this->store->cases['case-1'];
		$this->assertSame(expected: 'aad', actual: $stored['assignee']);
		$this->assertSame(expected: CaseRouter::KEPT, actual: $stored['routingTakeBacks'][0]['outcome']);
		$this->assertSame(expected: 'aad', actual: $stored['routingTakeBacks'][0]['to']);
	}//end testAPoolOfOneKeepsTheCaseAndSaysSo()
}//end class
