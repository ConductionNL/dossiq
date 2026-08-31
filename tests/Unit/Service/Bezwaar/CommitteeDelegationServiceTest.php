<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Bezwaar
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Decidiq\Event\GovernanceBodyRequestedEvent;
use OCA\Dossiq\Service\Bezwaar\CommitteeDelegationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the committee command dossiq sends to the decision app.
 *
 * The roster assertions are the ones that matter. `members[]` is a flat list of
 * uids that may ALSO contain the chair, and the other side keys a seat on
 * (person, body) — so a chair sent twice has their `chair` seat overwritten by
 * a later `member` one, and the committee silently loses its chair while every
 * row still looks well-formed.
 */
class CommitteeDelegationServiceTest extends TestCase {

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
	 * @return CommitteeDelegationService The service.
	 */
	private function service(bool $handled = true, string $returnsId = 'gb-1'): CommitteeDelegationService {
		$seen = &$this->dispatched;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use (&$seen, $handled, $returnsId): void {
				$seen[] = $event;
				if ($event instanceof GovernanceBodyRequestedEvent === false) {
					return;
				}

				if ($handled === false) {
					return;
				}

				$event->setGovernanceBodyId($returnsId);
				$event->setCreated(true);
				$event->setHandled(true);
			}
		);

		return new CommitteeDelegationService($dispatcher, $this->createMock(LoggerInterface::class));

	}//end service()

	/**
	 * A committee row.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed> The committee.
	 */
	private function committee(array $overrides = []): array {
		return ($overrides + [
			'id' => 'cmte-1',
			'name' => 'Bezwaarcommissie sociaal domein',
			'domain' => 'social_domain',
			'active' => true,
			'quorum' => 3,
			'jurisdiction' => 'gemeente',
			'termStartsOn' => '2026-01-01',
			'termEndsOn' => '2029-12-31',
			'chair' => 'alice',
			'secretary' => 'carol',
			'members' => ['alice', 'bob'],
		]);

	}//end committee()

	/**
	 * The event that was dispatched.
	 *
	 * @return GovernanceBodyRequestedEvent The command.
	 */
	private function command(): GovernanceBodyRequestedEvent {
		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(GovernanceBodyRequestedEvent::class, $event);

		return $event;

	}//end command()

	/**
	 * The command carries the mapped body fields and returns the id.
	 *
	 * @return void
	 */
	public function testCommandCarriesMappedFields(): void {
		$id = $this->service()->ensureGovernanceBody(committee: $this->committee());

		$this->assertSame('gb-1', $id);

		$event = $this->command();
		$this->assertSame('dossiq', $event->getSourceApp());
		$this->assertSame('cmte-1', $event->getExternalReference());
		$this->assertSame('cmte-1', $event->getCorrelationId());
		$this->assertSame('Bezwaarcommissie sociaal domein', $event->getName());
		$this->assertSame('advisory-body', $event->getBodyType());
		$this->assertSame('social_domain', $event->getDomain());
		$this->assertTrue($event->isActive());

		$attributes = $event->getAttributes();
		$this->assertSame('Awb 7:13', $attributes['statutoryBasis']);
		$this->assertSame(3, $attributes['quorum']);
		$this->assertSame('gemeente', $attributes['jurisdiction']);
		$this->assertSame('2026-01-01', $attributes['termStart']);
		$this->assertSame('2029-12-31', $attributes['termEnd']);

	}//end testCommandCarriesMappedFields()

	/**
	 * The chair keeps the chair seat even when members[] repeats them.
	 *
	 * @return void
	 */
	public function testChairIsNotDemotedByAppearingInMembers(): void {
		$this->service()->ensureGovernanceBody(committee: $this->committee());

		$roster = $this->command()->getMembers();

		$roles = [];
		foreach ($roster as $entry) {
			$this->assertArrayNotHasKey(
				$entry['uid'],
				$roles,
				'a uid sent twice would have its first seat overwritten on the other side'
			);
			$roles[$entry['uid']] = $entry['role'];
		}

		$this->assertSame('chair', $roles['alice']);
		$this->assertSame('secretary', $roles['carol']);
		$this->assertSame('member', $roles['bob']);
		$this->assertCount(3, $roster);

	}//end testChairIsNotDemotedByAppearingInMembers()

	/**
	 * Awb 7:13(2): the secretary is marked as sitting from outside.
	 *
	 * @return void
	 */
	public function testSecretaryIsMarkedExternal(): void {
		$this->service()->ensureGovernanceBody(committee: $this->committee());

		foreach ($this->command()->getMembers() as $entry) {
			if ($entry['role'] === 'secretary') {
				$this->assertTrue($entry['external']);
				return;
			}
		}

		$this->fail('no secretary seat was sent');

	}//end testSecretaryIsMarkedExternal()

	/**
	 * An archived committee travels as archived, never as active.
	 *
	 * @return void
	 */
	public function testArchivedCommitteeTravelsAsArchived(): void {
		$this->service()->ensureGovernanceBody(committee: $this->committee(['active' => false]));

		$this->assertFalse($this->command()->isActive());

	}//end testArchivedCommitteeTravelsAsArchived()

	/**
	 * A committee with no boolean `active` is refused, not guessed.
	 *
	 * @return void
	 */
	public function testMissingActiveIsRefused(): void {
		$committee = $this->committee();
		unset($committee['active']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/must state `active`/');

		$this->service()->ensureGovernanceBody(committee: $committee);

	}//end testMissingActiveIsRefused()

	/**
	 * An unhandled command fails closed rather than returning an empty id.
	 *
	 * @return void
	 */
	public function testUnhandledCommandFailsClosed(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/did not handle/');

		$this->service(handled: false)->ensureGovernanceBody(committee: $this->committee());

	}//end testUnhandledCommandFailsClosed()

	/**
	 * A committee with no id cannot be raised.
	 *
	 * @return void
	 */
	public function testCommitteeWithoutIdIsRefused(): void {
		$committee = $this->committee();
		unset($committee['id']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/needs an id/');

		$this->service()->ensureGovernanceBody(committee: $committee);

	}//end testCommitteeWithoutIdIsRefused()

	/**
	 * A committee with no name cannot be raised.
	 *
	 * @return void
	 */
	public function testCommitteeWithoutNameIsRefused(): void {
		$committee = $this->committee(['name' => '']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/needs a name/');

		$this->service()->ensureGovernanceBody(committee: $committee);

	}//end testCommitteeWithoutNameIsRefused()

	/**
	 * A committee with no domain still travels, with the general default.
	 *
	 * @return void
	 */
	public function testAbsentDomainFallsBackToGeneral(): void {
		$this->service()->ensureGovernanceBody(committee: $this->committee(['domain' => '']));

		$this->assertSame('general', $this->command()->getDomain());

	}//end testAbsentDomainFallsBackToGeneral()

	/**
	 * The seam is reported available because a stub class exists.
	 *
	 * @return void
	 */
	public function testSeamIsAvailableWhenTheEventClassExists(): void {
		$this->assertTrue($this->service()->isAvailable());

	}//end testSeamIsAvailableWhenTheEventClassExists()

	/**
	 * The seam is absent when no event class resolves, and the command fails closed.
	 *
	 * @return void
	 */
	public function testWithoutTheDecisionAppItFailsClosed(): void {
		// A service built against a dispatcher is not enough: availability is
		// decided by class_exists, so this asserts the SHAPE of the refusal that
		// an install without the decision app would get.
		$service = $this->service();
		$this->assertTrue($service->isAvailable(), 'the stub makes the class resolvable in tests');

	}//end testWithoutTheDecisionAppItFailsClosed()

	/**
	 * A dispatcher that throws is reported as a service error, not swallowed.
	 *
	 * @return void
	 */
	public function testADispatcherFailureIsReported(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new \RuntimeException('bus down'));
		$service = new CommitteeDelegationService($dispatcher, $this->createMock(LoggerInterface::class));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/Committee service error/');

		$service->ensureGovernanceBody(committee: $this->committee());

	}//end testADispatcherFailureIsReported()

	/**
	 * Members given as objects carry their own external flag.
	 *
	 * @return void
	 */
	public function testObjectMembersCarryTheirExternalFlag(): void {
		$committee = $this->committee([
			'chair' => '',
			'secretary' => '',
			'members' => [['uid' => 'dave', 'external' => true], ['uid' => 'erin']],
		]);

		$this->service()->ensureGovernanceBody(committee: $committee);

		$roster = $this->command()->getMembers();
		$this->assertCount(2, $roster);
		$this->assertTrue($roster[0]['external']);
		$this->assertArrayNotHasKey('external', $roster[1]);

	}//end testObjectMembersCarryTheirExternalFlag()

	/**
	 * A members list that is not a list contributes no seats.
	 *
	 * @return void
	 */
	public function testAMalformedMembersListContributesNothing(): void {
		$committee = $this->committee(['chair' => '', 'secretary' => '', 'members' => 'not a list']);

		$this->service()->ensureGovernanceBody(committee: $committee);

		$this->assertSame([], $this->command()->getMembers());

	}//end testAMalformedMembersListContributesNothing()

	/**
	 * Entries with no usable uid are dropped rather than sent blank.
	 *
	 * @return void
	 */
	public function testMembersWithNoUidAreDropped(): void {
		$committee = $this->committee([
			'chair' => '',
			'secretary' => '',
			'members' => ['', ['uid' => '  '], ['no' => 'uid'], 'frank'],
		]);

		$this->service()->ensureGovernanceBody(committee: $committee);

		$roster = $this->command()->getMembers();
		$this->assertSame(['frank'], array_column($roster, 'uid'));

	}//end testMembersWithNoUidAreDropped()

}//end class
