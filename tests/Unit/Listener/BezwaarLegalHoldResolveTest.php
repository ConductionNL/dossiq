<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\BezwaarLegalHoldListener;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A legal hold that cannot be applied, because the case or an OpenRegister
 * collaborator cannot be resolved, is never silent and places nothing.
 *
 * @covers \OCA\Dossiq\Listener\BezwaarLegalHoldListener
 * @uses   \OCA\Dossiq\Listener\Support\EventObjectPayload
 */
class BezwaarLegalHoldResolveTest extends TestCase {

	/**
	 * @var array<int, string> Every hold call and warning, in order.
	 */
	public array $calls = [];

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testACaseTheMapperCannotReadIsLoggedAndNotHeld(): void {
		$this->declareOpenRegisterClasses();

		$this->created(container: $this->container(mapper: new class {
			public function find(string $identifier, bool $_rbac = true, bool $_multitenancy = true): ?object {
				throw new RuntimeException('mapper down');
			}
		}));

		$this->assertSame(
			[
				'warning Dossiq legal-hold: could not resolve case object {"app":"dossiq","caseId":"case-1","error":"mapper down"}',
				'warning Dossiq legal-hold: NOT applied, collaborator or case unresolved {"app":"dossiq","caseId":"case-1","place":true,"haveService":true,"haveCaseObject":false}',
			],
			$this->calls
		);
	}//end testACaseTheMapperCannotReadIsLoggedAndNotHeld()

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testACollaboratorTheContainerCannotGiveLeavesTheHoldUnapplied(): void {
		$this->declareOpenRegisterClasses();

		$failing = $this->createMock(ContainerInterface::class);
		$failing->method('get')->willThrowException(new RuntimeException('not registered'));
		$this->created(container: $failing);

		$notAnObject = $this->createMock(ContainerInterface::class);
		$notAnObject->method('get')->willReturn('LegalHoldService');
		$this->created(container: $notAnObject);

		$unapplied = 'warning Dossiq legal-hold: NOT applied, collaborator or case unresolved {"app":"dossiq","caseId":"case-1","place":true,"haveService":false,"haveCaseObject":false}';
		$this->assertSame([$unapplied, $unapplied], $this->calls);
	}//end testACollaboratorTheContainerCannotGiveLeavesTheHoldUnapplied()

	/**
	 * Declare the two OpenRegister classes the listener checks for, in this process only.
	 *
	 * @return void
	 */
	private function declareOpenRegisterClasses(): void {
		if (class_exists('OCA\\OpenRegister\\Service\\Archival\\LegalHoldService') === false) {
			eval('namespace OCA\\OpenRegister\\Service\\Archival; class LegalHoldService {}');
		}

		if (class_exists('OCA\\OpenRegister\\Db\\MagicMapper') === false) {
			eval('namespace OCA\\OpenRegister\\Db; class MagicMapper {}');
		}
	}//end declareOpenRegisterClasses()

	/**
	 * A container whose hold service records calls and whose mapper is the given one.
	 *
	 * @param object $mapper The object mapper.
	 *
	 * @return ContainerInterface
	 */
	private function container(object $mapper): ContainerInterface {
		$holds = new class($this) {
			public function __construct(private readonly BezwaarLegalHoldResolveTest $test) {
			}

			public function hasActiveHold(object $object): bool {
				$this->test->calls[] = 'has';
				return false;
			}

			public function placeHold(object $object, object $order): void {
				$this->test->calls[] = 'place';
			}

			public function releaseHold(object $object, object $order): void {
				$this->test->calls[] = 'release';
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => (str_ends_with($id, 'LegalHoldService') === true) ? $holds : $mapper
		);

		return $container;
	}//end container()

	/**
	 * Handle a created objection on case-1.
	 *
	 * @param ContainerInterface $container Where the OpenRegister collaborators come from.
	 *
	 * @return void
	 */
	private function created(ContainerInterface $container): void {
		$resolver = $this->createMock(ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturn('objection');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = 'warning ' . $message . ' ' . json_encode($context);
			}
		);

		$entity = new ObjectEntity();
		$entity->setObject(['case' => 'case-1']);
		(new BezwaarLegalHoldListener($container, $resolver, $logger))->handle(new ObjectCreatedEvent($entity));
	}//end created()
}//end class
