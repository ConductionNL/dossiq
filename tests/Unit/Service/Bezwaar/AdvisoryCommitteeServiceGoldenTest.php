<?php

/**
 * AdvisoryCommitteeService golden test
 *
 * Pins what assigning a committee, moving an advice request through its
 * states, auto-assigning and recording a council deviation read, write, log
 * and answer, for stores and collaborators that answer, answer nothing or fail.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use DateTimeImmutable;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail;
use OCA\Dossiq\Service\Bezwaar\PanelIndependenceChecker;
use OCA\Dossiq\Service\Governance\GovernanceBodyReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden test for AdvisoryCommitteeService.
 *
 * @covers \OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Transitions\GuardFailedException
 */
class AdvisoryCommitteeServiceGoldenTest extends TestCase {
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
	 * @param string               $class     The collaborator class.
	 * @param array<string, mixed> $behaviour Per method: a value, a closure over the named args, or 'throw'.
	 *
	 * @return object The double.
	 */
	private function double(string $class, array $behaviour): object {
		$mock = $this->createMock($class);
		foreach ($behaviour as $method => $answer) {
			$names = array_map(
				static fn (\ReflectionParameter $param): string => $param->getName(),
				(new ReflectionMethod($class, $method))->getParameters()
			);
			$mock->method($method)->willReturnCallback(
				function (mixed ...$args) use ($class, $method, $names, $answer): mixed {
					$named = [];
					foreach ($args as $index => $value) {
						$named[$names[$index]] = $value;
					}

					$short = substr($class, (strrpos($class, '\\') + 1));
					$this->calls[] = [$short . '::' . $method, array_map(static fn ($v) => ($v instanceof \Closure ? 'closure' : $v), $named)];
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
	 * Run one call against a world and record every effect.
	 *
	 * @param array<string, mixed> $world The behaviour per collaborator and the call.
	 *
	 * @return array<string, mixed> The answer or exception, the calls and the log lines.
	 */
	private function observe(array $world): array {
		$this->calls = [];
		$calls = &$this->calls;
		$store = new class($world['store'] ?? [], $calls) {
			/**
			 * @param array<string, mixed> $behaviour What each method answers ('throw' throws).
			 * @param array<int, mixed> $calls Collects every call.
			 */
			public function __construct(private array $behaviour, private array &$calls) {
			}

			/**
			 * @param string $method The method.
			 * @param array $args Its arguments by name.
			 *
			 * @return mixed
			 */
			private function answer(string $method, array $args): mixed {
				$this->calls[] = ['store::' . $method, $args];
				$answer = (array_key_exists($method, $this->behaviour) === true ? $this->behaviour[$method] : null);
				if ($answer === 'throw') {
					throw new RuntimeException($method . ' failed');
				}

				if ($answer instanceof \Closure) {
					return $answer($args);
				}

				return $answer;
			}

			public function find(string $id, mixed $register = null, mixed $schema = null): mixed {
				return $this->answer('find', ['id' => $id, 'register' => $register, 'schema' => $schema]);
			}

			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null): mixed {
				return $this->answer('saveObject', ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid]);
			}

			public function patchObject(string $objectId, array $data, mixed $register = null, mixed $schema = null): mixed {
				return $this->answer('patchObject', ['objectId' => $objectId, 'data' => $data, 'register' => $register, 'schema' => $schema]);
			}
		};

		$config = ($world['config'] ?? [
			'register' => 'reg',
			'bac_advice_request_schema' => 'req',
			'bezwaaradviescommissie_schema' => 'com',
			'bac_default_committee' => 'com-default',
		]);
		$settings = $this->double(
			SettingsService::class,
			[
				'getObjectService' => (($world['noStore'] ?? false) === true ? null : $store),
				'getConfigValue' => static fn (string $key, string $default = ''): string => ($config[$key] ?? $default),
			]
		);
		$delegation = $this->double(AdviceDelegationService::class, ['raiseAdviceDecision' => ($world['raise'] ?? 'decision-1')]);
		$audit = $this->double(
			BezwaarAuditTrail::class,
			($world['audit'] ?? []) + [
				'recordForNewRecord' => null,
				'record' => null,
				'recordRefusal' => true,
				'resolveActor' => 'chair-anna',
				'recordThenApply' => static fn (string $register, string $schema, string $objectUuid, string $event, array $payload, string $tag, callable $apply): mixed => $apply(),
			]
		);
		$independence = $this->double(PanelIndependenceChecker::class, ['check' => ($world['independence'] ?? ['ok' => true, 'member' => null, 'reason' => ''])]);
		$bodies = null;
		if (array_key_exists('bodies', $world) === true) {
			$bodies = $this->double(GovernanceBodyReader::class, ['resolve' => $world['bodies']]);
		}

		$logs = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$logs, $level): void {
					$logs[] = [$level, $message, $context];
				}
			);
		}

