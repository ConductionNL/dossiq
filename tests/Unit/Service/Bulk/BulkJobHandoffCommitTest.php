<?php

/**
 * Unit tests for committing a bulk job and resolving its scope.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bulk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bulk;

use OCA\Dossiq\BulkAction\MoveCaseTypeVersionAction;
use OCA\Dossiq\BulkAction\SetCaseAttributeAction;
use OCA\Dossiq\BulkAction\TransitionCasesAction;
use OCA\Dossiq\Service\Bulk\BulkJobHandoff;
use OCA\Dossiq\Service\Bulk\CaseTypeVersionGuard;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The commit hand-off, register slug resolution and what the job answers as.
 *
 * @covers \OCA\Dossiq\Service\Bulk\BulkJobHandoff
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\People\CoordinatorRequirement
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 */
class BulkJobHandoffCommitTest extends TestCase {

	/**
	 * The job service class name.
	 *
	 * @var string
	 */
	private const JOB_SERVICE = 'OCA\OpenRegister\Service\BulkJob\BulkJobService';

	/**
	 * The job mapper class name.
	 *
	 * @var string
	 */
	private const JOB_MAPPER = 'OCA\OpenRegister\Db\BulkJobMapper';

	/**
	 * The register mapper class name.
	 *
	 * @var string
	 */
	private const REGISTER_MAPPER = 'OCA\OpenRegister\Db\RegisterMapper';

	/**
	 * A job service that records create and commit, answering a dictated job.
	 *
	 * @param mixed $answer What create and commit answer.
	 *
	 * @return object The service, with a public `$calls` log.
	 */
	private function jobService(mixed $answer): object {
		return new class($answer) {
			/**
			 * The calls received.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $answer The answer.
			 */
			public function __construct(private readonly mixed $answer) {
			}

			/**
			 * Record a create.
			 *
			 * @param string      $actionId      The action.
			 * @param array       $parameters    The parameters.
			 * @param array       $selection     The selection.
			 * @param string|null $justification The reason.
			 * @param string      $actorUid      The actor.
			 * @param int|null    $registerId    The register.
			 * @param int|null    $schemaId      The schema.
			 *
			 * @return mixed The answer.
			 */
			public function create(string $actionId, array $parameters, array $selection, ?string $justification, string $actorUid, ?int $registerId, ?int $schemaId): mixed {
				$this->calls[] = ['create' => compact('actionId', 'registerId', 'schemaId')];
				return $this->answer;
			}

			/**
			 * Record a commit.
			 *
			 * @param mixed       $job           The job entity.
			 * @param string|null $justification The reason.
			 *
			 * @return mixed The answer.
			 */
			public function commit(mixed $job, ?string $justification = null): mixed {
				$this->calls[] = ['commit' => ['job' => $job, 'justification' => $justification]];
				return $this->answer;
			}
		};
	}//end jobService()

	/**
	 * Build the hand-off.
	 *
	 * @param array<string, object>     $classes OpenRegister class name to instance.
	 * @param array<string, string>     $config  Dossiq setting to value.
	 * @param CaseTypeVersionGuard|null $guard   The version guard.
	 * @param LoggerInterface|null      $logger  The logger.
	 *
	 * @return BulkJobHandoff The hand-off.
	 */
	private function handoff(array $classes, array $config = ['register' => '3', 'case_schema' => '11'], ?CaseTypeVersionGuard $guard = null, ?LoggerInterface $logger = null): BulkJobHandoff {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static fn (string $class): ?object => ($classes[$class] ?? null)
		);

