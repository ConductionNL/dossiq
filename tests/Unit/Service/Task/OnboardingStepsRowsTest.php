<?php

/**
 * How the onboarding steps of a tenant are read back from engine rows:
 * array rows, entity rows, dates and the rows that are dropped.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineInboxQuery;
use OCA\Dossiq\Service\Task\OnboardingSteps;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Task\OnboardingSteps
 * @uses \OCA\Dossiq\Service\Task\EngineInboxQuery
 */
class OnboardingStepsRowsTest extends TestCase {

	/**
	 * The criteria the inbox was last asked with.
	 *
	 * @var object|null
	 */
	private ?object $seen = null;

	/**
	 * Onboarding steps reading from an inbox that answers the given value.
	 *
	 * @param mixed $answer The inbox answer, or a Throwable to raise.
	 *
	 * @return OnboardingSteps The steps service.
	 */
	private function steps(mixed $answer): OnboardingSteps {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$logger    = new NullLogger();
		$seen      = &$this->seen;

		$inbox = new class ($answer, $seen) {

			/**
			 * @param mixed       $answer What to answer.
			 * @param object|null $seen   Where to record the criteria.
			 */
			public function __construct(private readonly mixed $answer, public ?object &$seen) {
			}

			/**
			 * @param object  $criteria The criteria.
			 * @param integer $limit    The page size.
			 * @param integer $offset   The offset.
			 *
			 * @return mixed The answer.
			 */
			public function inbox(object $criteria, int $limit, int $offset): mixed {
				$this->seen = $criteria;
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};

		$query = new class ($settings, $container, $logger, $inbox) extends EngineInboxQuery {

			/**
			 * @param SettingsService    $settings  The settings.
			 * @param ContainerInterface $container The container.
			 * @param NullLogger         $logger    The logger.
			 * @param object             $inbox     The inbox double.
			 */
			public function __construct(
				SettingsService $settings,
				ContainerInterface $container,
				NullLogger $logger,
				private readonly object $inbox,
			) {
				parent::__construct($settings, $container, $logger);
			}

			/**
			 * @return object|null The inbox double.
			 */
			protected function resolveInbox(): ?object {
				return $this->inbox;
			}
		};

		return new OnboardingSteps(settings: $settings, container: $container, logger: $logger, inboxQuery: $query);
	}//end steps()

	/**
	 * Array and entity rows are both read; rows without a step key are dropped.
	 *
	 * @return void
	 */
	public function testRowsAreReadByStep(): void {
		$entity = new class {

			/**
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return 'task-2';
			}

			/**
			 * @return string The step key.
			 */
			public function getKey(): string {
				return 'contract';
			}

			/**
			 * @return string The engine state.
			 */
			public function getState(): string {
				return 'terminated';
			}

			/**
			 * @return string The outcome.
			 */
			public function getOutcome(): string {
				return OnboardingSteps::OUTCOME_SKIPPED;
			}

			/**
			 * @return array The completer, not a scalar.
			 */
			public function getCompletedBy(): array {
				return ['uid' => 'anna'];
			}

			/**
			 * @return DateTimeImmutable When it closed.
			 */
			public function getCompletedAt(): DateTimeImmutable {
				return new DateTimeImmutable('2026-09-10T08:00:00+00:00');
			}
		};

		$steps = $this->steps(
			[
				'results' => [
					['uuid' => 'task-0', 'key' => '', 'state' => 'available'],
					['uuid' => 'task-1', 'key' => 'branding', 'state' => 'active', 'completedBy' => 'piet', 'completedAt' => ''],
					$entity,
				],
			]
		)->forTenant(tenantId: 'org-1', actor: 'beheerder');

		$this->assertSame(
			expected: [
				'branding' => ['id' => 'task-1', 'step' => 'branding', 'status' => 'in_progress', 'completedBy' => 'piet', 'completedAt' => ''],
				'contract' => ['id' => 'task-2', 'step' => 'contract', 'status' => 'skipped', 'completedBy' => '', 'completedAt' => '2026-09-10T08:00:00+00:00'],
			],
			actual: $steps
		);
		$this->assertInstanceOf(TaskInboxCriteria::class, $this->seen);
		$this->assertSame(expected: 'org-1', actual: $this->seen->objectUuid);
		$this->assertSame(expected: OnboardingSteps::KIND, actual: $this->seen->kind);
	}//end testRowsAreReadByStep()

	/**
	 * A blank tenant is never read; a failed read is empty and says why.
	 *
	 * @return void
	 */
	public function testBlankTenantAndFailedRead(): void {
		$this->assertSame(expected: [], actual: $this->steps(['results' => []])->forTenant(tenantId: ' ', actor: 'a'));
		$this->assertNull(actual: $this->seen);

		$failing = $this->steps(new RuntimeException('engine down'));
		$this->assertSame(expected: [], actual: $failing->forTenant(tenantId: 'org-1', actor: 'a'));
		$this->assertSame(expected: 'engine down', actual: $failing->lastError());
	}//end testBlankTenantAndFailedRead()

	/**
	 * An engine state outside the map is reported as it is.
	 *
	 * @return void
	 */
	public function testUnknownStatePassesThrough(): void {
		$this->assertSame(expected: 'suspended', actual: OnboardingSteps::statusFor(state: 'suspended', outcome: ''));
		$this->assertSame(expected: 'terminated', actual: OnboardingSteps::statusFor(state: 'terminated', outcome: 'cancelled'));
		$this->assertSame(expected: 'pending', actual: OnboardingSteps::statusFor(state: 'available', outcome: ''));
	}//end testUnknownStatePassesThrough()
}//end class
