<?php

/**
 * The case action provider is built by hand, from the container, with every
 * collaborator in the slot it belongs in.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Lifecycle
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Lifecycle;

use OCA\Dossiq\Kernel\Contracts\ExternalHomeInterface;
use OCA\Dossiq\Kernel\Contracts\OpenRegisterGrantsGatewayInterface;
use OCA\Dossiq\Kernel\Contracts\StatusTransitionServiceInterface;
use OCA\Dossiq\Lifecycle\CaseActionList;
use OCA\Dossiq\Lifecycle\CaseActionProvider;
use OCA\Dossiq\Lifecycle\LifecycleRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Tests for the lifecycle registrar.
 *
 * @covers \OCA\Dossiq\Lifecycle\LifecycleRegistrar
 * @uses \OCA\Dossiq\Lifecycle\CaseActionProvider
 */
class LifecycleRegistrarTest extends TestCase {

	/**
	 * The services registered, as [name, factory].
	 *
	 * @var array<int, array{0: string, 1: callable}>
	 */
	private array $services = [];

	/**
	 * A context that records every service registered on it.
	 *
	 * @return IRegistrationContext The recording context.
	 */
	private function recordingContext(): IRegistrationContext {
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->expects($this->never())->method('registerEventListener');
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory, bool $shared = true): void {
				$this->services[] = [$name, $factory];
			}
		);

		return $context;
	}//end recordingContext()

	public function testOnlyTheCaseActionProviderIsRegistered(): void {
		(new LifecycleRegistrar())->register(context: $this->recordingContext());

		self::assertCount(1, $this->services);
		self::assertSame(CaseActionProvider::class, $this->services[0][0]);
	}//end testOnlyTheCaseActionProviderIsRegistered()

	public function testTheFactoryWiresEachCollaboratorIntoItsOwnSlot(): void {
		$deps = [
			StatusTransitionServiceInterface::class   => $this->createMock(originalClassName: StatusTransitionServiceInterface::class),
			OpenRegisterGrantsGatewayInterface::class => $this->createMock(originalClassName: OpenRegisterGrantsGatewayInterface::class),
			ExternalHomeInterface::class              => $this->createMock(originalClassName: ExternalHomeInterface::class),
			CaseActionList::class                     => $this->createMock(originalClassName: CaseActionList::class),
			LoggerInterface::class                    => $this->createMock(originalClassName: LoggerInterface::class),
		];

		$asked     = [];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($deps, &$asked): object {
				$asked[] = $id;
				self::assertArrayHasKey($id, $deps, 'The factory asked the container for an unexpected id: '.$id);
				return $deps[$id];
			}
		);

		(new LifecycleRegistrar())->register(context: $this->recordingContext());
		$provider = ($this->services[0][1])($container);

		self::assertInstanceOf(CaseActionProvider::class, $provider);
		self::assertSame(
			[
				StatusTransitionServiceInterface::class,
				OpenRegisterGrantsGatewayInterface::class,
				ExternalHomeInterface::class,
				CaseActionList::class,
				LoggerInterface::class,
			],
			$asked
		);

		$slots = [
			'transitionEngine' => StatusTransitionServiceInterface::class,
			'grants'           => OpenRegisterGrantsGatewayInterface::class,
			'externalHome'     => ExternalHomeInterface::class,
			'actions'          => CaseActionList::class,
			'logger'           => LoggerInterface::class,
		];
		foreach ($slots as $property => $id) {
			$reflection = new ReflectionProperty(CaseActionProvider::class, $property);
			self::assertSame($deps[$id], $reflection->getValue($provider), 'Wrong collaborator in slot '.$property);
		}
	}//end testTheFactoryWiresEachCollaboratorIntoItsOwnSlot()

	public function testEachCallOfTheFactoryBuildsAFreshProvider(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => $this->createMock(originalClassName: $id)
		);

		(new LifecycleRegistrar())->register(context: $this->recordingContext());
		$first  = ($this->services[0][1])($container);
		$second = ($this->services[0][1])($container);

		self::assertNotSame($first, $second);
	}//end testEachCallOfTheFactoryBuildsAFreshProvider()
}//end class
