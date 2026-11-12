<?php

/**
 * How the legal-hold listener places and releases holds through OpenRegister.
 *
 * OpenRegister's LegalHoldService and MagicMapper are absent from a unit run,
 * and the listener only resolves a class that exists. These tests declare the
 * two classes inside their own process, so no other test sees them, and hand
 * the listener recording fakes through the container.
 *
 * Characterisation written against the unsplit methods and run green on them
 * before they were split.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
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

/**
 * @covers \OCA\Dossiq\Listener\BezwaarLegalHoldListener
 */
class BezwaarLegalHoldApplyTest extends TestCase {

	/**
	 * Every call the fakes saw, and every warning, in order.
	 *
	 * @var array<int, string>
	 */
	public array $calls = [];

	/**
	 * Cases that carry an active hold.
	 *
	 * @var array<int, string>
	 */
	public array $held = [];

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
	 * Handle one created proceeding.
	 *
	 * @param string               $slug   The schema slug.
	 * @param array<string, mixed> $object The proceeding's fields.
	 *
	 * @return void
	 */
	private function created(string $slug, array $object): void {
		$test = $this;
		$holds = new class($test) {
			/**
			 * @param BezwaarLegalHoldApplyTest $test The test.
			 */
			public function __construct(private readonly BezwaarLegalHoldApplyTest $test) {
			}//end __construct()

			/**
			 * @param object $object The case.
			 *
			 * @return bool
			 */
			public function hasActiveHold(object $object): bool {
				$this->test->calls[] = 'has ' . $object->id;
				if ($object->id === 'case-boom') {
					throw new \RuntimeException('hold store down');
				}

				return in_array($object->id, $this->test->held, true);
			}

			/**
			 * @param object $object The case.
			 * @param object $order  The order.
			 *
			 * @return void
			 */
			public function placeHold(object $object, object $order): void {
				$this->test->calls[] = 'place ' . $object->id . ': ' . $order->reason;
			}

			/**
			 * @param object $object The case.
			 * @param object $order  The order.
			 *
			 * @return void
			 */
			public function releaseHold(object $object, object $order): void {
				$this->test->calls[] = 'release ' . $object->id . ': ' . $order->reason;
			}
		};
		$mapper = new class($test) {
			/**
			 * @param BezwaarLegalHoldApplyTest $test The test.
			 */
			public function __construct(private readonly BezwaarLegalHoldApplyTest $test) {
			}//end __construct()

			/**
			 * @param string $identifier    The uuid.
			 * @param bool   $_rbac         Unused.
			 * @param bool   $_multitenancy Unused.
			 *
			 * @return object|null
			 */
			public function find(string $identifier, bool $_rbac=true, bool $_multitenancy=true): ?object {
				$this->test->calls[] = 'find ' . $identifier . ' ' . var_export($_rbac, true) . ' ' . var_export($_multitenancy, true);
				if ($identifier === 'case-missing' || $identifier === 'objection-missing') {
					return null;
				}

				if ($identifier === 'objection-boom') {
					throw new \RuntimeException('mapper down');
				}

				$found = new class {
					/**
					 * @var string
					 */
					public string $id = '';

					/**
					 * @var mixed
					 */
					public mixed $data = [];

					/**
					 * @return mixed
					 */
					public function getObject(): mixed {
						return $this->data;
					}
				};
				$found->id = $identifier;
				$found->data = match ($identifier) {
					'objection-1' => ['case' => 'case-9'],
					'objection-text' => 'not an object',
					default => [],
				};
				return $found;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => (str_ends_with($id, 'LegalHoldService') === true) ? $holds : $mapper
		);

		$resolver = $this->createMock(ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturn($slug);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = 'warning ' . $message . ' ' . json_encode($context);
			}
		);

		$entity = new ObjectEntity();
		$entity->setObject($object);
		(new BezwaarLegalHoldListener($container, $resolver, $logger))->handle(new ObjectCreatedEvent($entity));
	}//end created()

	/**
	 * A hold is placed only when none is active, released only when one is, and a failure is logged.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testHoldsArePlacedAndReleasedOnlyWhenTheyChangeSomething(): void {
		$this->declareOpenRegisterClasses();
		$this->held = ['case-2', 'case-3'];

		$this->created(slug: 'objection', object: ['case' => 'case-1']);
		$this->created(slug: 'beroep', object: ['case' => 'case-2']);
		$this->created(slug: 'appealDecision', object: ['case' => 'case-3']);
		$this->created(slug: 'appealDecision', object: ['case' => 'case-4']);
		$this->created(slug: 'objection', object: ['case' => 'case-boom']);
		$this->created(slug: 'objection', object: ['case' => 'case-missing']);

		$this->assertSame(
			[
				'find case-1 false false',
				'has case-1',
				'place case-1: Awb-procedure (objection) geregistreerd — archivering opgeschort',
				'find case-2 false false',
				'has case-2',
				'find case-3 false false',
				'has case-3',
				'release case-3: Awb-procedure (appealDecision) afgehandeld — archivering hervat',
				'find case-4 false false',
				'has case-4',
				'find case-boom false false',
				'has case-boom',
				'warning Dossiq legal-hold: could not apply hold {"app":"dossiq","caseId":"case-boom","place":true,"error":"hold store down"}',
				'find case-missing false false',
				'warning Dossiq legal-hold: NOT applied, collaborator or case unresolved {"app":"dossiq","caseId":"case-missing","place":true,"haveService":true,"haveCaseObject":false}',
			],
			$this->calls
		);
	}//end testHoldsArePlacedAndReleasedOnlyWhenTheyChangeSomething()

	/**
	 * An objection decision releases the hold on the case of its objection, when the objection can be read.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testAnObjectionDecisionReleasesTheHoldOnItsObjectionsCase(): void {
		$this->declareOpenRegisterClasses();
		$this->held = ['case-9'];

		$this->created(slug: 'bezwaarDecision', object: ['objectionProceeding' => 'objection-1']);
		$this->created(slug: 'bezwaarDecision', object: ['objectionProceeding' => 'objection-missing']);
		$this->created(slug: 'bezwaarDecision', object: ['objectionProceeding' => 'objection-text']);
		$this->created(slug: 'bezwaarDecision', object: ['objectionProceeding' => 'objection-2']);
		$this->created(slug: 'bezwaarDecision', object: ['objectionProceeding' => 'objection-boom']);

		$this->assertSame(
			[
				'find objection-1 false false',
				'find case-9 false false',
				'has case-9',
				'release case-9: Awb-procedure (bezwaarDecision) afgehandeld — archivering hervat',
				'find objection-missing false false',
				'find objection-text false false',
				'find objection-2 false false',
				'find objection-boom false false',
				'warning Dossiq legal-hold: could not resolve objection for case linkage {"app":"dossiq","bezwaarId":"objection-boom","error":"mapper down"}',
			],
			$this->calls
		);
	}//end testAnObjectionDecisionReleasesTheHoldOnItsObjectionsCase()
}//end class
