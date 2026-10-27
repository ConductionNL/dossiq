<?php

/**
 * OneStatutoryTermPerCaseListener unit tests.
 *
 * A case runs one statutory term at a time. A statutory term written for a
 * case (an example set, an import, a correction) takes over from the one that
 * ran before it, which is withdrawn; the queue, the term reports and the
 * counts then read one term, not two (DQ-DATA, 27 Oct: ten cases ran the
 * engine's term and the example set's side by side).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\AppInfo\Registrar\DeadlineTimerRegistrar;
use OCA\Dossiq\Listener\OneStatutoryTermPerCaseListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Listener\OneStatutoryTermPerCaseListener
 */
class OneStatutoryTermPerCaseListenerTest extends TestCase {

	/**
	 * A term instance as OpenRegister hands it to a listener.
	 *
	 * @param string               $uuid The instance id.
	 * @param array<string, mixed> $data The instance.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema('17');
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * The listener over a term service.
	 *
	 * @param TermijnService $terms The term service.
	 *
	 * @return OneStatutoryTermPerCaseListener
	 */
	private function listener(TermijnService $terms): OneStatutoryTermPerCaseListener {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnMap([['termijn_instance_schema', '', '17']]);

		return new OneStatutoryTermPerCaseListener(settingsService: $settings, terms: $terms, logger: new NullLogger());
	}//end listener()

	/**
	 * A statutory term written for a case withdraws the statutory term still running on it.
	 *
	 * @return void
	 */
	public function testANewStatutoryTermWithdrawsTheOneRunningBeforeIt(): void {
		$terms = $this->createMock(originalClassName: TermijnService::class);
		$terms->method('instancesForCase')->willReturn([
			['id' => 'set-term', 'case' => 'case-1', 'kind' => 'statutory', 'status' => 'lopend'],
			['id' => 'engine-term', 'case' => 'case-1', 'kind' => 'statutory', 'status' => 'lopend', 'deadlineDefinition' => 'def-1'],
			['id' => 'old-term', 'case' => 'case-1', 'status' => 'completed'],
			['id' => 'planned-term', 'case' => 'case-1', 'kind' => 'planned', 'status' => 'lopend'],
		]);
		$terms->expects($this->once())->method('updateTermijnInstance')->with('engine-term', ['status' => 'withdrawn']);

		$written = $this->entity(uuid: 'set-term', data: ['case' => 'case-1', 'kind' => 'statutory', 'status' => 'lopend']);
		$this->listener(terms: $terms)->handle(new ObjectCreatedEvent($written));
	}//end testANewStatutoryTermWithdrawsTheOneRunningBeforeIt()

	/**
	 * A closed case's completed term also stops the term that kept running on it.
	 *
	 * @return void
	 */
	public function testACompletedTermStopsTheOneStillRunning(): void {
		$terms = $this->createMock(originalClassName: TermijnService::class);
		$terms->method('instancesForCase')->willReturn([
			['id' => 'engine-term', 'case' => 'case-2', 'status' => 'lopend'],
			['id' => 'set-term', 'case' => 'case-2', 'kind' => 'statutory', 'status' => 'completed'],
		]);
		$terms->expects($this->once())->method('updateTermijnInstance')->with('engine-term', ['status' => 'withdrawn']);

		$written = $this->entity(uuid: 'set-term', data: ['case' => 'case-2', 'status' => 'completed']);
		$this->listener(terms: $terms)->handle(new ObjectCreatedEvent($written));
	}//end testACompletedTermStopsTheOneStillRunning()

	/**
	 * A planned, internal or phase term leaves the statutory one alone, as does any other object.
	 *
	 * @return void
	 */
	public function testAnotherKindOrObjectLeavesTheStatutoryTermAlone(): void {
		$terms = $this->createMock(originalClassName: TermijnService::class);
		$terms->method('instancesForCase')->willReturn([['id' => 'engine-term', 'case' => 'case-1', 'status' => 'lopend']]);
		$terms->expects($this->never())->method('updateTermijnInstance');

		$listener = $this->listener(terms: $terms);
		$listener->handle(new ObjectCreatedEvent($this->entity(uuid: 'planned-term', data: ['case' => 'case-1', 'kind' => 'planned'])));
		$other = $this->entity(uuid: 'x', data: ['case' => 'case-1']);
		$other->setSchema('4');
		$listener->handle(new ObjectCreatedEvent($other));
	}//end testAnotherKindOrObjectLeavesTheStatutoryTermAlone()

	/**
	 * It runs after every create of an object.
	 *
	 * @return void
	 */
	public function testItIsRegisteredAfterCreate(): void {
		$registered = [];
		$context    = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new DeadlineTimerRegistrar())->register($context);

		$this->assertContains(needle: [ObjectCreatedEvent::class, OneStatutoryTermPerCaseListener::class], haystack: $registered);
	}//end testItIsRegisteredAfterCreate()
}//end class
