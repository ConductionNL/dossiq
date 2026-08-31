<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Parafeer
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Parafeer;

use OCA\Decidiq\Event\ApprovalRouteRequestedEvent;
use OCA\Dossiq\Service\Parafeer\ParaferingDelegationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the route command dossiq sends to the decision app.
 *
 * The step assertions carry the weight. `order` IS the sign-off sequence on the
 * other side, so a step that loses it does not produce a broken route — it
 * produces a plausible one in the wrong order, which is a signature chain with
 * the wrong person at the end.
 */
class ParaferingDelegationServiceTest extends TestCase {

	/**
	 * Events seen by the dispatcher.
	 *
	 * @var array<int, Event>
	 */
	private array $dispatched = [];

	/**
	 * Build the service with a recording dispatcher.
	 *
	 * @param boolean $handled   What the fake decision app reports.
	 * @param string  $returnsId The id it reports.
	 *
	 * @return ParaferingDelegationService The service.
	 */
	private function service(bool $handled = true, string $returnsId = 'ar-1'): ParaferingDelegationService {
		$seen = &$this->dispatched;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use (&$seen, $handled, $returnsId): void {
				$seen[] = $event;
				if ($event instanceof ApprovalRouteRequestedEvent === false || $handled === false) {
					return;
				}

				$event->setRouteId($returnsId);
				$event->setCreated(true);
				$event->setStageCount(count($event->getSteps()));
				$event->setHandled(true);
			}
		);

