<?php

/**
 * BeroepService golden test
 *
 * Runs every public method over a set of store, configuration and transition
 * behaviours, and compares every object-service call (by parameter name),
 * every transition, every log line and every answer with a recorded golden
 * file.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
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
 * @spec openspec/specs/beroep-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\BeroepService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Throwable;

/**
 * An object service answering find(), saveObject() and patchObject() from a behaviour.
 */
final class GoldenAppealObjects {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $behaviour How the store answers.
	 * @param array<int, mixed>    $log       The shared call log.
	 */
	public function __construct(
		private array $behaviour,
		private array &$log,
	) {
	}//end __construct()

	/**
	 * Find one object.
	 *
	 * @param string     $id       The object id.
	 * @param int|string $register The register.
	 * @param int|string $schema   The schema.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, int|string $register, int|string $schema): ?array {
		$this->log[] = ['find' => compact('id', 'register', 'schema')];
		return ($this->behaviour['find'][$id] ?? null);
	}//end find()

	/**
	 * Save one object.
	 *
	 * @param array<string, mixed> $object   The fields.
	 * @param int|string           $register The register.
	 * @param int|string           $schema   The schema.
	 * @param string|null          $uuid     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	public function saveObject(array $object, int|string $register, int|string $schema, ?string $uuid = null): ?array {
		$this->log[] = ['saveObject' => compact('object', 'register', 'schema', 'uuid')];
		return $this->answer(key: 'save', fields: $object + ['id' => 'appeal-new']);
	}//end saveObject()

	/**
	 * Patch one object.
	 *
	 * @param string               $objectId The object id.
	 * @param array<string, mixed> $data     The changes.
	 * @param int|string           $register The register.
	 * @param int|string           $schema   The schema.
	 *
	 * @return array<string, mixed>|null
	 */
	public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): ?array {
		$this->log[] = ['patchObject' => compact('objectId', 'data', 'register', 'schema')];
		return $this->answer(key: 'patch', fields: $data + ['id' => $objectId, 'patched' => true]);
	}//end patchObject()

	/**
	 * The answer a write gives: the fields, null, or a throw.
	 *
	 * @param string               $key    The behaviour key.
	 * @param array<string, mixed> $fields The fields to answer.
	 *
	 * @return array<string, mixed>|null
	 */
	private function answer(string $key, array $fields): ?array {
		$mode = ($this->behaviour[$key] ?? 'ok');
		if ($mode === 'throw') {
			throw new RuntimeException($key . ' failed');
		}

		return ($mode === 'null') ? null : $fields;
	}//end answer()
}//end class

