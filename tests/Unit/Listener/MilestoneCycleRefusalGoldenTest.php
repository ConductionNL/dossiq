<?php

/**
 * Golden record of the milestone dependency cycle refusal.
 *
 * Records, per world, whether the save is stopped, the errors it carries and
 * every log line, so a refactor cannot change which milestone definitions are
 * refused or how the refusal reads.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\MilestoneDependencyCycleListener;
use OCA\Dossiq\Service\Milestone\MilestoneRepository;
use OCA\Dossiq\Service\Milestone\MilestoneSchedule;
use OCA\Dossiq\Service\Settings\SchemaScopeResolver;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden test for MilestoneDependencyCycleListener::refuseCycle.
 *
 * @covers \OCA\Dossiq\Listener\MilestoneDependencyCycleListener
 */
class MilestoneCycleRefusalGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Log lines of the current world.
	 *
	 * @var list<string>
	 */
	private array $logLines = [];

	/**
	 * Every world the refusal distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testRefusalAcrossWorlds(): void {
		$type = 'type-1';
		$stored = [
			['caseType' => $type, 'identifier' => 'intake', 'dependsOn' => []],
			['caseType' => $type, 'identifier' => 'review', 'dependsOn' => ['intake']],
		];
		$worlds = [
			'no identifier' => [['caseType' => $type, 'dependsOn' => ['x']], $stored],
			'blank identifier' => [['caseType' => $type, 'identifier' => '  ', 'dependsOn' => ['x']], $stored],
			'no case type' => [['identifier' => 'loop', 'dependsOn' => ['loop']], $stored],
			'case type unreadable' => [['caseType' => $type, 'identifier' => 'loop', 'dependsOn' => ['loop']], 'throw'],
			'waits for itself' => [['caseType' => $type, 'identifier' => 'loop', 'dependsOn' => ['loop']], []],
			'waits for something else' => [['caseType' => $type, 'identifier' => 'decide', 'dependsOn' => ['review']], $stored],
			'a loop through the stored definitions' => [['caseType' => $type, 'identifier' => 'intake', 'dependsOn' => ['review']], $stored],
			'an update that opens the loop again' => [['caseType' => $type, 'identifier' => 'review', 'dependsOn' => []], [['caseType' => $type, 'identifier' => 'intake', 'dependsOn' => ['review']], ['caseType' => $type, 'identifier' => ' review ', 'dependsOn' => ['intake']]]],
		];

		$record = [];
		foreach ($worlds as $name => [$payload, $definitions]) {
			$this->logLines = [];
			$entity = new ObjectEntity();
			$entity->setObject($payload);
			$event = new ObjectCreatingEvent($entity);
			$refuse = new ReflectionMethod(MilestoneDependencyCycleListener::class, 'refuseCycle');
			$refuse->setAccessible(true);
			$refuse->invoke($this->listenerWith(definitions: $definitions), $event, $payload);
			$record[$name] = [
				'stopped' => $event->isPropagationStopped(),
				'errors' => $event->getErrors(),
				'log' => $this->logLines,
			];
		}

		$this->assertMatchesGolden(name: 'milestone-cycle-refusal', value: $record);
	}//end testRefusalAcrossWorlds()

	/**
	 * A listener whose repository answers with $definitions.
	 *
	 * @param array<mixed>|string $definitions The stored definitions, or 'throw'.
	 *
	 * @return MilestoneDependencyCycleListener
	 */
	private function listenerWith(array|string $definitions): MilestoneDependencyCycleListener {
		$repository = $this->getMockBuilder(MilestoneRepository::class)
			->disableOriginalConstructor()
			->onlyMethods(['findDefinitions'])
			->getMock();
		if ($definitions === 'throw') {
			$repository->method('findDefinitions')->willThrowException(new RuntimeException('register hiccup'));
		} else {
			$repository->method('findDefinitions')->willReturn($definitions);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'notice', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logLines[] = $level . ': ' . $message . ' ' . json_encode($context);
				}
			);
		}

		return new MilestoneDependencyCycleListener(
			$this->getMockBuilder(SchemaScopeResolver::class)->disableOriginalConstructor()->getMock(),
			$repository,
			new MilestoneSchedule(workingDays: new WorkingDayCalculator()),
			$l10n,
			$logger,
		);
	}//end listenerWith()
}//end class
