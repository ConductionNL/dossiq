<?php

/**
 * A case with private records changes hands only through a supervisor.
 *
 * Driven with OpenRegister's own update events and the real reviewer access
 * over one in-memory register; only the OpenRegister grant side is doubled.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-with-private-records-changes-hands-only-through-a-supervisor-req-cra-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseReassignGuardListener;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\Access\ObjectReadGrants;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-CRA-005.
 *
 * @covers \OCA\Dossiq\Listener\CaseReassignGuardListener
 * @uses   \OCA\Dossiq\Service\Access\CaseRecordLookup
 * @uses   \OCA\Dossiq\Service\Access\CaseReviewerAccess
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseReassignGuardListenerTest extends TestCase {

	private const CASE_ID = '44444444-4444-4444-8444-444444444444';

	private const CASE_TYPE = '55555555-5555-4555-8555-555555555555';

	private InMemoryRegister $register;

	/** @var list<array{uuid: string, readers: list<string>}> */
	private array $grantCalls = [];

	private string $caller = 'handler';

	private CaseReassignGuardListener $listener;

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_schema' => 'case',
				'assessment_schema_key' => 'assessment',
			][$key] ?? $default
		);

		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(
			static fn (string $id): array => $id === self::CASE_TYPE
				? ['reviewerAccess' => ['records' => [['schemaKey' => 'assessment_schema_key', 'caseField' => 'caseRef']]]]
				: []
		);

		$grants = $this->createMock(ObjectReadGrants::class);
		$grants->method('available')->willReturn(true);
		$grants->method('reconcile')->willReturnCallback(
			function (string $schema, string $uuid, array $readers, string $group): array {
				$this->grantCalls[] = ['uuid' => $uuid, 'readers' => $readers];
				return ['granted' => count($readers), 'revoked' => 1, 'refused' => 0];
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $uid === 'coordinator' && $group === 'dossiq-coordinators');

		$access = new CaseReviewerAccess(
			settingsService: $settings,
			caseTypes: new CaseTypeStore($settings),
			resolver: $resolver,
			grants: $grants,
			groupManager: $groups,
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function (): ?IUser {
			if ($this->caller === '') {
				return null;
			}

			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->caller);
			return $user;
		});

		$this->listener = new CaseReassignGuardListener(
			settingsService: $settings,
			access: $access,
			userSession: $session,
			logger: new NullLogger(),
		);

		$this->register->seed('case', self::CASE_ID, ['caseType' => self::CASE_TYPE, 'assignee' => 'a.smit']);
		$this->register->seed('assessment', 'a-1', ['caseRef' => self::CASE_ID, '@self' => ['owner' => 'reviewer1']]);
	}//end setUp()

	public function testANonCoordinatorIsRefused(): void {
		$event = $this->updating(from: 'j.devries', to: 'a.smit');

		$this->listener->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('reassign-needs-supervisor', $event->getErrors()['error']);
		self::assertSame([], $this->grantCalls, 'a refused change moves no grant');
	}//end testANonCoordinatorIsRefused()

	public function testACoordinatorMayAndGrantsMove(): void {
		$this->caller = 'coordinator';
		$event = $this->updating(from: 'j.devries', to: 'a.smit');

		$this->listener->handle($event);
		self::assertFalse($event->isPropagationStopped());

		$this->listener->handle(new ObjectUpdatedEvent($this->entity(assignee: 'a.smit'), $this->entity(assignee: 'j.devries')));

		self::assertSame([['uuid' => 'a-1', 'readers' => ['a.smit']]], $this->grantCalls, 'the new assignee reads; the former one is no longer computed, so revoked');
	}//end testACoordinatorMayAndGrantsMove()

	public function testAnAdministratorMay(): void {
		$this->caller = 'admin';
		$event = $this->updating(from: 'j.devries', to: 'a.smit');

		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnAdministratorMay()

	public function testACaseWithoutAssessmentsIsUntouched(): void {
		unset($this->register->rows['assessment']);
		$event = $this->updating(from: 'j.devries', to: 'a.smit');

		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getErrors());
	}//end testACaseWithoutAssessmentsIsUntouched()

	public function testACaseTypeThatKeepsNothingPrivateIsUntouched(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(assignee: 'a.smit', caseType: 'another-type'),
			$this->entity(assignee: 'j.devries', caseType: 'another-type')
		);

		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testACaseTypeThatKeepsNothingPrivateIsUntouched()

	public function testAnUpdateThatKeepsTheAssigneeIsUntouched(): void {
		$event = $this->updating(from: 'j.devries', to: 'j.devries');

		$this->listener->handle($event);
		$this->listener->handle(new ObjectUpdatedEvent($this->entity(assignee: 'j.devries'), $this->entity(assignee: 'j.devries')));

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $this->grantCalls);
	}//end testAnUpdateThatKeepsTheAssigneeIsUntouched()

	public function testAWriteWithoutASignedInUserIsLetThrough(): void {
		$this->caller = '';
		$event = $this->updating(from: 'j.devries', to: 'a.smit');

		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAWriteWithoutASignedInUserIsLetThrough()

	public function testAnotherSchemaIsIgnored(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(assignee: 'a.smit', schema: 'task'),
			$this->entity(assignee: 'j.devries', schema: 'task')
		);

		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsIgnored()

	/**
	 * A pre-persist update moving the assignee.
	 *
	 * @param string $from The assignee before.
	 * @param string $to   The assignee after.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function updating(string $from, string $to): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent($this->entity(assignee: $to), $this->entity(assignee: $from));
	}//end updating()

	/**
	 * A case entity as OpenRegister serialises it.
	 *
	 * @param string $assignee The assignee.
	 * @param string $caseType The case type reference.
	 * @param string $schema   The schema slug.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $assignee, string $caseType = self::CASE_TYPE, string $schema = 'case'): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn([
			'caseType' => $caseType,
			'assignee' => $assignee,
			'@self' => ['id' => self::CASE_ID, 'schema' => $schema],
		]);

		return $entity;
	}//end entity()
}//end class
