<?php

/**
 * WiringRegistrar and ComponentContractRegistrar Unit Tests
 *
 * Each composite makes exactly the registrations its registrars make when run
 * one by one, in the order Application::register() ran them.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\ComponentContractRegistrar;
use OCA\Dossiq\AppInfo\Registrar\WiringRegistrar;
use OCA\Dossiq\Contribution\ContributionContractRegistrar;
use OCA\Dossiq\Integration\IntegrationContractRegistrar;
use OCA\Dossiq\Lifecycle\LifecycleRegistrar;
use OCA\Dossiq\Listener\Registrar\ListenerRegistrar;
use OCA\Dossiq\Notification\NotificationContractRegistrar;
use OCA\Dossiq\Portal\PortalContractRegistrar;
use OCA\Dossiq\Review\ReviewContractRegistrar;
use OCA\Dossiq\Service\Registrar\ServiceRegistrar;
use OCA\Dossiq\Woo\WooContractRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\Dossiq\AppInfo\Registrar\WiringRegistrar
 * @covers \OCA\Dossiq\AppInfo\Registrar\ComponentContractRegistrar
 */
final class WiringRegistrarTest extends TestCase {

	/**
	 * The contract composite registers what the six component registrars register, in order.
	 *
	 * @return void
	 */
	public function testTheContractCompositeRunsTheSixInOrder(): void {
		$direct = $this->recording();
		foreach ([new ContributionContractRegistrar(), new IntegrationContractRegistrar(), new NotificationContractRegistrar()] as $registrar) {
			$registrar->register(context: $direct['context']);
		}

		foreach ([new PortalContractRegistrar(), new ReviewContractRegistrar(), new WooContractRegistrar()] as $registrar) {
			$registrar->register(context: $direct['context']);
		}

		$composite = $this->recording();
		(new ComponentContractRegistrar())->register(context: $composite['context']);

		$this->assertNotSame(expected: [], actual: $direct['calls']->getArrayCopy());
		$this->assertSame(expected: $direct['calls']->getArrayCopy(), actual: $composite['calls']->getArrayCopy());
	}//end testTheContractCompositeRunsTheSixInOrder()

	/**
	 * The wiring composite registers what the lifecycle, service, contract and listener registrars register, in order.
	 *
	 * @return void
	 */
	public function testTheWiringCompositeRunsTheFourInOrder(): void {
		$direct = $this->recording();
		(new LifecycleRegistrar())->register(context: $direct['context']);
		(new ServiceRegistrar())->register(context: $direct['context']);
		(new ComponentContractRegistrar())->register(context: $direct['context']);
		(new ListenerRegistrar())->register(context: $direct['context']);

		$composite = $this->recording();
		(new WiringRegistrar())->register(context: $composite['context']);

		$this->assertNotSame(expected: [], actual: $direct['calls']->getArrayCopy());
		$this->assertSame(expected: $direct['calls']->getArrayCopy(), actual: $composite['calls']->getArrayCopy());
	}//end testTheWiringCompositeRunsTheFourInOrder()

	/**
	 * A registration context that records every call: the method and its
	 * arguments, a closure recorded as `closure` since two closures are never
	 * the same instance.
	 *
	 * @return array{context: IRegistrationContext, calls: \ArrayObject<int, array<int, mixed>>}
	 */
	private function recording(): array {
		$calls = new \ArrayObject();
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		foreach ((new ReflectionClass(objectOrClass: IRegistrationContext::class))->getMethods() as $method) {
			$name = $method->getName();
			$context->expects($this->any())->method(constraint: $name)->willReturnCallback(
				callback: static function (mixed ...$args) use ($calls, $name): void {
					$recorded = [$name];
					foreach ($args as $arg) {
						if ($arg instanceof \Closure) {
							$arg = 'closure';
						}

						$recorded[] = $arg;
					}

					$calls->append(value: $recorded);
				}
			);
		}

		return ['context' => $context, 'calls' => $calls];
	}//end recording()
}//end class
