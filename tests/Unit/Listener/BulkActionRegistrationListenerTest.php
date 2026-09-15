<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Listener
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\BulkAction\LifecycleCasesAction;
use OCA\Dossiq\BulkAction\ReassignCasesAction;
use OCA\Dossiq\BulkAction\SetCaseAttributeAction;
use OCA\Dossiq\BulkAction\TransitionCasesAction;
use OCA\Dossiq\Listener\BulkActionRegistrationListener;
use OCA\Dossiq\Service\CaseLifecycleService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Support\CaseAssigneeWriter;
use OCA\OpenRegister\Event\BulkActionRegistrationEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Proves dossiq's four bulk actions actually reach the registry.
 *
 * An action class that exists but is never registered is invisible to every
 * caller, and looks identical to one that works right up until somebody selects
 * four hundred cases and finds nothing on offer.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
class BulkActionRegistrationListenerTest extends TestCase {

	/**
	 * A container answering a real instance of each of the four actions.
	 *
	 * @param array<int, string> $failing Classes the container refuses to build.
	 *
	 * @return ContainerInterface|\PHPUnit\Framework\MockObject\MockObject The container.
	 */
	private function container(array $failing = []) {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class) use ($l10n, $failing): object {
				if (in_array($class, $failing, true) === true) {
					throw new RuntimeException('cannot build ' . $class);
				}

				return match ($class) {
					TransitionCasesAction::class => new TransitionCasesAction(
						engine: $this->createMock(originalClassName: StatusTransitionService::class),
						l10n: $l10n,
					),
					LifecycleCasesAction::class => new LifecycleCasesAction(
						lifecycle: $this->createMock(originalClassName: CaseLifecycleService::class),
						l10n: $l10n,
					),
					ReassignCasesAction::class => new ReassignCasesAction(
						writer: $this->createMock(originalClassName: CaseAssigneeWriter::class),
						l10n: $l10n,
					),
					default => new SetCaseAttributeAction(
						settingsService: $this->createMock(originalClassName: SettingsService::class),
						l10n: $l10n,
					),
				};
			}
		);

		return $container;
	}//end container()

	/**
	 * All four actions are registered, under their declared ids.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testAllFourCaseActionsAreRegistered(): void {
		$event = new BulkActionRegistrationEvent();

		(new BulkActionRegistrationListener(
			container: $this->container(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		))->handle($event);

		$ids = array_map(static fn (object $action): string => $action->getId(), $event->getActions());

		$this->assertSame(
			expected: [
				TransitionCasesAction::ID,
				LifecycleCasesAction::ID,
				ReassignCasesAction::ID,
				SetCaseAttributeAction::ID,
			],
			actual: $ids
		);
	}//end testAllFourCaseActionsAreRegistered()

	/**
	 * One action that cannot be built does not take the other three with it.
	 *
	 * A registry that answers nothing looks exactly like an instance where
	 * dossiq is not installed, so the fail-soft behaviour is the difference
	 * between one missing act and four.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testOneUnbuildableActionDoesNotTakeTheOthersWithIt(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$event = new BulkActionRegistrationEvent();

		(new BulkActionRegistrationListener(
			container: $this->container(failing: [ReassignCasesAction::class]),
			logger: $logger,
		))->handle($event);

		$ids = array_map(static fn (object $action): string => $action->getId(), $event->getActions());

		$this->assertSame(
			expected: [TransitionCasesAction::ID, LifecycleCasesAction::ID, SetCaseAttributeAction::ID],
			actual: $ids
		);
	}//end testOneUnbuildableActionDoesNotTakeTheOthersWithIt()

	/**
	 * Only the redistribution and the lifecycle gesture demand a written
	 * reason, and only the attribute write carries the homogeneity guard.
	 *
	 * The catalogue endpoint renders both facts, so a dialog that asks for a
	 * reason does so because the action said to, not because a component
	 * remembered to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testTheCatalogueSaysWhichActsNeedAReasonAndWhichIsGuarded(): void {
		$event = new BulkActionRegistrationEvent();

		(new BulkActionRegistrationListener(
			container: $this->container(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		))->handle($event);

		$declared = [];
		foreach ($event->getActions() as $action) {
			$declared[$action->getId()] = [
				'reason' => $action->requiresJustification(),
				'guards' => $action->getGuards(),
			];
		}

		$this->assertTrue(condition: $declared[ReassignCasesAction::ID]['reason']);
		$this->assertTrue(condition: $declared[LifecycleCasesAction::ID]['reason']);
		$this->assertFalse(condition: $declared[TransitionCasesAction::ID]['reason']);
		$this->assertFalse(condition: $declared[SetCaseAttributeAction::ID]['reason']);

		$this->assertSame(expected: ['homogeneity'], actual: $declared[SetCaseAttributeAction::ID]['guards']);
		$this->assertSame(expected: [], actual: $declared[ReassignCasesAction::ID]['guards']);
	}//end testTheCatalogueSaysWhichActsNeedAReasonAndWhichIsGuarded()
}//end class
