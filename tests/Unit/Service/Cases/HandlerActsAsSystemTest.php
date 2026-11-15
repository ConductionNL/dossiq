<?php

/**
 * The handler gate in front of an act the system runs.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
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
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;


use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Cases\HandlerActsAsSystem;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Who may run the act, at which step, and as whom it runs.
 *
 * @covers \OCA\Dossiq\Service\Cases\HandlerActsAsSystem
 * @uses   \OCA\Dossiq\Exception\RefusedException
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class HandlerActsAsSystemTest extends TestCase {

	/**
	 * The guard.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard&MockObject $guard;

	/**
	 * The case store.
	 *
	 * @var CaseStatusStore&MockObject
	 */
	private CaseStatusStore&MockObject $store;

	/**
	 * The settings.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The caller.
	 *
	 * @var IUser&MockObject
	 */
	private IUser&MockObject $user;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->store = $this->createMock(CaseStatusStore::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->user = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * The subject.
	 *
	 * @return HandlerActsAsSystem The service.
	 */
	private function subject(): HandlerActsAsSystem {
		return new HandlerActsAsSystem(guard: $this->guard, store: $this->store, settings: $this->settings);
	}//end subject()

	/**
	 * A caller who is not the handler is refused with 403 before the case is read.
	 *
	 * @return void
	 */
	public function testACallerWhoIsNotTheHandlerIsRefusedFirst(): void {
		$this->guard->expects($this->once())->method('hasCaseMutationAccess')
			->with('case-1', $this->user)->willReturn(false);
		$this->store->expects($this->never())->method('loadCase');
		$ran = false;

		try {
			$this->subject()->run('case-1', $this->user, 'phase', ['assess'], function () use (&$ran): string {
				$ran = true;
				return 'done';
			});
			self::fail('A caller who is not the handler must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('not-the-handler', $e->getRule());
			self::assertSame(403, $e->getStatus());
			self::assertSame('Claim this case first: only its handler can do this.', $e->getSentence());
		}

		self::assertFalse($ran);
	}//end testACallerWhoIsNotTheHandlerIsRefusedFirst()

	/**
	 * Cases that are not at the step: missing, final, or at another step value.
	 *
	 * @return array<string, array{0: array<string, mixed>|null}> The cases.
	 */
	public static function notAtStepProvider(): array {
		return [
			'case gone' => [null],
			'final status (bool)' => [['phase' => 'assess', 'isFinalStatus' => true]],
			'final status (string)' => [['phase' => 'assess', 'isFinalStatus' => 'true']],
			'final status (int)' => [['phase' => 'assess', 'isFinalStatus' => 1]],
			'another step' => [['phase' => 'intake']],
			'no step field' => [[]],
		];
	}//end notAtStepProvider()

	/**
	 * A handler at the wrong step is refused with 422 and the act does not run.
	 *
	 * @param array<string, mixed>|null $case The stored case.
	 *
	 * @dataProvider notAtStepProvider
	 *
	 * @return void
	 */
	public function testACaseNotAtTheStepIsRefused(?array $case): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->store->expects($this->once())->method('loadCase')->with('case-1')->willReturn($case);
		$this->settings->expects($this->never())->method('getObjectService');
		$ran = false;

		try {
			$this->subject()->run('case-1', $this->user, 'phase', ['assess', 'decide'], function () use (&$ran): string {
				$ran = true;
				return 'done';
			});
			self::fail('A case not at the step must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('not-at-this-step', $e->getRule());
			self::assertSame(422, $e->getStatus());
		}

		self::assertFalse($ran);
	}//end testACaseNotAtTheStepIsRefused()

	/**
	 * Without OpenRegister the act runs directly and its result is returned.
	 *
	 * @return void
	 */
	public function testWithoutAnObjectServiceTheActRunsDirectly(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->store->method('loadCase')->willReturn(['phase' => 'decide', 'isFinalStatus' => false]);
		$this->settings->expects($this->once())->method('getObjectService')->willReturn(null);

		$result = $this->subject()->run('case-1', $this->user, 'phase', ['assess', 'decide'], static fn (): array => ['ok' => true]);

		self::assertSame(['ok' => true], $result);
	}//end testWithoutAnObjectServiceTheActRunsDirectly()

	/**
	 * With an object service that can run as the system, the act runs through it.
	 *
	 * @return void
	 */
	public function testTheActRunsThroughRunAsSystem(): void {
		$objectService = new class {
			/**
			 * How often the act was run as the system.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Run as the system.
			 *
			 * @param callable $operation The act.
			 *
			 * @return mixed The act's result, wrapped.
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->calls++;
				return ['system' => $operation()];
			}//end runAsSystem()
		};
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->store->method('loadCase')->willReturn(['phase' => 'assess', 'isFinalStatus' => '0']);
		$this->settings->method('getObjectService')->willReturn($objectService);

		$result = $this->subject()->run('case-1', $this->user, 'phase', ['assess'], static fn (): string => 'done');

		self::assertSame(['system' => 'done'], $result);
		self::assertSame(1, $objectService->calls);
	}//end testTheActRunsThroughRunAsSystem()

	/**
	 * An object service without runAsSystem still runs the act, as the caller.
	 *
	 * @return void
	 */
	public function testAnObjectServiceWithoutRunAsSystemRunsTheActDirectly(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->store->method('loadCase')->willReturn(['phase' => 'assess']);
		$this->settings->method('getObjectService')->willReturn(new \stdClass());

		self::assertSame('done', $this->subject()->run('case-1', $this->user, 'phase', ['assess'], static fn (): string => 'done'));
	}//end testAnObjectServiceWithoutRunAsSystemRunsTheActDirectly()
}//end class
