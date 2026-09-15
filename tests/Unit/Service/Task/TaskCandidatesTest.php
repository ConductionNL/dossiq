<?php

/**
 * A task reaches a team before it reaches a person.
 *
 * The declaration is dossiq's, on the case type; the claim is the engine's.
 * These tests pin the seam between the two: that a declared candidate list
 * reaches the engine's own candidate columns rather than being flattened onto
 * an assignee, and that whether a claim affordance may be rendered is ASKED of
 * the engine rather than assumed either way.
 *
 * 🔴 WHY THE CAPABILITY IS ASKED. The proposal for this change was written
 * when the engine had no claim verb at all, and said dossiq should declare the
 * candidate group and state plainly that nothing honoured it. The verb landed
 * in openregister meanwhile. Hard-coding either answer makes dossiq wrong on
 * half the instances in the fleet, and the wrong half is silent: a claim
 * button that does nothing, or a stated gap that is not a gap.
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
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Task\EngineTaskGateway
 */
class TaskCandidatesTest extends TestCase {

	/**
	 * The declared candidates reach the engine's own candidate columns.
	 *
	 * @return void
	 */
	public function testDeclaredCandidatesReachTheEngine(): void {
		$payload = $this->gateway(service: null)->toEnginePayload(
			task: [
				'title' => 'Hoor de belanghebbende',
				'candidateGroups' => ['Juridische Zaken'],
				'candidateUsers' => ['hbakker', 'jdejong'],
			],
			caseId: 'case-9'
		);

		$this->assertSame(['Juridische Zaken'], $payload['candidateGroups']);
		$this->assertSame(['hbakker', 'jdejong'], $payload['candidateUsers']);
		// 🔑 NOT FLATTENED ONTO AN ASSIGNEE. A task with candidates and no
		// assignee is a task waiting to be claimed; writing the first
		// candidate into `assignee` would hand it to one person and call that
		// a queue.
		$this->assertArrayNotHasKey('assignee', $payload);
	}

	/**
	 * A declared team wins over the case's team.
	 *
	 * They are different questions. The case's team is where work falls back
	 * to; a declared candidate group is who THIS task was written for.
	 *
	 * @return void
	 */
	public function testADeclaredTeamWinsOverTheCaseTeam(): void {
		$payload = $this->gateway(service: null)->toEnginePayload(
			task: ['title' => 'Hoorzitting', 'assigneeGroup' => 'team-intake', 'candidateGroups' => ['Juridische Zaken']],
			caseId: 'case-9'
		);

		$this->assertSame(['Juridische Zaken'], $payload['candidateGroups']);
	}

	/**
	 * The form and the effects travel in the engine's own metadata room.
	 *
	 * @return void
	 */
	public function testTheDeclarationTravelsInMetadata(): void {
		$payload = $this->gateway(service: null)->toEnginePayload(
			task: [
				'title' => 'Hoorzitting',
				'metadata' => [
					'form' => ['kind' => 'fields', 'schema' => 'case', 'fields' => [['field' => 'verslag', 'required' => true]]],
					'dossiq' => ['effects' => [['type' => 'sendEmail']]],
				],
			],
			caseId: 'case-9'
		);

		$this->assertSame('fields', $payload['metadata']['form']['kind']);
		$this->assertSame('sendEmail', $payload['metadata']['dossiq']['effects'][0]['type']);
	}

	/**
	 * An engine that answers a claim act is reported as answering one.
	 *
	 * @return void
	 */
	public function testAnEngineWithAClaimActIsReportedAsHavingOne(): void {
		$engine = new class {
			/**
			 * @param string      $uuid  The task.
			 * @param string|null $actor Who claims it.
			 *
			 * @return array<string, string> The claimed task.
			 */
			public function claim(string $uuid, ?string $actor): array {
				return ['uuid' => $uuid, 'assignee' => (string)$actor];
			}
		};

		$gateway = $this->gateway(service: $engine);

		$this->assertTrue($gateway->supportsClaim());
		$this->assertTrue($gateway->claim(taskId: 'task-1', actor: 'hbakker'));
	}

	/**
	 * An engine without one is reported as not having one, and refuses by name.
	 *
	 * dossiq does NOT fall back to assigning the task to the caller. A surface
	 * that presented a claim and silently assigned would be worse than no
	 * surface: the handler would believe they took it from a pool that never
	 * existed.
	 *
	 * @return void
	 */
	public function testAnEngineWithoutAClaimActSaysSoRatherThanAssigning(): void {
		$engine = new class {
			/**
			 * @param string      $uuid     The task.
			 * @param string      $assignee Who receives it.
			 * @param string|null $actor    Who assigns.
			 *
			 * @return array<string, string> The task.
			 */
			public function assign(string $uuid, string $assignee, ?string $actor): array {
				return ['uuid' => $uuid];
			}
		};

		$gateway = $this->gateway(service: $engine);

		$this->assertFalse($gateway->supportsClaim());
		$this->assertFalse($gateway->claim(taskId: 'task-1', actor: 'hbakker'));
		$this->assertStringContainsString('claim act', $gateway->lastError());
	}

	/**
	 * A refusal from the engine keeps the engine's own reason.
	 *
	 * @return void
	 */
	public function testARefusedClaimKeepsTheEnginesReason(): void {
		$engine = new class {
			/**
			 * @param string      $uuid  The task.
			 * @param string|null $actor Who claims it.
			 *
			 * @return array<string, string> Never returned.
			 */
			public function claim(string $uuid, ?string $actor): array {
				throw new \RuntimeException('Verb "claim" denied: not in the candidate pool');
			}
		};

		$gateway = $this->gateway(service: $engine);

		$this->assertFalse($gateway->claim(taskId: 'task-1', actor: 'outsider'));
		$this->assertStringContainsString('candidate pool', $gateway->lastError());
	}

	/**
	 * A gateway whose service resolution is overridden, so the paths needing a
	 * live engine are reachable where OpenRegister is not autoloadable.
	 *
	 * @param object|null $service The engine double, or null.
	 *
	 * @return EngineTaskGateway The gateway.
	 */
	private function gateway(?object $service): EngineTaskGateway {
		$settings = $this->getMockBuilder(SettingsService::class)->disableOriginalConstructor()->getMock();
		$settings->method('getConfigValue')->willReturn('1');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);

		return new class ($settings, $container, new NullLogger(), $service) extends EngineTaskGateway {
			/**
			 * @param SettingsService    $settings  The settings double.
			 * @param ContainerInterface $container The container double.
			 * @param NullLogger         $logger    The logger.
			 * @param object|null        $service   The engine double.
			 */
			public function __construct(
				SettingsService $settings,
				ContainerInterface $container,
				NullLogger $logger,
				private readonly ?object $service,
			) {
				parent::__construct($settings, $container, $logger);
			}

			/**
			 * @return object|null The injected double.
			 */
			protected function resolveService(): ?object {
				return $this->service;
			}
		};
	}
}//end class
