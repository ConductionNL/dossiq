<?php

/**
 * DsoCaseService golden test
 *
 * Pins what intake, a status transition, the mutation check and the deadline
 * read, write, log, dispatch and answer, across every collaborator answer.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T02
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Dso\DsoCaseSteps;
use OCA\Dossiq\Service\Dso\DsoIntakeCasePayload;
use OCA\Dossiq\Service\Dso\DsoStatusChangeNotifier;
use OCA\Dossiq\Service\DsoCaseService;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden test for DsoCaseService.
 *
 * @covers \OCA\Dossiq\Service\DsoCaseService
 * @covers \OCA\Dossiq\Service\Dso\DsoCaseSteps
 * @uses \OCA\Dossiq\Service\Lifecycle\CaseJournal
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Support\RowList
 */
class DsoCaseServiceGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every call any collaborator received, by parameter name.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * A double whose listed methods record their call and answer as configured.
	 *
	 * Arguments left at their declared default are not recorded, so the record
	 * does not depend on how many optional parameters the loaded class has.
	 *
	 * @param string               $class     The collaborator class.
	 * @param array<string, mixed> $behaviour Per method: a value, a closure over the named args, or 'throw'.
	 *
	 * @return object The double.
	 */
	private function double(string $class, array $behaviour): object {
		$mock = $this->createMock($class);
		foreach ($behaviour as $method => $answer) {
			$params = (new ReflectionMethod($class, $method))->getParameters();
			$mock->method($method)->willReturnCallback(
				function (mixed ...$args) use ($class, $method, $params, $answer): mixed {
					$named = [];
					foreach ($args as $index => $value) {
						$param = $params[$index];
						if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $value) {
							continue;
						}

						$named[$param->getName()] = ($value instanceof \JsonSerializable ? $value->jsonSerialize() : $value);
					}

					$short = substr($class, (strrpos($class, '\\') + 1));
					$this->calls[] = [$short . '::' . $method, $named];
					if ($answer === 'throw') {
						throw new RuntimeException($method . ' failed');
					}

					if ($answer instanceof \Closure) {
						return $answer(...$named);
					}

					return $answer;
				}
			);
		}

		return $mock;
	}//end double()

	/**
	 * An entity that serialises to this value.
	 *
	 * @param mixed $value The serialised value.
	 *
	 * @return ObjectEntityInterface The entity.
	 */
	private function entity(mixed $value): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($value);

		return $entity;
	}//end entity()

	/**
	 * Run one call against a world and record every effect.
	 *
	 * @param array<string, mixed> $world The answers per collaborator and the call.
	 *
	 * @return array<string, mixed> The answer or exception, the calls and the log lines.
	 */
	private function observe(array $world): array {
		$this->calls = [];
		$config = ($world['config'] ?? []) + [
			'register' => 'dossiq',
			'case_schema' => 'case',
			'dso_vergunningaanvraag_schema' => 'aanvraag',
		];
		$appConfig = $this->double(
			IAppConfig::class,
			['getValueString' => static fn (string $app, string $key, string $default = ''): string => (string)($config[$key] ?? $default)]
		);

		$objects = ($world['objects'] ?? []);
		$entity = fn (mixed $value): mixed => (is_array($value) === true || is_string($value) === true ? $this->entity(value: $value) : $value);
		$objectService = $this->double(
			ObjectServiceInterface::class,
			[
				'find' => ($objects['find'] ?? null) === 'throw' ? 'throw' : static function (int|string $id, mixed ...$rest) use ($objects, $entity): mixed {
					return $entity(($objects['find'] ?? [])[$id] ?? null);
				},
				'searchObjectsBySlug' => ($objects['search'] ?? []),
				'saveObject' => ($objects['save'] ?? null) === 'throw' ? 'throw' : static fn (array $object, mixed ...$rest): mixed => $entity(($objects['save'] ?? null) ?? ($object + ['id' => 'saved-1'])),
				'patchObject' => ($objects['patch'] ?? null) === 'throw' ? 'throw' : static fn (string $objectId, array $data, mixed ...$rest): mixed => $entity(($objects['patch'] ?? null) ?? (['id' => $objectId] + $data)),
			]
		);

		$groups = ($world['admin'] ?? false);
		$container = $this->double(
			ContainerInterface::class,
			['get' => $groups === 'throw' ? 'throw' : $this->double(IGroupManager::class, ['isAdmin' => $groups])]
		);

		$logger = new class extends AbstractLogger {
			/**
			 * Every log line.
			 *
			 * @var array<int, mixed>
			 */
			public array $lines = [];

			/**
			 * Record one line.
			 *
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, $context];
			}
		};

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$service = new DsoCaseService(
			appConfig: $appConfig,
			container: $container,
			steps: new DsoCaseSteps(
				notifier: $this->double(class: DsoStatusChangeNotifier::class, behaviour: ['dispatchStatusChanged' => null]),
				intakeCases: $this->double(
					class: DsoIntakeCasePayload::class,
					behaviour: [
						'payloadFor' => (
							$world['payload'] ?? ['caseType' => 'ct-1', 'procedureType' => 'reguliere', 'startDate' => '2026-01-05', 'title' => 'Aanvraag']
						),
					]
				),
				journal: new CaseJournal(userSession: $session),
			),
			logger: $logger,
			objectService: $objectService,
			workingDays: new WorkingDayCalculator(),
		);

		try {
			$answer = ($world['call'])($service);
		} catch (\Throwable $thrown) {
			$answer = ['thrown' => get_class($thrown), 'message' => $thrown->getMessage()];
		}

		$json = (string)json_encode(['answer' => $answer, 'calls' => $this->calls, 'logs' => $logger->lines], JSON_PRESERVE_ZERO_FRACTION);

		return json_decode((string)preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d/', '<now>', $json), true);
	}//end observe()

	/**
	 * Every public operation across its guards and its collaborators' answers.
	 *
	 * @return void
	 */
	public function testGoldenDsoCaseService(): void {
		$move = static fn (?string $date = '2026-02-03', ?string $notes = 'Besloten', string $to = 'verleend'): \Closure => static fn (DsoCaseService $s): array => $s->transitionStatus(
			caseId: 'case-1',
			newStatus: $to,
			besluitdatum: $date,
			notes: $notes,
			userId: 'anna',
		);
		$intake = static fn (?array $given = null): \Closure => static fn (DsoCaseService $s): array => $s->createZaakFromVergunningaanvraag(
			permitApplicationId: 'va-1',
			permitApplication: $given,
		);
		$guard = function (array $case): \Closure {
			return function (DsoCaseService $s) use ($case): string {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn('anna');
				$s->authorizeZaakMutation(case: $case, user: $user);
				return 'allowed';
			};
		};
		$case = ['id' => 'case-1', 'dsoStatus' => 'in_behandeling', 'permitApplicationRef' => 'va-1', 'title' => 'Dakkapel'];
		$request = ['id' => 'va-1', 'status' => 'ingediend', 'activiteit' => 'bouwen'];

		$worlds = [
			'move, with date and notes' => ['objects' => ['find' => ['case-1' => $case, 'va-1' => $request]], 'call' => $move()],
			'move, no date, no notes' => ['objects' => ['find' => ['case-1' => $case, 'va-1' => $request]], 'call' => $move(date: null, notes: null)],
			'move, only notes' => ['objects' => ['find' => ['case-1' => $case, 'va-1' => $request]], 'call' => $move(date: null)],
			'move, only a date' => ['objects' => ['find' => ['case-1' => $case, 'va-1' => $request]], 'call' => $move(notes: null)],
			'move, empty notes' => ['objects' => ['find' => ['case-1' => $case, 'va-1' => $request]], 'call' => $move(notes: '')],
			'move, earlier journal kept' => ['objects' => ['find' => ['case-1' => $case + [CaseJournal::FIELD => (string)json_encode([['type' => 'created', 'at' => 'x', 'by' => 'y']])], 'va-1' => $request]], 'call' => $move()],
			'move, no status yet' => ['objects' => ['find' => ['case-1' => array_diff_key($case, ['dsoStatus' => 1]), 'va-1' => $request]], 'call' => $move()],
			'move, no permit application' => ['objects' => ['find' => ['case-1' => array_diff_key($case, ['permitApplicationRef' => 1])]], 'call' => $move()],
			'move, permit application missing' => ['objects' => ['find' => ['case-1' => $case]], 'call' => $move()],
			'move, no permit application schema' => ['config' => ['dso_vergunningaanvraag_schema' => ''], 'objects' => ['find' => ['case-1' => $case, 'va-1' => $request]], 'call' => $move()],
			'move, permit application save fails' => ['objects' => ['find' => ['case-1' => $case, 'va-1' => $request], 'save' => 'throw'], 'call' => $move()],
			'move, case missing' => ['objects' => ['find' => []], 'call' => $move()],
			'move, case read fails' => ['objects' => ['find' => 'throw'], 'call' => $move()],
			'move, case reads as a scalar' => ['objects' => ['find' => ['case-1' => 'plain', 'va-1' => $request]], 'call' => $move()],
			'move, patch fails' => ['objects' => ['find' => ['case-1' => $case, 'va-1' => $request], 'patch' => 'throw'], 'call' => $move()],
			'move, patch answers a scalar' => ['objects' => ['find' => ['case-1' => $case, 'va-1' => $request], 'patch' => 'odd'], 'call' => $move()],
			'move, no register configured' => ['config' => ['register' => '', 'case_schema' => ''], 'objects' => ['find' => ['case-1' => $case, 'va-1' => $request]], 'call' => $move()],
			'intake, given application, new case' => ['call' => $intake(given: $request)],
			'intake, read application, new case' => ['objects' => ['find' => ['va-1' => $request]], 'call' => $intake()],
			'intake, application missing' => ['objects' => ['find' => []], 'call' => $intake()],
			'intake, application read fails' => ['objects' => ['find' => 'throw'], 'call' => $intake()],
			'intake, case already exists' => ['objects' => ['search' => [['id' => 'case-old', 'permitApplicationRef' => 'va-1'], ['id' => 'case-older']]], 'call' => $intake(given: $request)],
			'intake, extensive procedure' => ['payload' => ['caseType' => 'ct-2', 'procedureType' => 'uitgebreide', 'startDate' => '2026-03-02'], 'call' => $intake(given: $request)],
			'intake, no case type answers' => ['payload' => 'throw', 'call' => $intake(given: $request)],
			'intake, save answers a scalar' => ['objects' => ['save' => 'odd'], 'call' => $intake(given: $request)],
			'intake, save fails' => ['objects' => ['save' => 'throw'], 'call' => $intake(given: $request)],
			'guard, the assignee' => ['call' => $guard(['assignee' => 'anna'])],
			'guard, the older assignee field' => ['call' => $guard(['assigneeUserId' => 'anna', 'handler' => 'piet'])],
			'guard, the handler field' => ['call' => $guard(['handler' => 'anna'])],
			'guard, the assignee field wins' => ['call' => $guard(['assignee' => 'piet', 'handler' => 'anna'])],
			'guard, an admin' => ['admin' => true, 'call' => $guard(['assignee' => 'piet'])],
			'guard, not an admin' => ['admin' => false, 'call' => $guard(['assignee' => 'piet'])],
			'guard, no assignee, not an admin' => ['admin' => false, 'call' => $guard([])],
			'guard, groups unavailable' => ['admin' => 'throw', 'call' => $guard(['assignee' => 'piet'])],
			'deadline, regular' => ['call' => static fn (DsoCaseService $s): string => $s->computeDeadline(submissionDate: '2026-01-05', procedureType: 'reguliere')],
			'deadline, extensive' => ['call' => static fn (DsoCaseService $s): string => $s->computeDeadline(submissionDate: '2026-12-18', procedureType: 'uitgebreide')],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'dso-case-service', value: $observed);
	}//end testGoldenDsoCaseService()

	/**
	 * A read answer is normalised from an array, a serialisable object, or neither.
	 *
	 * @return void
	 */
	public function testNormalizeToArrayAcceptsArraysAndSerialisableObjects(): void {
		$service = (new \ReflectionClass(DsoCaseService::class))->newInstanceWithoutConstructor();
		$normalise = new ReflectionMethod(DsoCaseService::class, 'normalizeToArray');

		self::assertSame(['id' => 'a'], $normalise->invoke($service, ['id' => 'a']));
		self::assertSame(['id' => 'b'], $normalise->invoke($service, $this->entity(value: ['id' => 'b'])));
		self::assertSame([], $normalise->invoke($service, $this->entity(value: 'plain')));
		self::assertSame([], $normalise->invoke($service, new \stdClass()));
		self::assertSame([], $normalise->invoke($service, 'plain'));
	}//end testNormalizeToArrayAcceptsArraysAndSerialisableObjects()
}//end class
