<?php

/**
 * The cross-app listeners are registered, and the composite still runs them.
 *
 * Both registrations moved out of `ListenerRegistrar` to get that class under
 * phpmd's coupling ceiling. A registrar that is written but never called looks
 * exactly like one that registered nothing: the flow nodes simply do not
 * appear, integriq's delivery is never projected onto the case, and no error is
 * raised at boot. That is the failure this test exists to make loud.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec exclude No canonical spec covers registrar composition. This pins a
 *  wiring fact a refactor can silently drop, not a stated requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\CrossAppListenerRegistrar;
use OCA\Dossiq\AppInfo\Registrar\ListenerRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\AppInfo\Registrar\CrossAppListenerRegistrar
 */
class CrossAppListenerRegistrarTest extends TestCase {

	/**
	 * The flow node listener is registered when OpenRegister is installed.
	 *
	 * Guarded on the event class existing, so the assertion only holds where
	 * that class is loadable. Where it is not, the registrar must register
	 * nothing rather than fail, which the second assertion states.
	 *
	 * @return void
	 */
	public function testTheFlowNodeListenerIsRegisteredWhereOpenRegisterIsPresent(): void {
		$event = 'OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent';
		$registered = $this->registrations(registrar: new CrossAppListenerRegistrar());

		if (class_exists($event) === false) {
			$this->assertArrayNotHasKey(
				$event,
				$registered,
				'without OpenRegister the registrar must register nothing, not fail',
			);
			return;
		}

		$this->assertContains(
			needle: \OCA\Dossiq\Flow\DossiqFlowNodeListener::class,
			haystack: ($registered[$event] ?? []),
			message: 'the flow nodes are contributed by this listener, or the case can DO nothing',
		);
	}//end testTheFlowNodeListenerIsRegisteredWhereOpenRegisterIsPresent()

	/**
	 * The integriq delivery seam is registered when integriq is installed.
	 *
	 * @return void
	 */
	public function testTheDeliverySeamIsRegisteredWhereIntegriqIsPresent(): void {
		$event = 'OCA\Integriq\Event\DeliveryConcludedEvent';
		$registered = $this->registrations(registrar: new CrossAppListenerRegistrar());

		if (class_exists($event) === false) {
			$this->assertArrayNotHasKey(
				$event,
				$registered,
				'without integriq the registrar must register nothing, not fail',
			);
			return;
		}

		$this->assertContains(
			needle: \OCA\Dossiq\Listener\DeliveryConcludedListener::class,
			haystack: ($registered[$event] ?? []),
			message: 'without this the delivery outcome never reaches the case publication record',
		);
	}//end testTheDeliverySeamIsRegisteredWhereIntegriqIsPresent()

	/**
	 * The composite still delegates to the cross-app registrar.
	 *
	 * This is the assertion that catches the move itself, and it reads the
	 * SOURCE rather than the registrations. Neither cross-app event class is
	 * loadable in a unit test, so both guards take their false branch and a
	 * registrar run here registers nothing at all: comparing the two runs would
	 * compare two empty arrays and pass whatever ListenerRegistrar did. The
	 * delegation is the fact worth pinning, so it is asserted directly.
	 *
	 * @return void
	 */
	public function testTheCompositeStillRunsTheCrossAppRegistrar(): void {
		$this->assertStringContainsString(
			'(new CrossAppListenerRegistrar())->register(',
			$this->source(class: ListenerRegistrar::class),
			'ListenerRegistrar no longer runs CrossAppListenerRegistrar, so the flow nodes '
			. 'and the integriq delivery seam are registered by nobody and fail silently',
		);
	}//end testTheCompositeStillRunsTheCrossAppRegistrar()

	/**
	 * The move carried both registrations across, guard and listener each.
	 *
	 * Also source-read, for the same reason: a guard whose event class cannot
	 * load registers nothing, so only the text says whether the block survived.
	 *
	 * @param string $needle The spelling that must still appear.
	 *
	 * @return void
	 *
	 * @dataProvider crossAppSpellings
	 */
	public function testBothCrossAppRegistrationsSurvivedTheMove(string $needle): void {
		$this->assertStringContainsString(
			$needle,
			$this->source(class: CrossAppListenerRegistrar::class),
			$needle . ' is gone from the registrar, so that surface is dark',
		);
	}//end testBothCrossAppRegistrationsSurvivedTheMove()

	/**
	 * The four spellings the two guarded blocks are built from.
	 *
	 * The integriq event is a FQN STRING and not `::class`, because the class
	 * is not loadable here. That spelling is load-bearing and is pinned as
	 * written.
	 *
	 * @return array<int, array<int, string>> The cases.
	 */
	public static function crossAppSpellings(): array {
		return [
			['OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent'],
			['OCA\Dossiq\Flow\DossiqFlowNodeListener'],
			['OCA\\\\Integriq\\\\Event\\\\DeliveryConcludedEvent'],
			['OCA\Dossiq\Listener\DeliveryConcludedListener'],
		];
	}//end crossAppSpellings()

	/**
	 * Read a class's own source file.
	 *
	 * @param string $class The class name.
	 *
	 * @return string The file contents.
	 */
	private function source(string $class): string {
		$file = (new \ReflectionClass($class))->getFileName();
		$this->assertIsString($file);

		return (string)file_get_contents($file);
	}//end source()

	/**
	 * Run a registrar over a recording context.
	 *
	 * @param object $registrar The registrar to run.
	 *
	 * @return array<string, array<int, string>> Listener classes per event class.
	 */
	private function registrations(object $registrar): array {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[ltrim($event, '\\')][] = $listener;
			}
		);

		$registrar->register(context: $context);

		return $registered;
	}//end registrations()
}//end class
