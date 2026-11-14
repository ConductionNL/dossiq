<?php

/**
 * Dossiq TaskCompletionEffectsListener test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\TaskCompletionEffectsListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\TaskAttachmentService;
use OCA\Dossiq\Service\Task\TaskEffects;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A completed task runs its declared effects on its case and publishes its
 * files; anything else, or an uncommitted completion, does nothing.
 *
 * @covers \OCA\Dossiq\Listener\TaskCompletionEffectsListener
 */
class TaskCompletionEffectsListenerTest extends TestCase {

	/**
	 * The effects service.
	 *
	 * @var TaskEffects&MockObject
	 */
	private TaskEffects $effects;

	/**
	 * The attachment service.
	 *
	 * @var TaskAttachmentService&MockObject
	 */
	private TaskAttachmentService $attachments;

	/**
	 * The settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The object service double; answers find().
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->effects = $this->createMock(TaskEffects::class);
		$this->attachments = $this->createMock(TaskAttachmentService::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objects = new class {
			/**
			 * The case find() answers, or null to throw.
			 *
			 * @var array|null
			 */
			public ?array $case = ['id' => 'case-1', 'title' => 'Case'];

			/**
			 * The ids asked for.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $asked = [];

			/**
			 * Find one object.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array
			 */
			public function find(string $id, mixed $register, mixed $schema): array {
				$this->asked[] = ['id' => $id, 'register' => $register, 'schema' => $schema];
				if ($this->case === null) {
					throw new RuntimeException('store down');
				}

				return $this->case;
			}
		};

		$this->settings->method('getObjectService')->willReturn($this->objects);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'reg', 'case_schema' => 'case'][$key] ?? ''
		);
	}//end setUp()

	/**
	 * A completed task with effects runs them on its case, with the context,
	 * and then publishes its files.
	 *
	 * @return void
	 */
	public function testACompletedTaskRunsItsEffectsAndPublishesItsFiles(): void {
		$declared = [['type' => 'setStatus', 'status' => 'st-2']];
		$this->effects->expects($this->once())->method('declaredOn')
			->with($this->callback(static fn (array $task): bool => $task['id'] === 'task-1' && $task['case'] === 'case-1' && $task['metadata'] === ['effects' => 1]))
			->willReturn($declared);
		$this->effects->expects($this->once())->method('run')->with(
			$declared,
			['id' => 'case-1', 'title' => 'Case'],
			['caseId' => 'case-1', 'taskId' => 'task-1', 'userId' => 'alice', 'transitionLabel' => 'Check the file']
		)->willReturn([]);
		$this->attachments->expects($this->once())->method('publish')->with('case-1', 'task-1')->willReturn(2);

		$this->listener()->handle($this->event($this->task()));

		$this->assertSame([['id' => 'case-1', 'register' => 'reg', 'schema' => 'case']], $this->objects->asked);
	}//end testACompletedTaskRunsItsEffectsAndPublishesItsFiles()

	/**
	 * Without a session user the context names nobody, not a crash.
	 *
	 * @return void
	 */
	public function testNoSessionUserRunsWithAnEmptyUser(): void {
		$this->effects->method('declaredOn')->willReturn([['type' => 'x']]);
		$this->effects->expects($this->once())->method('run')->with(
			$this->anything(),
			$this->anything(),
			$this->callback(static fn (array $context): bool => $context['userId'] === '')
		);

		$this->listener(uid: null)->handle($this->event($this->task()));
	}//end testNoSessionUserRunsWithAnEmptyUser()

	/**
	 * A task that declares no effects still publishes its files, and its case
	 * is not even read.
	 *
	 * @return void
	 */
	public function testATaskWithoutEffectsOnlyPublishes(): void {
		$this->effects->method('declaredOn')->willReturn([]);
		$this->effects->expects($this->never())->method('run');
		$this->attachments->expects($this->once())->method('publish')->with('case-1', 'task-1');

		$this->listener()->handle($this->event($this->task()));

		$this->assertSame([], $this->objects->asked);
	}//end testATaskWithoutEffectsOnlyPublishes()

	/**
	 * A case that cannot be read is logged, the effects do not run, and the
	 * files are still published.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseSkipsTheEffects(): void {
		$this->objects->case = null;
		$this->effects->method('declaredOn')->willReturn([['type' => 'x']]);
		$this->effects->expects($this->never())->method('run');
		$this->attachments->expects($this->once())->method('publish');
		$messages = [];
		$this->logger->method('error')->willReturnCallback(
			static function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$this->listener()->handle($this->event($this->task()));

		$this->assertSame(
			[
				'Dossiq: the case of a completed task could not be read',
				'Dossiq: a completed task declared effects, and its case could not be read to run them',
			],
			$messages
		);
	}//end testAnUnreadableCaseSkipsTheEffects()

	/**
	 * Without a configured case schema there is no case to read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredCaseSchemaSkipsTheEffects(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturn('');
		$this->settings = $settings;
		$this->effects->method('declaredOn')->willReturn([['type' => 'x']]);
		$this->effects->expects($this->never())->method('run');
		$this->logger->expects($this->once())->method('error')
			->with('Dossiq: a completed task declared effects, and its case could not be read to run them');

		$this->listener()->handle($this->event($this->task()));

		$this->assertSame([], $this->objects->asked);
	}//end testAnUnconfiguredCaseSchemaSkipsTheEffects()

	/**
	 * A failed publish is logged with the task and the case, not thrown.
	 *
	 * @return void
	 */
	public function testAFailedPublishIsLogged(): void {
		$this->effects->method('declaredOn')->willReturn([]);
		$this->attachments->method('publish')->willThrowException(new RuntimeException('disk full'));
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq: a completed task could not publish its files to the case',
			['task' => 'task-1', 'case' => 'case-1', 'error' => 'disk full']
		);

		$this->listener()->handle($this->event($this->task()));
	}//end testAFailedPublishIsLogged()

	/**
	 * Cases where nothing at all happens.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function ignored(): array {
		return [
			'not committed' => [[], false],
			'not completed' => [['state' => 'terminated'], true],
			'no task id' => [['uuid' => null], true],
			'no case' => [['case' => null], true],
		];
	}//end ignored()

	/**
	 * An uncommitted, uncompleted, anonymous or case-less task does nothing.
	 *
	 * @param array<string, mixed> $overrides The task's fields.
	 * @param boolean              $committed Whether the event committed.
	 *
	 * @dataProvider ignored
	 *
	 * @return void
	 */
	public function testNothingHappens(array $overrides, bool $committed): void {
		$this->effects->expects($this->never())->method('declaredOn');
		$this->attachments->expects($this->never())->method('publish');

		$this->listener()->handle($this->event($this->task($overrides), $committed));
	}//end testNothingHappens()

	/**
	 * A task whose fields cannot be read is skipped, not thrown.
	 *
	 * @return void
	 */
	public function testAnUnreadableTaskIsSkipped(): void {
		$this->effects->expects($this->never())->method('declaredOn');
		$this->attachments->expects($this->never())->method('publish');

		$this->listener()->handle($this->event($this->task(['throws' => true])));
	}//end testAnUnreadableTaskIsSkipped()

	/**
	 * Another event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->effects->expects($this->never())->method('declaredOn');
		$this->attachments->expects($this->never())->method('publish');

		$this->listener()->handle(new Event());
	}//end testAnotherEventIsIgnored()

	/**
	 * A task entity with the fields the listener reads.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return Task
	 */
	private function task(array $overrides = []): Task {
		$fields = array_merge(
			[
				'uuid' => 'task-1',
				'title' => 'Check the file',
				'state' => 'completed',
				'case' => 'case-1',
				'metadata' => ['effects' => 1],
				'throws' => false,
			],
			$overrides
		);

		return new class ($fields) extends Task {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $fields The fields.
			 */
			public function __construct(private readonly array $fields) {
			}

			/**
			 * The uuid.
			 *
			 * @return string|null
			 */
			public function getUuid(): ?string {
				if ($this->fields['throws'] === true) {
					throw new RuntimeException('unreadable');
				}

				return $this->fields['uuid'];
			}

			/**
			 * The title.
			 *
			 * @return string|null
			 */
			public function getTitle(): ?string {
				return $this->fields['title'];
			}

			/**
			 * The state.
			 *
			 * @return string|null
			 */
			public function getState(): ?string {
				return $this->fields['state'];
			}

			/**
			 * The object the task is on.
			 *
			 * @return string|null
			 */
			public function getObjectUuid(): ?string {
				return $this->fields['case'];
			}

			/**
			 * The metadata.
			 *
			 * @return array|null
			 */
			public function getMetadata(): ?array {
				return $this->fields['metadata'];
			}
		};
	}//end task()

	/**
	 * The terminal event for a task.
	 *
	 * @param Task    $task      The task.
	 * @param boolean $committed Whether it committed.
	 *
	 * @return TaskTerminalEvent
	 */
	private function event(Task $task, bool $committed = true): TaskTerminalEvent {
		$event = $this->createMock(TaskTerminalEvent::class);
		$event->method('getTask')->willReturn($task);
		$event->method('isCommitted')->willReturn($committed);

		return $event;
	}//end event()

	/**
	 * The listener under test.
	 *
	 * @param string|null $uid The session user.
	 *
	 * @return TaskCompletionEffectsListener
	 */
	private function listener(?string $uid = 'alice'): TaskCompletionEffectsListener {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new TaskCompletionEffectsListener(
			effects: $this->effects,
			attachments: $this->attachments,
			settings: $this->settings,
			userSession: $session,
			logger: $this->logger,
		);
	}//end listener()
}//end class
