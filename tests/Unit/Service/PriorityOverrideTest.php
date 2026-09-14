<?php

/**
 * Unit tests for the human override — the fact, not the state.
 *
 * 🔴 THE FAILURE THIS GUARDS IS A CASE THAT SINKS BACK OVERNIGHT. A wethouder
 * calls, a handler raises the priority, and tomorrow the derivation runs and
 * puts it back. Nobody notices until the case is late, because nothing errored:
 * a field was written and a later save wrote it again. So these tests do not
 * assert that an override can be SET — that is trivially true of any writable
 * field. They assert that it SURVIVES the next derivation, that clearing it
 * returns to the value the matrix derives NOW rather than the one it derived
 * when the override was made, and that re-saving a case does not quietly
 * reassign somebody else's decision to whoever touched it last.
 *
 * The listener is exercised through `handle()` rather than through its private
 * parts, because what is under test is the decision it makes when the event
 * arrives, and the event stubs mirror OpenRegister's real surface.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Listener\CasePriorityDerivationListener;
use OCA\Dossiq\Service\CasePriorityService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PriorityOverrideTest extends TestCase {

	/**
	 * An entity carrying one case payload.
	 *
	 * @param array<string, mixed> $payload The case.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $payload): ObjectEntity {
		$payload['@self'] = ['schema' => 'case'];
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($payload);

		return $entity;
	}//end entity()

	/**
	 * A listener whose session is this user, over these case types.
	 *
	 * @param string $uid The signed-in user, or the empty string for none.
	 * @param array<string, array<string, mixed>> $caseTypes Effective case types.
	 *
	 * @return CasePriorityDerivationListener The listener.
	 */
	private function listener(string $uid = 'teamleider', array $caseTypes = []): CasePriorityDerivationListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'case_schema') ? 'case' : ''
		);

		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(
			static fn (string $id): array => ($caseTypes[$id] ?? [])
		);

		$session = $this->createMock(IUserSession::class);
		if ($uid === '') {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new CasePriorityDerivationListener(
			$settings,
			new CasePriorityService($resolver, new NullLogger()),
			$session,
			new NullLogger()
		);
	}//end listener()

	/**
	 * What the listener wrote for this update.
	 *
	 * @param array<string, mixed> $now      The case as it is being saved.
	 * @param array<string, mixed>|null $before The case as it was, or null on create.
	 * @param string $uid The signed-in user.
	 * @param array<string, array<string, mixed>> $caseTypes Effective case types.
	 *
	 * @return array<string, mixed> The modified data.
	 */
	private function written(array $now, ?array $before = null, string $uid = 'teamleider', array $caseTypes = []): array {
		if ($before === null) {
			$event = new ObjectCreatingEvent($this->entity($now));
		} else {
			$event = new ObjectUpdatingEvent($this->entity($now), $this->entity($before));
		}

		$this->listener(uid: $uid, caseTypes: $caseTypes)->handle($event);

		return $event->getModifiedData();
	}//end written()

	/**
	 * An override survives the next derivation (REQ-PRI-03).
	 *
	 * The case's own impact and urgency derive `normal`. The override says
	 * `urgent`, and `urgent` is what the case must read afterwards.
	 */
	public function testAnOverrideSurvivesTheNextDerivation(): void {
		$written = $this->written(
			now: [
				'impact' => 'medium',
				'urgency' => 'medium',
				'priorityOverride' => 'urgent',
				'priorityOverrideReason' => 'Wethouder heeft gebeld',
			],
			before: [
				'impact' => 'medium',
				'urgency' => 'medium',
				'priorityOverride' => 'urgent',
				'priorityOverrideReason' => 'Wethouder heeft gebeld',
			]
		);

		self::assertSame('urgent', $written['priority']);
		self::assertSame('normal', $written['priorityDerived'], 'the matrix keeps answering underneath');
		self::assertSame(4, $written['priorityOrder']);
	}//end testAnOverrideSurvivesTheNextDerivation()

	/**
	 * A new override says who set it and when (REQ-PRI-03).
	 */
	public function testANewOverrideRecordsWhoAndWhen(): void {
		$written = $this->written(
			now: ['impact' => 'medium', 'urgency' => 'medium', 'priorityOverride' => 'urgent'],
			before: ['impact' => 'medium', 'urgency' => 'medium'],
			uid: 'teamleider'
		);

		self::assertSame('teamleider', $written['priorityOverrideBy']);
		self::assertNotEmpty($written['priorityOverrideAt']);
		self::assertNotFalse(
			strtotime((string)$written['priorityOverrideAt']),
			'the moment must be a date a reader can parse, not a label'
		);
	}//end testANewOverrideRecordsWhoAndWhen()

	/**
	 * The byline is taken from the session, not from the payload. Otherwise
	 * anybody could attribute their own decision to somebody else.
	 */
	public function testTheBylineIsNotTakenFromThePayload(): void {
		$written = $this->written(
			now: [
				'impact' => 'medium',
				'urgency' => 'medium',
				'priorityOverride' => 'urgent',
				'priorityOverrideBy' => 'de-burgemeester',
			],
			before: ['impact' => 'medium', 'urgency' => 'medium'],
			uid: 'handler-a'
		);

		self::assertSame('handler-a', $written['priorityOverrideBy']);
	}//end testTheBylineIsNotTakenFromThePayload()

	/**
	 * Re-saving a case with an unchanged override leaves the byline alone.
	 *
	 * 🔴 This is the quiet one. A stamp written on every save would reassign a
	 * teamleider's decision to whoever last edited the description, and the
	 * case would still read `urgent`, so nothing would look wrong.
	 */
	public function testAnUnchangedOverrideIsNotRestamped(): void {
		$written = $this->written(
			now: [
				'impact' => 'medium',
				'urgency' => 'medium',
				'priorityOverride' => 'urgent',
				'priorityOverrideBy' => 'teamleider',
				'title' => 'A changed title',
			],
			before: [
				'impact' => 'medium',
				'urgency' => 'medium',
				'priorityOverride' => 'urgent',
				'priorityOverrideBy' => 'teamleider',
				'title' => 'The old title',
			],
			uid: 'somebody-else'
		);

		self::assertArrayNotHasKey('priorityOverrideBy', $written);
		self::assertArrayNotHasKey('priorityOverrideAt', $written);
		self::assertSame('urgent', $written['priority'], 'and the override still stands');
	}//end testAnUnchangedOverrideIsNotRestamped()

	/**
	 * Changing an override to a different value re-stamps it: it is a new
	 * decision by a possibly different person.
	 */
	public function testChangingTheOverrideRestampsIt(): void {
		$written = $this->written(
			now: [
				'impact' => 'medium',
				'urgency' => 'medium',
				'priorityOverride' => 'high',
				'priorityOverrideBy' => 'teamleider',
			],
			before: [
				'impact' => 'medium',
				'urgency' => 'medium',
				'priorityOverride' => 'urgent',
				'priorityOverrideBy' => 'teamleider',
			],
			uid: 'manager'
		);

		self::assertSame('manager', $written['priorityOverrideBy']);
		self::assertSame('high', $written['priority']);
	}//end testChangingTheOverrideRestampsIt()

	/**
	 * Clearing the override returns the case to the value the matrix derives
	 * NOW, not to the one it derived when the override was made (REQ-PRI-03).
	 *
	 * The urgency changed while the override stood. Clearing it must give
	 * `high`, the answer for the urgency the case carries today.
	 */
	public function testClearingTheOverrideReturnsToTheDerivedAnswer(): void {
		$written = $this->written(
			now: ['impact' => 'medium', 'urgency' => 'high', 'priorityOverride' => ''],
			before: [
				'impact' => 'medium',
				'urgency' => 'medium',
				'priorityOverride' => 'urgent',
				'priorityOverrideBy' => 'teamleider',
				'priorityOverrideReason' => 'Wethouder heeft gebeld',
			]
		);

		self::assertSame('high', $written['priority']);
		self::assertSame('high', $written['priorityDerived']);
	}//end testClearingTheOverrideReturnsToTheDerivedAnswer()

	/**
	 * Clearing the override does not leave the overridden value behind: the
	 * byline and the reason go with it (REQ-PRI-03).
	 */
	public function testClearingTheOverrideTakesItsBylineAndReasonWithIt(): void {
		$written = $this->written(
			now: ['impact' => 'medium', 'urgency' => 'medium', 'priorityOverride' => ''],
			before: [
				'impact' => 'medium',
				'urgency' => 'medium',
				'priorityOverride' => 'urgent',
				'priorityOverrideBy' => 'teamleider',
				'priorityOverrideAt' => '2026-09-01T10:00:00+02:00',
				'priorityOverrideReason' => 'Wethouder heeft gebeld',
			]
		);

		self::assertNull($written['priorityOverrideBy']);
		self::assertNull($written['priorityOverrideAt']);
		self::assertNull($written['priorityOverrideReason']);
	}//end testClearingTheOverrideTakesItsBylineAndReasonWithIt()

	/**
	 * An override naming a value outside the vocabulary is ignored, and the
	 * derived answer stands. Accepting it would put a word in `priority` that
	 * the enum does not carry.
	 */
	public function testAnOverrideOutsideTheVocabularyIsIgnored(): void {
		$written = $this->written(
			now: ['impact' => 'medium', 'urgency' => 'medium', 'priorityOverride' => 'blocker'],
			before: ['impact' => 'medium', 'urgency' => 'medium']
		);

		self::assertSame('normal', $written['priority']);
	}//end testAnOverrideOutsideTheVocabularyIsIgnored()

	/**
	 * A background write with no session is attributed to the system, not to
	 * an empty string that would read as an anonymous person.
	 */
	public function testABackgroundOverrideIsAttributedToTheSystem(): void {
		$written = $this->written(
			now: ['impact' => 'medium', 'urgency' => 'medium', 'priorityOverride' => 'urgent'],
			before: ['impact' => 'medium', 'urgency' => 'medium'],
			uid: ''
		);

		self::assertSame('system', $written['priorityOverrideBy']);
	}//end testABackgroundOverrideIsAttributedToTheSystem()

	/**
	 * An override set at creation is stamped too.
	 */
	public function testAnOverrideSetAtCreationIsStamped(): void {
		$written = $this->written(
			now: ['impact' => 'medium', 'urgency' => 'medium', 'priorityOverride' => 'urgent'],
			uid: 'handler-a'
		);

		self::assertSame('handler-a', $written['priorityOverrideBy']);
		self::assertSame('urgent', $written['priority']);
	}//end testAnOverrideSetAtCreationIsStamped()

	/**
	 * A case created with no priority fields at all still gets the whole block,
	 * so no case reaches a list without a sort key.
	 */
	public function testACaseCreatedBareStillGetsTheWholeBlock(): void {
		$written = $this->written(now: ['title' => 'A case']);

		self::assertSame('medium', $written['impact']);
		self::assertSame('medium', $written['urgency']);
		self::assertSame('normal', $written['priority']);
		self::assertSame('normal', $written['priorityDerived']);
		self::assertSame(2, $written['priorityOrder']);
	}//end testACaseCreatedBareStillGetsTheWholeBlock()

	/**
	 * An object that is not a case is left entirely alone. The listener is
	 * registered on every ObjectCreatingEvent in the instance, so a complaint,
	 * a document or a task must come out untouched.
	 */
	public function testAnObjectThatIsNotACaseIsLeftAlone(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('case');

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(
			['@self' => ['schema' => 'complaint'], 'priority' => 'urgent']
		);
		$event = new ObjectCreatingEvent($entity);

		$this->listener()->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnObjectThatIsNotACaseIsLeftAlone()

	/**
	 * A field another pre-persist listener already wrote is read, not lost.
	 *
	 * The derivation runs LAST of the pre-persist listeners, so the impact it
	 * reads has to include anything an earlier one put in `modifiedData`; and
	 * what it writes has to be merged onto that rather than replacing it.
	 */
	public function testItReadsAndKeepsWhatAnEarlierListenerWrote(): void {
		$entity = $this->entity(['impact' => 'medium', 'urgency' => 'medium']);
		$event = new ObjectCreatingEvent($entity);
		$event->setModifiedData(['deadline' => '2026-12-01', 'urgency' => 'high']);

		$this->listener()->handle($event);
		$written = $event->getModifiedData();

		self::assertSame('2026-12-01', $written['deadline'], 'an earlier listener is not clobbered');
		self::assertSame('high', $written['urgency']);
		self::assertSame('high', $written['priority'], 'and the derivation read it');
	}//end testItReadsAndKeepsWhatAnEarlierListenerWrote()
}//end class