		return new BulkJobHandoff(
			settingsService: $settings,
			versionGuard: ($guard ?? $this->createMock(originalClassName: CaseTypeVersionGuard::class)),
			logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class)),
		);
	}//end handoff()

	/**
	 * A job mapper finding a stand-in entity for an id.
	 *
	 * @return object The mapper, with a public `$found` log.
	 */
	private function jobMapper(): object {
		return new class {
			/**
			 * The ids looked up.
			 *
			 * @var array<int, int>
			 */
			public array $found = [];

			/**
			 * Find a job.
			 *
			 * @param int $id The id.
			 *
			 * @return object The entity.
			 */
			public function find(int $id): object {
				$this->found[] = $id;
				$entity = new \stdClass();
				$entity->id = $id;
				return $entity;
			}
		};
	}//end jobMapper()

	/**
	 * Commit looks the job up by its integer id and hands it on with the justification.
	 *
	 * @return void
	 */
	public function testCommitHandsTheStoredJobOn(): void {
		$service = $this->jobService(answer: ['id' => 7, 'state' => 'running']);
		$mapper = $this->jobMapper();

		$answer = $this->handoff(classes: [self::JOB_SERVICE => $service, self::JOB_MAPPER => $mapper])
			->commit(job: ['id' => '7', 'state' => 'previewed'], justification: 'Herverdeling na vertrek');

		self::assertSame(expected: ['id' => 7, 'state' => 'running'], actual: $answer);
		self::assertSame(expected: [7], actual: $mapper->found);
		self::assertSame(expected: 7, actual: $service->calls[0]['commit']['job']->id);
		self::assertSame(expected: 'Herverdeling na vertrek', actual: $service->calls[0]['commit']['justification']);
	}//end testCommitHandsTheStoredJobOn()

	/**
	 * A job without an id is answered as it came, without touching OpenRegister.
	 *
	 * @return void
	 */
	public function testCommitOfAJobWithoutAnIdIsANoOp(): void {
		$job = ['state' => 'previewed', 'total' => 0];

		self::assertSame(expected: $job, actual: $this->handoff(classes: [])->commit(job: $job));
	}//end testCommitOfAJobWithoutAnIdIsANoOp()

	/**
	 * Without the job mapper, or without the job service, a commit is refused.
	 *
	 * @return void
	 */
	public function testCommitWithoutOpenRegisterIsRefused(): void {
		try {
			$this->handoff(classes: [self::JOB_SERVICE => $this->jobService(answer: [])])->commit(job: ['id' => 7]);
			self::fail(message: 'a commit without the job mapper was not refused');
		} catch (RuntimeException $e) {
			self::assertSame(expected: 'OpenRegister is not available, so a bulk act cannot be started', actual: $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available, so a bulk act cannot be handed over');
		$this->handoff(classes: [self::JOB_MAPPER => $this->jobMapper()])->commit(job: ['id' => 7]);
	}//end testCommitWithoutOpenRegisterIsRefused()

	/**
	 * A job entity is answered through jsonSerialize; anything else as an empty array.
	 *
	 * @return void
	 */
	public function testAJobEntityIsSerialised(): void {
		$entity = new class {
			/**
			 * Serialise.
			 *
			 * @return array<string, mixed> The job.
			 */
			public function jsonSerialize(): array {
				return ['id' => 9, 'state' => 'previewed'];
			}
		};

		$created = $this->handoff(classes: [self::JOB_SERVICE => $this->jobService(answer: $entity)])->create(
			actionId: TransitionCasesAction::ID,
			parameters: [],
			selection: ['ids' => ['c-1']],
			justification: null,
			actorUid: 'ilse',
		);
		self::assertSame(expected: ['id' => 9, 'state' => 'previewed'], actual: $created);

		$opaque = $this->handoff(classes: [self::JOB_SERVICE => $this->jobService(answer: new \stdClass())])->create(
			actionId: TransitionCasesAction::ID,
			parameters: [],
			selection: [],
			justification: null,
			actorUid: 'ilse',
		);
		self::assertSame(expected: [], actual: $opaque);
	}//end testAJobEntityIsSerialised()

	/**
	 * A register slug is resolved to its id, unscoped.
	 *
	 * @return void
	 */
	public function testARegisterSlugIsResolved(): void {
		$registers = new class {
			/**
			 * The lookups asked for.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $asked = [];

			/**
			 * Find a register.
			 *
			 * @param string $slug          The slug.
			 * @param bool   $_rbac         RBAC.
			 * @param bool   $_multitenancy Multitenancy.
			 *
			 * @return object The register.
			 */
			public function find(string $slug, bool $_rbac = true, bool $_multitenancy = true): object {
				$this->asked[] = [$slug, $_rbac, $_multitenancy];
				return new class {
					/**
					 * The id.
					 *
					 * @return int The id.
					 */
					public function getId(): int {
						return 42;
					}
				};
			}
		};
		$service = $this->jobService(answer: []);

		$this->handoff(
			classes: [self::JOB_SERVICE => $service, self::REGISTER_MAPPER => $registers],
			config: ['register' => ' dossiq ', 'case_schema' => '11'],
		)->create(actionId: TransitionCasesAction::ID, parameters: [], selection: [], justification: null, actorUid: 'ilse');

		self::assertSame(expected: [['dossiq', false, false]], actual: $registers->asked);
		self::assertSame(
			expected: ['actionId' => TransitionCasesAction::ID, 'registerId' => 42, 'schemaId' => 11],
			actual: $service->calls[0]['create']
		);
	}//end testARegisterSlugIsResolved()

	/**
	 * A slug that cannot be resolved is logged, and the act is refused as unconfigured.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRegisterRefusesTheAct(): void {
		$registers = new class {
			/**
			 * Fails.
			 *
			 * @param string $slug          The slug.
			 * @param bool   $_rbac         RBAC.
			 * @param bool   $_multitenancy Multitenancy.
			 *
			 * @return object Never.
			 */
			public function find(string $slug, bool $_rbac = true, bool $_multitenancy = true): object {
				throw new RuntimeException('no such register');
			}
		};
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: could not resolve the register for a bulk job',
			['slug' => 'dossiq', 'exception' => 'no such register']
		);
		$service = $this->jobService(answer: []);

		try {
			$this->handoff(
				classes: [self::JOB_SERVICE => $service, self::REGISTER_MAPPER => $registers],
				config: ['register' => 'dossiq', 'case_schema' => '11'],
				logger: $logger,
			)->create(actionId: TransitionCasesAction::ID, parameters: [], selection: [], justification: null, actorUid: 'ilse');
			self::fail(message: 'an unresolvable register was not refused');
		} catch (RuntimeException $e) {
			self::assertSame(expected: 'The dossiq register and case schema are not configured', actual: $e->getMessage());
		}

		self::assertSame(expected: [], actual: $service->calls);
	}//end testAnUnresolvableRegisterRefusesTheAct()

	/**
	 * An empty register, a missing register mapper or a zero schema refuse the act.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredScopeRefusesTheAct(): void {
		foreach (
			[
				['register' => '', 'case_schema' => '11'],
				['register' => 'dossiq', 'case_schema' => '11'],
				['register' => '3', 'case_schema' => 'case'],
			] as $config
		) {
			$service = $this->jobService(answer: []);
			try {
				$this->handoff(classes: [self::JOB_SERVICE => $service], config: $config)
					->create(actionId: TransitionCasesAction::ID, parameters: [], selection: [], justification: null, actorUid: 'ilse');
				self::fail(message: 'not refused: ' . json_encode($config));
			} catch (RuntimeException $e) {
				self::assertSame(expected: 'The dossiq register and case schema are not configured', actual: $e->getMessage());
			}

			self::assertSame(expected: [], actual: $service->calls);
		}
	}//end testAnUnconfiguredScopeRefusesTheAct()

	/**
	 * Moving versions is guarded with the ids as strings; an empty selection is not guarded.
	 *
	 * @return void
	 */
	public function testTheVersionMoveIsGuardedWithStringIds(): void {
		$guard = $this->createMock(originalClassName: CaseTypeVersionGuard::class);
		$guard->expects($this->once())->method('assertOneVersion')->with(['12', 'c-2']);

		$handoff = $this->handoff(classes: [self::JOB_SERVICE => $this->jobService(answer: [])], guard: $guard);
		$handoff->create(actionId: MoveCaseTypeVersionAction::ID, parameters: [], selection: ['ids' => [12, 'c-2']], justification: null, actorUid: 'ilse');
		$handoff->create(actionId: SetCaseAttributeAction::ID, parameters: [], selection: ['ids' => []], justification: null, actorUid: 'ilse');
		$handoff->create(actionId: SetCaseAttributeAction::ID, parameters: [], selection: ['filter' => ['status' => 'open']], justification: null, actorUid: 'ilse');
	}//end testTheVersionMoveIsGuardedWithStringIds()
}//end class
