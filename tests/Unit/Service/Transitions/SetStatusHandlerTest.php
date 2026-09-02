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
 * WHERE THE runAs TESTS WENT. This suite used to assert that the handler
 * wrapped its resolve-and-write in dossiq's FlowRunAsScope. That duty moved
 * into the engine: RegistryStepDispatcher executes every contributed node —
 * and therefore the handlers those nodes delegate to — inside
 * `ObjectService::runAs()` as the run's validated acting identity
 * (openregister#3332, proven by its RegistryStepDispatcherRunAsTest). The
 * local wrap is deleted, so asserting it here would re-encode the retired
 * requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\SetStatusHandler;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SetStatusHandlerTest extends TestCase {

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
		$objectService = new class($saved) {
			public function __construct(private ?array &$sink) {
			}

			public function saveObject(array $object, string $register, string $schema): ObjectEntity {
				$this->sink = $object;

				return $this->entity();
			}

			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): ObjectEntity {
				$this->sink = array_merge(($this->sink ?? []), $data);

				return $this->entity();
			}

			private function entity(): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid('case-entity-uuid');
				$entity->setObject(($this->sink ?? []));

				return $entity;
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
	 * A handler over these settings and this lookup.
	 *
	 * @param SettingsService $settings The settings double.
	 * @param StatusTypeLookup $lookup  The lookup double.
	 *
	 * @return SetStatusHandler The handler under test.
	 */
	private function handler(SettingsService $settings, StatusTypeLookup $lookup): SetStatusHandler {
		return new SetStatusHandler($settings, $lookup, new CaseFieldWriter(), new NullLogger());
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

	public function testTheActionIdIsSetStatus(): void {
		$saved = null;
		$handler = $this->handler($this->settings($saved), $this->lookup('x'));

		self::assertSame('setStatus', $handler->type());
	}//end testTheActionIdIsSetStatus()
}//end class