		return new ParaferingDelegationService($dispatcher, $this->createMock(LoggerInterface::class));

	}//end service()

	/**
	 * A local parafeerroute row.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed> The route.
	 */
	private function route(array $overrides = []): array {
		return ($overrides + [
			'id' => 'pr-1',
			'name' => 'Collegeadvies - Omgevingsvergunning',
			'proposalType' => 'collegeadvies',
			'description' => 'Standaard parafering',
			'isDefault' => true,
			'steps' => [
				['order' => 1, 'type' => 'advies', 'actorType' => 'role', 'actor' => 'juridisch-adviseur', 'mandatory' => true, 'label' => 'Juridisch advies'],
				['order' => 2, 'type' => 'parafering', 'actorType' => 'role', 'actor' => 'afdelingshoofd', 'mandatory' => true, 'label' => 'Parafering'],
				['order' => 3, 'type' => 'accordering', 'actorType' => 'person', 'actor' => 'gemeentesecretaris', 'mandatory' => false, 'label' => 'Accordering'],
			],
		]);

	}//end route()

	/**
	 * The event that was dispatched.
	 *
	 * @return ApprovalRouteRequestedEvent The command.
	 */
	private function command(): ApprovalRouteRequestedEvent {
		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(ApprovalRouteRequestedEvent::class, $event);

		return $event;

	}//end command()

	/**
	 * The command carries the route identity and returns the id.
	 *
	 * @return void
	 */
	public function testCommandCarriesRouteIdentity(): void {
		$id = $this->service()->holdRoute(route: $this->route());

		$this->assertSame('ar-1', $id);

		$event = $this->command();
		$this->assertSame('dossiq', $event->getSourceApp());
		$this->assertSame('pr-1', $event->getExternalReference());
		$this->assertSame('pr-1', $event->getCorrelationId());
		$this->assertSame('Collegeadvies - Omgevingsvergunning', $event->getName());
		$this->assertSame('collegeadvies', $event->getSubjectType());
		$this->assertSame('Standaard parafering', $event->getDescription());
		$this->assertTrue($event->isDefault());

	}//end testCommandCarriesRouteIdentity()

	/**
	 * The step order survives the move, because order IS the sequence.
	 *
	 * @return void
	 */
	public function testStepOrderSurvivesTheMove(): void {
		$this->service()->holdRoute(route: $this->route());

		$steps = $this->command()->getSteps();
		$this->assertCount(3, $steps);
		$this->assertSame([1, 2, 3], array_column($steps, 'order'));
		$this->assertSame(
			['juridisch-adviseur', 'afdelingshoofd', 'gemeentesecretaris'],
			array_column($steps, 'actor')
		);
		$this->assertSame([true, true, false], array_column($steps, 'mandatory'));

	}//end testStepOrderSurvivesTheMove()

	/**
	 * The local step types map onto the approval-route vocabulary.
	 *
	 * @return void
	 */
	public function testStepTypesAreTranslated(): void {
		$this->service()->holdRoute(route: $this->route());

		$this->assertSame(
			['advisory', 'endorsement', 'decisive'],
			array_column($this->command()->getSteps(), 'stageType')
		);

	}//end testStepTypesAreTranslated()

	/**
	 * An unrecognised step type travels as an endorsement, not as nothing.
	 *
	 * @return void
	 */
	public function testUnknownStepTypeBecomesEndorsement(): void {
		$route = $this->route();
		$route['steps'] = [['order' => 1, 'type' => 'iets-nieuws', 'actor' => 'x']];

		$this->service()->holdRoute(route: $route);

		$this->assertSame('endorsement', $this->command()->getSteps()[0]['stageType']);

	}//end testUnknownStepTypeBecomesEndorsement()

	/**
	 * A step with no order gets its position, so the sequence is never lost.
	 *
	 * @return void
	 */
	public function testStepWithoutOrderFallsBackToItsPosition(): void {
		$route = $this->route();
		$route['steps'] = [
			['type' => 'advies', 'actor' => 'a'],
			['type' => 'parafering', 'actor' => 'b'],
		];

		$this->service()->holdRoute(route: $route);

		$this->assertSame([1, 2], array_column($this->command()->getSteps(), 'order'));

	}//end testStepWithoutOrderFallsBackToItsPosition()

	/**
	 * Steps stored as a JSON string are accepted too.
	 *
	 * @return void
	 */
	public function testJsonEncodedStepsAreAccepted(): void {
		$route = $this->route();
		$route['steps'] = json_encode($route['steps']);

		$this->service()->holdRoute(route: $route);

		$this->assertCount(3, $this->command()->getSteps());

	}//end testJsonEncodedStepsAreAccepted()

	/**
	 * A subject is passed through so the chain starts in the same command.
	 *
	 * @return void
	 */
	public function testSubjectStartsTheChain(): void {
		$this->service()->holdRoute(
			route: $this->route(),
			actorId: 'admin',
			subject: 'voorstel-1',
			subjectSchema: 'proposal',
		);

		$event = $this->command();
		$this->assertSame('voorstel-1', $event->getSubject());
		$this->assertSame('proposal', $event->getSubjectSchema());
		$this->assertSame('admin', $event->getActorId());
		$this->assertSame(3, $event->getStageCount());

	}//end testSubjectStartsTheChain()

	/**
	 * A route with no steps is refused before anything is dispatched.
	 *
	 * @return void
	 */
	public function testRouteWithNoStepsIsRefused(): void {
		$route = $this->route(['steps' => []]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/nothing to travel/');

		try {
			$this->service()->holdRoute(route: $route);
		} finally {
			$this->assertCount(0, $this->dispatched, 'nothing is dispatched on a refusal');
		}

	}//end testRouteWithNoStepsIsRefused()

	/**
	 * A route with no id cannot be held.
	 *
	 * @return void
	 */
	public function testRouteWithoutIdIsRefused(): void {
		$route = $this->route();
		unset($route['id']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/needs an id/');

		$this->service()->holdRoute(route: $route);

	}//end testRouteWithoutIdIsRefused()

	/**
	 * A route with no name cannot be held.
	 *
	 * @return void
	 */
	public function testRouteWithoutNameIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/needs a name/');

		$this->service()->holdRoute(route: $this->route(['name' => '']));

	}//end testRouteWithoutNameIsRefused()

	/**
	 * An unhandled command fails closed rather than returning an empty id.
	 *
	 * @return void
	 */
	public function testUnhandledCommandFailsClosed(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/did not handle/');

		$this->service(handled: false)->holdRoute(route: $this->route());

	}//end testUnhandledCommandFailsClosed()

	/**
	 * The seam is reported available because a stub class exists.
	 *
	 * @return void
	 */
	public function testSeamIsAvailableWhenTheEventClassExists(): void {
		$this->assertTrue($this->service()->isAvailable());

	}//end testSeamIsAvailableWhenTheEventClassExists()

}//end class
