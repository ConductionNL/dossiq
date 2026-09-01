<?php

/**
 * Unit tests for SetStatusHandler — moving a case to a NAMED status.
 *
 * The behaviour worth protecting is the refusal. A status that cannot be
 * resolved must FAIL the step, not skip it: a run that completes while the case
 * never moved is a case frozen at "received" for the applicant and a flow that
 * reports success for the handler. Every "did not move" path below asserts a
 * named error rather than a bare false.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\FlowRunAsScope;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\SetStatusHandler;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SetStatusHandlerTest extends TestCase {

	/**
	 * The uids the object service's runAs seam was asked to act as.
	 *
	 * @var string[]
	 */
	private array $actedAs = [];

	protected function setUp(): void {
		$this->actedAs = [];
	}//end setUp()

	/**
	 * A lookup that resolves one name to one id.
	 *
	 * @param string $resolvesTo The id to return, or '' for "no such status".
	 *
	 * @return StatusTypeLookup The lookup double.
	 */
	private function lookup(string $resolvesTo): StatusTypeLookup {
		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('idForName')->willReturn($resolvesTo);

		return $lookup;
	}//end lookup()

	/**
	 * Settings wired to a recording object service.
	 *
	 * The fake's saveObject returns an ObjectEntity BECAUSE THE REAL ONE DOES.
	 * A fake returning the caller's array encodes the caller's assumption and
	 * can never fail it — which is exactly how the ask node's "could not
	 * identify the task it created" shipped green.
	 *
	 * @param array|null $saved Receives the saved case.
	 *
	 * @return SettingsService The settings double.
	 */
	private function settings(?array &$saved): SettingsService {
		$objectService = new class($saved, $this->actedAs) {
			public function __construct(private ?array &$sink, private array &$actedAs) {
			}

			public function saveObject(array $object, string $register, string $schema): ObjectEntity {
				$this->sink = $object;

				$entity = new ObjectEntity();
				$entity->setUuid('case-entity-uuid');
				$entity->setObject($object);

				return $entity;
			}

			public function runAs(IUser $user, callable $operation): mixed {
				$this->actedAs[] = $user->getUID();

				return $operation();
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : 'case')
		);

		return $settings;
	}//end settings()

	/**
	 * A runAs scope over these settings, resolving the one named user.
	 *
	 * @param SettingsService $settings The settings the scope resolves through.
	 * @param string          $knownUid The only uid that resolves to an account.
	 *
	 * @return FlowRunAsScope The scope.
	 */
	private function scope(SettingsService $settings, string $knownUid = 'admin'): FlowRunAsScope {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($knownUid);
		$user->method('isEnabled')->willReturn(true);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => ($uid === $knownUid) ? $user : null
		);

		return new FlowRunAsScope($settings, $users);
	}//end scope()

	/**
	 * A handler over these settings and this lookup.
	 *
	 * @param SettingsService $settings The settings double.
	 * @param StatusTypeLookup $lookup  The lookup double.
	 *
	 * @return SetStatusHandler The handler under test.
	 */
	private function handler(SettingsService $settings, StatusTypeLookup $lookup): SetStatusHandler {
		return new SetStatusHandler($settings, $lookup, $this->scope($settings), new NullLogger());
	}//end handler()

	public function testTheCaseIsMovedToTheResolvedStatus(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->lookup('status-uuid-3'));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'In behandeling'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: []
		);

		self::assertTrue($result->succeeded);
		self::assertSame('status-uuid-3', $saved['status']);
		self::assertSame('In behandeling', $result->data['statusName']);
	}//end testTheCaseIsMovedToTheResolvedStatus()

	/**
	 * 🔴 An unresolvable status FAILS rather than silently leaving the case.
	 */
	public function testAnUnknownStatusFailsTheStep(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->lookup(''));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'Verzonnen'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('status_not_found_on_case_type', $result->error);
		self::assertNull($saved, 'The case must not be written when the status did not resolve.');
	}//end testAnUnknownStatusFailsTheStep()

	public function testAStepWithNoStatusNamedFails(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->lookup('x'));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('set_status_missing_status', $result->error);
	}//end testAStepWithNoStatusNamedFails()

	/**
	 * A case with no type has nothing to resolve the name WITHIN.
	 */
	public function testACaseWithoutACaseTypeFails(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->lookup('x'));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'Ontvangen'],
			case: ['id' => 'case-1'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('case_has_no_case_type', $result->error);
	}//end testACaseWithoutACaseTypeFails()

	public function testFailsWhenStorageIsUnavailable(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$handler = $this->handler($settings, $this->lookup('status-1'));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'Ontvangen'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('storage_unavailable', $result->error);
	}//end testFailsWhenStorageIsUnavailable()

	public function testFailsWhenTheCaseSchemaIsNotConfigured(): void {
		$objectService = new class {
			public function saveObject(array $object, string $register, string $schema): array {
				return $object;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturn('');

		$handler = $this->handler($settings, $this->lookup('status-1'));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'Ontvangen'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('case_schema_not_configured', $result->error);
	}//end testFailsWhenTheCaseSchemaIsNotConfigured()

	/**
	 * 🔴 A CONTEXT THAT NAMES AN ACTING IDENTITY IS OBEYED.
	 *
	 * Under FlowRunWorker the ambient session carries nobody, so a bare
	 * saveObject() is refused as 'Anonymous' — measured live, with the run
	 * context carrying `runAs: admin` the whole time. The write (and the
	 * status lookup that decides it) must go through the object service's
	 * runAs seam as that user. Remove the wrap in the handler and this test
	 * goes red: the seam is never asked, and $actedAs stays empty.
	 */
	public function testTheWriteRunsAsTheRunsActingIdentity(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->lookup('status-uuid-3'));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'In behandeling'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: ['runAs' => 'admin']
		);

		self::assertTrue($result->succeeded);
		self::assertSame(
			['admin'],
			$this->actedAs,
			'The resolve-and-write must run through the object service\'s runAs seam, as the run\'s acting identity.'
		);
		self::assertSame('status-uuid-3', $saved['status']);
	}//end testTheWriteRunsAsTheRunsActingIdentity()

	/**
	 * An acting identity that resolves to nobody fails the step — it must not
	 * quietly fall back to the ambient session, which under a worker is the
	 * exact anonymous write this seam exists to prevent.
	 */
	public function testAnUnresolvableActingIdentityFailsTheStep(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->lookup('status-uuid-3'));

		$result = $handler->handle(
			actionConfig: ['type' => 'setStatus', 'status' => 'In behandeling'],
			case: ['id' => 'case-1', 'caseType' => 'ct-1'],
			transitionContext: ['runAs' => 'nobody-by-this-name']
		);

		self::assertFalse($result->succeeded);
		self::assertSame('set_status_failed', $result->error);
		self::assertNull($saved, 'Nothing may be written under an identity that does not resolve.');
	}//end testAnUnresolvableActingIdentityFailsTheStep()

	public function testTheActionIdIsSetStatus(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->lookup('x'));

		self::assertSame('setStatus', $handler->type());
	}//end testTheActionIdIsSetStatus()
}//end class