		$service = new AdvisoryCommitteeService(
			settingsService: $settings,
			logger: $logger,
			adviceDelegation: $delegation,
			auditTrail: $audit,
			independence: $independence,
			governanceBodies: $bodies,
		);
		try {
			$answer = ($world['call'])($service);
		} catch (\Throwable $e) {
			$answer = ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}

		$json = (string)json_encode(['answer' => $answer, 'calls' => $this->calls, 'logs' => $logs]);
		$deadline = (new DateTimeImmutable())->modify('+84 days')->format('Y-m-d');
		$json = str_replace($deadline, '<deadline>', $json);
		$json = (string)preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d/', '<now>', $json);

		return json_decode($json, true);
	}//end observe()

	/**
	 * Every method across its guards and its collaborators' answers.
	 *
	 * @return void
	 */
	public function testGoldenAdvisoryCommitteeService(): void {
		$assign = static fn (array $payload = ['panel' => ['p1'], 'note' => 'x']): \Closure => static fn (AdvisoryCommitteeService $s): array => $s->assignToCommittee(
			objectionId: 'obj-1',
			commissieId: 'com-1',
			payload: $payload,
		);
		$move = static fn (string $to, array $payload = []): \Closure => static fn (AdvisoryCommitteeService $s): array => $s->transitionAdviceStatus(
			requestId: 'req-1',
			newStatus: $to,
			payload: $payload,
		);
		$deviate = static fn (AdvisoryCommitteeService $s): string => json_encode($s->recordCouncilDeviation(requestId: 'req-1', decisionId: 'dec-1', rationaleRef: 'mot-1'));
		$committee = ['find' => ['id' => 'com-1', 'active' => true]];
		$at = static fn (array $request): array => ['find' => $request, 'patchObject' => static fn (array $args): array => ['id' => 'req-1', 'patched' => $args['data']]];
		$assigned = ['id' => 'req-1', 'status' => 'assigned', 'panel' => ['p1', 'p2'], 'bezwaar' => 'obj-1'];
		$deliberating = ['id' => 'req-1', 'status' => 'in-deliberation', 'bezwaar' => 'obj-1', 'conclusion' => 'gegrond', 'recommendation' => 'herroepen', 'signatureEvidence' => 'sig-0'];
		$advice = ['conclusion' => 'ongegrond', 'recommendation' => 'handhaven', 'signatureEvidence' => 'sig-1'];
		$config = ['register' => 'reg', 'bac_advice_request_schema' => 'req', 'bezwaaradviescommissie_schema' => 'com', 'bac_default_committee' => 'com-default'];

		$scenarios = [
			'assign' => ['store' => $committee + ['saveObject' => ['@self' => ['id' => 'req-self'], 'id' => 'req-plain']]],
			'assign, saved with plain id' => ['store' => $committee + ['saveObject' => ['id' => 'req-plain']]],
			'assign, saved with uuid' => ['store' => $committee + ['saveObject' => ['uuid' => 'req-uuid']]],
			'assign, save answers nothing' => ['store' => $committee + ['saveObject' => null]],
			'assign, save fails' => ['store' => $committee + ['saveObject' => 'throw']],
			'assign, no payload' => ['store' => $committee + ['saveObject' => ['id' => 'r']], 'call' => $assign([])],
			'assign, payload overrides the fixed fields' => ['store' => $committee + ['saveObject' => ['id' => 'r']], 'call' => $assign(['bezwaar' => 'evil', 'status' => 'advice-issued', 'deadline' => '2030-01-01', 'committee' => 'c-x'])],
			'assign, committee not found' => ['store' => ['find' => null]],
			'assign, committee archived' => ['store' => ['find' => ['id' => 'com-1', 'active' => false]]],
			'assign, committee active as a string' => ['store' => $committee + ['saveObject' => ['id' => 'r'], 'find' => ['id' => 'com-1', 'active' => 'false']]],
			'assign, decidiq says archived' => ['store' => $committee, 'bodies' => ['id' => 'com-1', 'active' => false]],
			'assign, decidiq says active' => ['store' => ['find' => ['id' => 'com-1', 'active' => false], 'saveObject' => ['id' => 'r']], 'bodies' => ['id' => 'com-1']],
			'assign, audit fails' => ['store' => $committee + ['saveObject' => ['id' => 'r']], 'audit' => ['recordForNewRecord' => 'throw']],
			'assign, no store' => ['noStore' => true],
			'assign, no register' => ['config' => array_diff_key($config, ['register' => 1])],
			'assign, no request schema' => ['config' => array_diff_key($config, ['bac_advice_request_schema' => 1])],
			'assign, no committee schema' => ['config' => array_diff_key($config, ['bezwaaradviescommissie_schema' => 1])],
			'move, invalid status' => ['call' => $move('done')],
			'move, no store' => ['noStore' => true, 'call' => $move('in-deliberation')],
			'move, not found' => ['store' => ['find' => null], 'call' => $move('in-deliberation')],
			'move, find fails' => ['store' => ['find' => 'throw'], 'call' => $move('in-deliberation')],
			'move, no register configured' => ['config' => [], 'store' => $at($assigned), 'call' => $move('in-deliberation')],
			'move, to deliberation' => ['store' => $at($assigned), 'call' => $move('in-deliberation', ['note' => 'n'])],
			'move, to deliberation, status missing reads as assigned' => ['store' => $at(array_diff_key($assigned, ['status' => 1])), 'call' => $move('in-deliberation')],
			'move, to deliberation, legacy objection key' => ['store' => $at(['id' => 'req-1', 'status' => 'assigned', 'panel' => ['p1'], 'objectionProceeding' => 'obj-old']), 'call' => $move('in-deliberation')],
			'move, to deliberation, no objection' => ['store' => $at(['id' => 'req-1', 'status' => 'assigned', 'panel' => ['p1']]), 'call' => $move('in-deliberation')],
			'move, to deliberation, no panel' => ['store' => $at(['panel' => []] + $assigned), 'call' => $move('in-deliberation')],
			'move, to deliberation, conflict' => ['store' => $at($assigned), 'independence' => ['ok' => false, 'member' => 'p2', 'reason' => 'eerder betrokken'], 'call' => $move('in-deliberation')],
			'move, to deliberation, conflict, refusal entry fails' => ['store' => $at($assigned), 'independence' => ['ok' => false, 'member' => 'p2', 'reason' => 'r'], 'audit' => ['recordRefusal' => 'throw'], 'call' => $move('in-deliberation')],
			'move, to deliberation, patch answers nothing' => ['store' => ['find' => $assigned, 'patchObject' => null], 'call' => $move('in-deliberation', ['x' => 1])],
			'move, to deliberation, patch fails' => ['store' => ['find' => $assigned, 'patchObject' => 'throw'], 'call' => $move('in-deliberation')],
			'move, assigned to advice skips a step' => ['store' => $at($assigned), 'call' => $move('advice-issued', $advice)],
			'move, back to assigned' => ['store' => $at($deliberating), 'call' => $move('assigned')],
			'move, from an unknown status' => ['store' => $at(['status' => 'weird'] + $assigned), 'call' => $move('in-deliberation')],
			'move, issue advice' => ['store' => $at($deliberating), 'call' => $move('advice-issued', $advice)],
			'move, issue advice from the stored fields' => ['store' => $at($deliberating), 'call' => $move('advice-issued')],
			'move, issue advice, payload status is overridden' => ['store' => $at($deliberating), 'call' => $move('advice-issued', ['status' => 'assigned', 'decisionRef' => 'old'] + $advice)],
			'move, issue advice, no objection uses the request id' => ['store' => $at(array_diff_key($deliberating, ['bezwaar' => 1])), 'call' => $move('advice-issued')],
			'move, issue advice, missing conclusion' => ['store' => $at(array_diff_key($deliberating, ['conclusion' => 1])), 'call' => $move('advice-issued')],
			'move, issue advice, both fields missing' => ['store' => $at(array_diff_key($deliberating, ['conclusion' => 1, 'recommendation' => 1])), 'call' => $move('advice-issued', ['conclusion' => ''])],
			'move, issue advice, empty recommendation' => ['store' => $at(['recommendation' => []] + $deliberating), 'call' => $move('advice-issued')],
			'move, issue advice, decision raise fails' => ['store' => $at($deliberating), 'raise' => 'throw', 'call' => $move('advice-issued')],
			'move, issue advice, decision ref empty' => ['store' => $at($deliberating), 'raise' => '', 'call' => $move('advice-issued')],
			'move, issue advice, signing entry fails' => ['store' => $at($deliberating), 'audit' => ['recordThenApply' => 'throw'], 'call' => $move('advice-issued')],
			'move, issue advice, no signature anywhere' => ['store' => $at(array_diff_key($deliberating, ['signatureEvidence' => 1])), 'call' => $move('advice-issued')],
			'move, issue advice, forged signer in payload and object' => ['store' => $at(['chair' => 'mallory', 'owner' => 'mallory', 'actor' => 'mallory'] + $deliberating), 'call' => $move('advice-issued', ['chair' => 'mallory', 'actor' => 'mallory', 'userId' => 'mallory'] + $advice)],
			'move, issued advice cannot be reopened' => ['store' => $at(['status' => 'advice-issued'] + $deliberating), 'call' => $move('assigned')],
			'move, inadmissible cannot be reopened' => ['store' => $at(['status' => 'inadmissible'] + $deliberating), 'call' => $move('in-deliberation')],
			'move, inadmissible' => ['store' => $at($deliberating), 'call' => $move('inadmissible', ['reason' => 'te laat'])],
			'move, inadmissible, patch answers nothing' => ['store' => ['find' => $deliberating, 'patchObject' => null], 'call' => $move('inadmissible')],
			'auto assign' => ['store' => $committee + ['saveObject' => ['id' => 'r']], 'call' => static fn (AdvisoryCommitteeService $s): ?array => $s->autoAssignDefaultCommittee(objectionId: 'obj-1')],
			'auto assign, none configured' => ['config' => array_diff_key($config, ['bac_default_committee' => 1]), 'call' => static fn (AdvisoryCommitteeService $s): ?array => $s->autoAssignDefaultCommittee(objectionId: 'obj-1')],
			'auto assign, fails' => ['store' => ['find' => null], 'call' => static fn (AdvisoryCommitteeService $s): ?array => $s->autoAssignDefaultCommittee(objectionId: 'obj-1')],
			'deviation' => ['store' => ['find' => ['id' => 'req-1']], 'call' => $deviate],
			'deviation, no store' => ['noStore' => true, 'call' => $deviate],
			'deviation, read fails' => ['store' => ['find' => 'throw'], 'call' => $deviate],
			'deviation, not found' => ['store' => ['find' => null], 'call' => $deviate],
			'deviation, entry fails' => ['store' => ['find' => ['id' => 'req-1']], 'audit' => ['record' => 'throw'], 'call' => $deviate],
		];

		$observed = [];
		foreach ($scenarios as $name => $world) {
			$observed[$name] = $this->observe(world: $world + ['call' => $assign()]);
		}

		$this->assertMatchesGolden('advisory-committee-service', $observed);
	}//end testGoldenAdvisoryCommitteeService()
}//end class
