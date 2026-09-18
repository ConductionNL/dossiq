<?php

/**
 * Unit tests for InterventionProvider and the interventions that carry one.
 *
 * 🔴 WHAT THESE GUARD IS A PROVIDER NOBODY CAN PHONE. `deploymentTrajectories`
 * was a string array, so the provider was whatever somebody typed: spelled
 * three ways across four plans, impossible to report on, and impossible to
 * correct once. The refusal here is what makes "a provider is a party" a fact
 * rather than an intention, and it is on SAVE, because a picker in a dialog is
 * not a guard.
 *
 * The second property is overdue, and it is COMPUTED rather than stored. A
 * stored flag is a flag somebody has to remember to clear, and a plan whose
 * overdue marks are a week stale is worse than one with none, because it reads
 * as checked. A completed intervention is never overdue however far its target
 * date has passed, and neither is a stopped one: somebody decided it stops, and
 * putting that decision in the overdue list is a report nobody can act on.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\SociaalDomein;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\SociaalDomein\CasePlanInterventions;
use OCA\Dossiq\Service\SociaalDomein\InterventionProvider;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the provider and the interventions.
 *
 * @covers \OCA\Dossiq\Service\SociaalDomein\InterventionProvider
 * @covers \OCA\Dossiq\Service\SociaalDomein\CasePlanInterventions
 *
 * @uses \OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class InterventionProviderTest extends TestCase {

	/**
	 * The in-memory store.
	 *
	 * @var FakeSociaalDomeinObjects
	 */
	private FakeSociaalDomeinObjects $objects;

	/**
	 * The contact the platform holds for the plan.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $contacts = [];

	/**
	 * A plan and one known provider contact.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeSociaalDomeinObjects();
		$this->objects->seed('gezinsplan', ['id' => 'plan-1', 'caseId' => 'case-1', 'preparedBy' => 'anna']);
		$this->contacts = [
			[
				'contactUid' => 'user:jeugdzorg-midden',
				'displayName' => 'Jeugdzorg Midden',
				'email' => 'aanmelding@jeugdzorgmidden.nl',
				'telephone' => '030 1234567',
				'organisation' => 'Jeugdzorg Midden',
			],
		];
	}//end setUp()

	/**
	 * The service under test, over the in-memory store and a contact service.
	 *
	 * @param boolean $withContacts Whether OpenRegister's contact service answers.
	 *
	 * @return CasePlanInterventions The service.
	 */
	private function interventions(bool $withContacts = true): CasePlanInterventions {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getOpenRegisterClass')->willReturn(
			(($withContacts === true) ? $this->contactService() : null)
		);

		$store = new SociaalDomeinStore(settingsService: $settings, logger: new NullLogger());

		return new CasePlanInterventions(
			store: $store,
			provider: new InterventionProvider(settingsService: $settings, logger: new NullLogger()),
		);
	}//end interventions()

	/**
	 * A stand-in for OpenRegister's contact service.
	 *
	 * 🔑 The method name is the one `PersonLinkReader` already calls. A double
	 * that invented a method the real class lacks could only ever pass.
	 *
	 * @return object The fake.
	 */
	private function contactService(): object {
		return new class($this->contacts) {
			/**
			 * @param array<int, array<string, mixed>> $contacts The contacts.
			 */
			public function __construct(private array $contacts) {
			}//end __construct()

			/**
			 * The contacts linked to one object.
			 *
			 * @param string $objectId The object.
			 *
			 * @return array<string, mixed> The listing.
			 */
			public function getContactsForObject(string $objectId): array {
				return ['results' => $this->contacts];
			}//end getContactsForObject()
		};
	}//end contactService()

	/**
	 * 🔴 A typed name is refused: a provider is a party.
	 *
	 * @return void
	 */
	public function testATypedNameIsRefusedAsAProvider(): void {
		try {
			$this->interventions()->save(
				intervention: [
					'plan' => 'plan-1',
					'title' => 'Ambulante begeleiding',
					'provider' => 'Jeugdzorg Midden',
				]
			);
			self::fail('A typed provider name must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('provider-is-not-a-party', $e->getRule());
			self::assertStringContainsString('cannot be phoned', $e->getSentence());
		}

		self::assertSame([], $this->objects->rowsOf('intervention'));
	}//end testATypedNameIsRefusedAsAProvider()

	/**
	 * An intervention with no provider at all is refused too, differently.
	 *
	 * @return void
	 */
	public function testAnInterventionWithNoProviderIsRefused(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('intervention_needs_a_provider');

		$this->interventions()->save(
			intervention: ['plan' => 'plan-1', 'title' => 'Ambulante begeleiding']
		);
	}//end testAnInterventionWithNoProviderIsRefused()

	/**
	 * 🔴 A provider reference resolves to a party with its contact details.
	 *
	 * @return void
	 */
	public function testAProviderResolvesToAPartyWithItsDetails(): void {
		$service = $this->interventions();
		$service->save(
			intervention: [
				'plan' => 'plan-1',
				'title' => 'Ambulante begeleiding',
				'provider' => 'user:jeugdzorg-midden',
				'targetDate' => '2026-12-31',
			]
		);

		$read = $service->of(planId: 'plan-1', today: '2026-06-01');

		self::assertCount(1, $read);
		self::assertSame('Jeugdzorg Midden', $read[0]['providerParty']['displayName']);
		self::assertSame('030 1234567', $read[0]['providerParty']['telephone']);
		self::assertSame('aanmelding@jeugdzorgmidden.nl', $read[0]['providerParty']['email']);
	}//end testAProviderResolvesToAPartyWithItsDetails()

	/**
	 * An unreachable contact service leaves the plan readable.
	 *
	 * The alternative, refusing the whole plan, would hide the goals too
	 * because the contact service is away.
	 *
	 * @return void
	 */
	public function testAnUnreachableContactServiceStillLetsThePlanRead(): void {
		$service = $this->interventions(withContacts: false);
		$service->save(
			intervention: [
				'plan' => 'plan-1',
				'title' => 'Ambulante begeleiding',
				'provider' => 'user:jeugdzorg-midden',
				'providerName' => 'Jeugdzorg Midden',
			]
		);

		$read = $service->of(planId: 'plan-1', today: '2026-06-01');

		self::assertNull($read[0]['providerParty']);
		// The stored display name is still there, so the plan says something.
		self::assertSame('Jeugdzorg Midden', $read[0]['providerName']);
	}//end testAnUnreachableContactServiceStillLetsThePlanRead()

	/**
	 * 🔴 An intervention past its target date and still open is overdue.
	 *
	 * @return void
	 */
	public function testAnOpenInterventionPastItsTargetDateIsOverdue(): void {
		$service = $this->interventions();
		$service->save(
			intervention: [
				'plan' => 'plan-1',
				'title' => 'Ambulante begeleiding',
				'provider' => 'user:jeugdzorg-midden',
				'targetDate' => '2026-05-01',
				'state' => 'running',
			]
		);

		$read = $service->of(planId: 'plan-1', today: '2026-06-01');

		self::assertTrue($read[0]['overdue']);
	}//end testAnOpenInterventionPastItsTargetDateIsOverdue()

	/**
	 * 🔴 A finished or stopped intervention is never overdue.
	 *
	 * A completed intervention in the overdue list is noise, and a stopped one
	 * is a decision reported as a failure.
	 *
	 * @return void
	 */
	public function testAFinishedOrStoppedInterventionIsNotOverdue(): void {
		$service = $this->interventions();

		foreach (['complete', 'stopped'] as $state) {
			self::assertFalse(
				$service->isOverdue(
					intervention: ['targetDate' => '2026-05-01', 'state' => $state],
					today: '2026-06-01'
				),
				'A ' . $state . ' intervention is not overdue.'
			);
		}

		// And one with no target date at all is not overdue either: nothing was
		// promised, so nothing is late.
		self::assertFalse(
			$service->isOverdue(intervention: ['state' => 'running'], today: '2026-06-01')
		);
	}//end testAFinishedOrStoppedInterventionIsNotOverdue()

	/**
	 * Several interventions can serve one goal, which is what a plan looks like.
	 *
	 * @return void
	 */
	public function testSeveralInterventionsCanServeOneGoal(): void {
		$service = $this->interventions();
		foreach (['Ambulante begeleiding', 'Weerbaarheidstraining'] as $title) {
			$service->save(
				intervention: [
					'plan' => 'plan-1',
					'goal' => 'goal-1',
					'title' => $title,
					'provider' => 'user:jeugdzorg-midden',
				]
			);
		}

		$read = $service->of(planId: 'plan-1', today: '2026-06-01');

		self::assertCount(2, $read);
		self::assertSame(['goal-1', 'goal-1'], array_column($read, 'goal'));
	}//end testSeveralInterventionsCanServeOneGoal()

	/**
	 * The shape test on its own: what counts as a reference, and what does not.
	 *
	 * @return void
	 */
	public function testWhatCountsAsAReference(): void {
		$provider = new InterventionProvider(
			settingsService: $this->createMock(SettingsService::class),
			logger: new NullLogger(),
		);

		self::assertTrue($provider->isReference(value: 'user:anna'));
		self::assertTrue($provider->isReference(value: 'b1e2c3d4-5678-90ab-cdef-1234567890ab'));
		self::assertFalse($provider->isReference(value: 'Jeugdzorg Midden'), 'a name with a space');
		self::assertFalse($provider->isReference(value: 'Buurtteam'), 'a short word somebody typed');
		self::assertFalse($provider->isReference(value: 'user:'), 'a prefix with nothing behind it');
		self::assertFalse($provider->isReference(value: ''));
	}//end testWhatCountsAsAReference()
}//end class
