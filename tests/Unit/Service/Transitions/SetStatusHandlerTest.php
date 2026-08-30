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

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\SetStatusHandler;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
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
	 * @param array|null $saved Receives the saved case.
	 *
	 * @return SettingsService The settings double.
	 */
	private function settings(?array &$saved): SettingsService {
		$objectService = new class($saved) {
			public function __construct(private ?array &$sink) {
			}

			public function saveObject(array $object, string $register, string $schema): array {
				$this->sink = $object;

				return $object;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : 'case')
		);

		return $settings;
	}//end settings()

	public function testTheCaseIsMovedToTheResolvedStatus(): void {
		$saved = null;
		$handler = new SetStatusHandler(
			$this->settings($saved),
			$this->lookup('status-uuid-3'),
			new NullLogger()
		);

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
		$handler = new SetStatusHandler(
			$this->settings($saved),
			$this->lookup(''),
			new NullLogger()
		);

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
		$handler = new SetStatusHandler($this->settings($saved), $this->lookup('x'), new NullLogger());

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
		$handler = new SetStatusHandler($this->settings($saved), $this->lookup('x'), new NullLogger());

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

		$handler = new SetStatusHandler($settings, $this->lookup('status-1'), new NullLogger());

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

		$handler = new SetStatusHandler($settings, $this->lookup('status-1'), new NullLogger());

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
		$handler = new SetStatusHandler($this->settings($saved), $this->lookup('x'), new NullLogger());

		self::assertSame('setStatus', $handler->type());
	}//end testTheActionIdIsSetStatus()
}//end class
