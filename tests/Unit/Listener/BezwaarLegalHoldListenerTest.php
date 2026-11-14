<?php

/**
 * Which proceeding events the legal-hold listener acts on, for which case and in which direction.
 *
 * Characterisation written against the unsplit handle() and run green on it
 * before it was split. With OpenRegister's hold service absent (as in a unit
 * run) the listener names every hold it would apply in its "not applied"
 * warning, which is what these tests read.
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
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\BezwaarLegalHoldListener
 * @uses \OCA\Dossiq\Listener\BezwaarDecisionListener
 * @uses \OCA\Dossiq\Listener\Support\EventObjectPayload
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 */
class BezwaarLegalHoldListenerTest extends TestCase {

	/**
	 * The holds the listener tried to apply: [caseId, place].
	 *
	 * @var array<int, array{0: string, 1: bool}>
	 */
	private array $holds = [];

	/**
	 * How many payloads the slug resolver was asked about.
	 *
	 * @var integer
	 */
	private int $resolved = 0;

	/**
	 * Handle one created object of the given schema.
	 *
	 * @param string               $slug   The schema slug the resolver answers.
	 * @param array<string, mixed> $object The object's fields.
	 *
	 * @return void
	 */
	private function created(string $slug, array $object): void {
		$entity = new ObjectEntity();
		$entity->setObject($object);
		$this->listener(slug: $slug)->handle(new ObjectCreatedEvent($entity));
	}//end created()

	/**
	 * The listener over a slug resolver answering one slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return BezwaarLegalHoldListener
	 */
	private function listener(string $slug): BezwaarLegalHoldListener {
		$resolver = $this->createMock(ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturnCallback(
			function () use ($slug): string {
				$this->resolved++;
				return $slug;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				if (str_contains($message, 'NOT applied') === true) {
					$this->holds[] = [$context['caseId'], $context['place']];
				}
			}
		);

		return new BezwaarLegalHoldListener($this->createMock(ContainerInterface::class), $resolver, $logger);
	}//end listener()

	/**
	 * An opening proceeding places a hold on its case, a closing decision releases it, anything else does nothing.
	 *
	 * @return void
	 */
	public function testProceedingsPlaceAndReleaseHoldsOnTheirCase(): void {
		$this->created(slug: 'objection', object: ['case' => 'case-1']);
		$this->created(slug: 'objectionProceeding', object: ['case' => 'case-2']);
		$this->created(slug: 'beroep', object: ['case' => 'case-3']);
		$this->created(slug: 'appealDecision', object: ['case' => 'case-4']);
		$this->created(slug: 'hearing', object: ['case' => 'case-5']);
		$this->created(slug: 'beroep', object: ['case' => '']);
		$this->created(slug: 'beroep', object: []);

		$this->assertSame(
			[['case-1', true], ['case-2', true], ['case-3', true], ['case-4', false]],
			$this->holds
		);
	}//end testProceedingsPlaceAndReleaseHoldsOnTheirCase()

	/**
	 * An objection decision is traced to its case through its objection; without one, or unresolvable, nothing happens.
	 *
	 * @return void
	 */
	public function testAnObjectionDecisionNeedsItsObjection(): void {
		$this->created(slug: 'bezwaarDecision', object: ['case' => 'case-1']);
		$this->created(slug: 'bezwaarDecision', object: ['objectionProceeding' => 'objection-1', 'case' => 'case-1']);

		$this->assertSame([], $this->holds);
	}//end testAnObjectionDecisionNeedsItsObjection()

	/**
	 * Only a created object is looked at.
	 *
	 * @return void
	 */
	public function testOnlyACreatedObjectIsLookedAt(): void {
		$this->listener(slug: 'objection')->handle(new Event());

		$this->assertSame(0, $this->resolved);
		$this->assertSame([], $this->holds);
	}//end testOnlyACreatedObjectIsLookedAt()
}//end class
