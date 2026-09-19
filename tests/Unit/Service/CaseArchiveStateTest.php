<?php

/**
 * The archive state is the platform's marker, read and written in one place.
 *
 * The handler is a fake rather than a mock of a class this app does not own:
 * `ArchiveHandler` lives in OpenRegister and is resolved through the container
 * at call time, so a test that type-hinted it would only run on a checkout that
 * has OpenRegister beside it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Lifecycle\CaseArchiveState;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reading the marker, writing it, and refusing out loud when it cannot be written.
 *
 * @covers \OCA\Dossiq\Service\Lifecycle\CaseArchiveState
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class CaseArchiveStateTest extends TestCase {

	/**
	 * A settings service naming a register and a case schema.
	 *
	 * @param string $register The register slug to answer with.
	 *
	 * @return SettingsService&MockObject The double.
	 */
	private function settings(string $register = 'dossiq'): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', $register],
				['case_schema', '', 'case'],
			]
		);

		return $settings;
	}//end settings()

	/**
	 * A container answering with the given handler, or refusing to.
	 *
	 * @param object|null $handler The handler to answer with, null to throw.
	 *
	 * @return ContainerInterface&MockObject The double.
	 */
	private function container(?object $handler): ContainerInterface {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		if ($handler === null) {
			$container->method('get')->willThrowException(new RuntimeException('not found'));

			return $container;
		}

		$container->method('get')->willReturn($handler);

		return $container;
	}//end container()

	/**
	 * The service over one handler.
	 *
	 * @param object|null $handler The archive handler double.
	 * @param string $register The register slug.
	 *
	 * @return CaseArchiveState The service.
	 */
	private function state(?object $handler, string $register = 'dossiq'): CaseArchiveState {
		return new CaseArchiveState(
			settings: $this->settings(register: $register),
			container: $this->container(handler: $handler),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end state()

	/**
	 * A fake archive handler recording what it was asked.
	 *
	 * @return object The fake.
	 */
	private function handler(): object {
		return new class {
			/**
			 * Every call made on this fake.
			 *
			 * @var array<int, array<string, string>>
			 */
			public array $calls = [];

			/**
			 * Archive an object.
			 *
			 * @param string $id The object id.
			 * @param string $reason Why.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed> The marker.
			 */
			public function archive(string $id, string $reason, string $register, string $schema): array {
				$this->calls[] = ['verb' => 'archive', 'id' => $id, 'reason' => $reason, 'register' => $register, 'schema' => $schema];

				return ['uuid' => $id, 'archived' => ['by' => 'anna', 'at' => '2026-09-12T09:00:00+00:00', 'reason' => $reason]];
			}

			/**
			 * Restore an object.
			 *
			 * @param string $id The object id.
			 * @param string $reason Why.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed> The cleared marker.
			 */
			public function unarchive(string $id, string $reason, string $register, string $schema): array {
				$this->calls[] = ['verb' => 'unarchive', 'id' => $id, 'reason' => $reason, 'register' => $register, 'schema' => $schema];

				return ['uuid' => $id, 'archived' => null];
			}
		};
	}//end handler()

	/**
	 * The marker is read off `@self`, which is where the platform writes it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function testTheMarkerIsReadOffSelf(): void {
		$state = $this->state(handler: $this->handler());

		$archived = ['@self' => ['archived' => ['by' => 'anna', 'at' => '2026-09-12T09:00:00+00:00', 'reason' => 'Afgehandeld']]];

		$this->assertTrue(condition: $state->isArchived(case: $archived));
		$this->assertSame(expected: 'anna', actual: $state->markerOn(case: $archived)['by']);
	}//end testTheMarkerIsReadOffSelf()

	/**
	 * A case with no marker is not archived, and neither is one with a null marker.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function testACaseWithoutAMarkerIsNotArchived(): void {
		$state = $this->state(handler: $this->handler());

		$this->assertFalse(condition: $state->isArchived(case: ['@self' => ['archived' => null]]));
		$this->assertFalse(condition: $state->isArchived(case: ['@self' => []]));
		$this->assertFalse(condition: $state->isArchived(case: []));
		$this->assertSame(expected: [], actual: $state->markerOn(case: []));
	}//end testACaseWithoutAMarkerIsNotArchived()

	/**
	 * 🔴 `archiveStatus` IS NOT THE STATE. A case whose ZGW field says archived
	 * and which carries no marker is NOT archived, because no list looks there.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function testTheZgwFieldAloneDoesNotMakeACaseArchived(): void {
		$state = $this->state(handler: $this->handler());

		$this->assertFalse(condition: $state->isArchived(case: ['archiveStatus' => 'archived', '@self' => []]));
	}//end testTheZgwFieldAloneDoesNotMakeACaseArchived()

	/**
	 * The call carries the register and the schema the url would name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function testMarkingPassesTheRegisterAndSchema(): void {
		$handler = $this->handler();
		$state = $this->state(handler: $handler);

		$marker = $state->mark(caseId: 'case-1', reason: 'Afgehandeld');

		$this->assertSame(expected: 'archive', actual: $handler->calls[0]['verb']);
		$this->assertSame(expected: 'dossiq', actual: $handler->calls[0]['register']);
		$this->assertSame(expected: 'case', actual: $handler->calls[0]['schema']);
		$this->assertSame(expected: 'anna', actual: $marker['archived']['by']);
	}//end testMarkingPassesTheRegisterAndSchema()

	/**
	 * Clearing calls the platform's restore, with the reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function testClearingCallsTheRestore(): void {
		$handler = $this->handler();
		$state = $this->state(handler: $handler);

		$state->clear(caseId: 'case-1', reason: 'Te vroeg gearchiveerd');

		$this->assertSame(expected: 'unarchive', actual: $handler->calls[0]['verb']);
		$this->assertSame(expected: 'Te vroeg gearchiveerd', actual: $handler->calls[0]['reason']);
	}//end testClearingCallsTheRestore()

	/**
	 * An OpenRegister with no archive handler refuses out loud, never silently.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function testAnAbsentHandlerIsARefusalAndNotAShrug(): void {
		$state = $this->state(handler: null);

		try {
			$state->mark(caseId: 'case-1', reason: 'Afgehandeld');
			$this->fail(message: 'an unreachable archive must refuse rather than pretend');
		} catch (RefusedException $e) {
			$this->assertSame(expected: 'archive-state-unavailable', actual: $e->getRule());
			$this->assertSame(expected: RefusedException::STATUS_INDETERMINATE, actual: $e->getStatus());
		}
	}//end testAnAbsentHandlerIsARefusalAndNotAShrug()

	/**
	 * An unconfigured register refuses before it calls anything.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function testAnUnconfiguredRegisterRefusesBeforeCalling(): void {
		$handler = $this->handler();
		$state = $this->state(handler: $handler, register: '');

		try {
			$state->mark(caseId: 'case-1', reason: 'Afgehandeld');
			$this->fail(message: 'an unconfigured register must refuse');
		} catch (RefusedException $e) {
			$this->assertSame(expected: 'archive-state-unavailable', actual: $e->getRule());
		}

		$this->assertSame(expected: [], actual: $handler->calls);
	}//end testAnUnconfiguredRegisterRefusesBeforeCalling()

	/**
	 * The platform's own sentence is what the user reads (ADR-105).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function testThePlatformsRefusalIsCarriedThroughWordsAndAll(): void {
		$handler = new class {
			/**
			 * Refuse the way the platform refuses.
			 *
			 * @param string $id The object id.
			 * @param string $reason Why.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed> Never returns.
			 */
			public function archive(string $id, string $reason, string $register, string $schema): array {
				throw new RuntimeException('Schema "Case" does not declare x-openregister-archive, so its objects cannot be archived.');
			}
		};

		$state = $this->state(handler: $handler);

		try {
			$state->mark(caseId: 'case-1', reason: 'Afgehandeld');
			$this->fail(message: 'a refusal must reach the caller');
		} catch (RefusedException $e) {
			$this->assertStringContainsString(needle: 'x-openregister-archive', haystack: $e->getSentence());
			$this->assertSame(expected: 'archive-state-refused', actual: $e->getRule());
		}
	}//end testThePlatformsRefusalIsCarriedThroughWordsAndAll()
}//end class