/**
 * Golden test for BeroepService.
 *
 * Set GOLDEN_RECORD=1 to rewrite the golden file; a recording run compares
 * nothing, so run once more without it.
 *
 * @covers \OCA\Dossiq\Service\Bezwaar\BeroepService
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class BeroepServiceGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The calls each case makes: [method, named arguments, behaviour].
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private static function cases(): array {
		$filing = ['caseId' => 'case-1', 'sourceObjectionId' => 'obj-1', 'contestedDecisionId' => 'dec-1', 'filingDate' => '2026-03-01'];
		$decision = ['find' => ['dec-1' => ['effectiveDate' => '2026-01-10']]];
		$appeal = ['id' => 'ap-1', 'sourceObjection' => 'obj-1', 'fileInspectionRequests' => [['requestedAt' => '2026-01-01', 'deadline' => '2026-01-29']]];
		$found = ['find' => ['ap-1' => $appeal, 'obj-1' => ['case' => 'case-0']]];
		$inspect = ['appealId' => 'ap-1', 'requestedAt' => '2026-04-01'];
		$reopen = ['appealId' => 'ap-1', 'action' => 'reopen_objection'];

		return [
			'register / late' => ['register', $filing, $decision],
			'register / on time' => ['register', ['filingDate' => '2026-02-01'] + $filing, $decision],
			'register / on the deadline' => ['register', ['filingDate' => '2026-02-21'] + $filing, $decision],
			'register / payload' => ['register', $filing + ['payload' => ['courtReference' => 'AWB 26/1', 'responsibleChamber' => 'meervoudig', 'case' => 'forged', 'filingDeadline' => 'forged']], $decision],
			'register / no effective date' => ['register', $filing, ['find' => ['dec-1' => ['title' => 'x']]]],
			'register / bad effective date' => ['register', $filing, ['find' => ['dec-1' => ['effectiveDate' => 'not a date']]]],
			'register / bad filing date' => ['register', ['filingDate' => 'nonsense'] + $filing, $decision],
			'register / no filing date' => ['register', ['filingDate' => ''] + $filing, $decision],
			'register / no object service' => ['register', $filing, $decision + ['noObjectService' => true]],
			'register / no register' => ['register', $filing, $decision + ['config' => ['register' => '']]],
			'register / no appeal schema' => ['register', $filing, $decision + ['config' => ['beroep_schema' => '']]],
			'register / no decision schema' => ['register', $filing, $decision + ['config' => ['appeal_decision_schema' => '']]],
			'register / decision missing' => ['register', $filing, []],
			'register / save throws' => ['register', $filing, $decision + ['save' => 'throw']],
			'register / save answers null' => ['register', $filing, $decision + ['save' => 'null']],
			'inspect / plain' => ['addFileInspectionRequest', $inspect, $found],
			'inspect / with bundle' => ['addFileInspectionRequest', $inspect + ['fileBundle' => 'file-9'], $found],
			'inspect / empty bundle' => ['addFileInspectionRequest', $inspect + ['fileBundle' => ''], $found],
			'inspect / first request' => ['addFileInspectionRequest', $inspect, ['find' => ['ap-1' => ['id' => 'ap-1']]]],
			'inspect / bad date' => ['addFileInspectionRequest', ['requestedAt' => 'soon'] + $inspect, $found],
			'inspect / no object service' => ['addFileInspectionRequest', $inspect, ['noObjectService' => true]],
			'inspect / not found' => ['addFileInspectionRequest', $inspect, []],
			'inspect / patch throws' => ['addFileInspectionRequest', $inspect, $found + ['patch' => 'throw']],
			'inspect / patch answers null' => ['addFileInspectionRequest', $inspect, $found + ['patch' => 'null']],
			'cascade / invalid' => ['executeCascade', ['action' => 'reopen_bezwaar'] + $reopen, $found],
			'cascade / reopen' => ['executeCascade', $reopen, $found],
			'cascade / reopen, no source' => ['executeCascade', $reopen, ['find' => ['ap-1' => ['id' => 'ap-1']]]],
			'cascade / reopen, no objection schema' => ['executeCascade', $reopen, $found + ['config' => ['bezwaar_schema' => '']]],
			'cascade / reopen, source missing' => ['executeCascade', $reopen, ['find' => ['ap-1' => $appeal]]],
			'cascade / reopen, source without case' => ['executeCascade', $reopen, ['find' => ['ap-1' => $appeal, 'obj-1' => ['title' => 'x']]]],
			'cascade / reopen, transition throws' => ['executeCascade', $reopen, $found + ['transitionThrows' => true]],
			'cascade / reopen, patch null' => ['executeCascade', $reopen, $found + ['patch' => 'null']],
			'cascade / new primary decision' => ['executeCascade', ['action' => 'new_primary_decision'] + $reopen, $found],
			'cascade / none' => ['executeCascade', ['action' => 'none'] + $reopen, $found],
			'cascade / not found' => ['executeCascade', $reopen, []],
			'cascade / no object service' => ['executeCascade', $reopen, ['noObjectService' => true]],
			'cascade / patch throws' => ['executeCascade', $reopen, $found + ['patch' => 'throw']],
		];
	}//end cases()

	/**
	 * Every case matches the golden record.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenRecord(): void {
		$actual = [];
		foreach (self::cases() as $name => [$method, $args, $behaviour]) {
			$log = [];
			$service = $this->service(behaviour: $behaviour, log: $log);
			try {
				$answer = $service->$method(...$args);
			} catch (Throwable $e) {
				$answer = ['threw' => get_class($e), 'message' => $e->getMessage()];
			}

			$actual[$name] = ['answer' => $answer, 'calls' => $log];
		}

		$this->assertMatchesGolden(name: 'beroep-service', value: $actual);
	}//end testEveryCaseMatchesTheGoldenRecord()

	/**
	 * The service over recording doubles.
	 *
	 * @param array<string, mixed> $behaviour How the collaborators answer.
	 * @param array<int, mixed>    $log       The shared call log.
	 *
	 * @return BeroepService
	 */
	private function service(array $behaviour, array &$log): BeroepService {
		$config = array_merge(
			['register' => 'dossiq', 'beroep_schema' => 'beroep', 'appeal_decision_schema' => 'appealDecision', 'bezwaar_schema' => 'objection'],
			($behaviour['config'] ?? [])
		);
		$objects = new GoldenAppealObjects(behaviour: $behaviour, log: $log);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(
			static function () use ($behaviour, $objects, &$log): ?object {
				$log[] = 'settings getObjectService';
				return (($behaviour['noObjectService'] ?? false) === true) ? null : $objects;
			}
		);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use ($config, &$log): string {
				$log[] = 'settings getConfigValue ' . $key;
				return ($config[$key] ?? $default);
			}
		);
		$transitions = $this->createMock(StatusTransitionService::class);
		$transitions->method('execute')->willReturnCallback(
			static function (...$args) use ($behaviour, &$log): array {
				$log[] = ['transition' => array_combine(['caseId', 'transitionId', 'comment', 'userId', 'resultTypeId'], array_pad($args, 5, null))];
				if (($behaviour['transitionThrows'] ?? false) === true) {
					throw new RuntimeException('transition refused');
				}

				return ['ok' => true];
			}
		);

		return new BeroepService(settingsService: $settings, transitions: $transitions, logger: $this->logger(log: $log));
	}//end service()

	/**
	 * A logger writing into the call log.
	 *
	 * @param array<int, mixed> $log The shared call log.
	 *
	 * @return AbstractLogger
	 */
	private function logger(array &$log): AbstractLogger {
		return new class ($log) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $log The shared call log.
			 */
			public function __construct(private array &$log) {
			}//end __construct()

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
				$this->log[] = ['log.' . (string)$level => ['message' => (string)$message, 'context' => $context]];
			}//end log()
		};
	}//end logger()
}//end class
