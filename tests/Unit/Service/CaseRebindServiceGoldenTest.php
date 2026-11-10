<?php

/**
 * CaseRebindService golden test
 *
 * Runs options, preview and rebind over a set of store, gate, impact and
 * engine behaviours, and compares every collaborator call (by parameter
 * name), every log line and every answer with a recorded golden file. The
 * rebind cases also pin the acting user: forged actor fields in the answers
 * and the remap never reach the gate, the engine, the journal or the log.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Cases\CaseRebindGate;
use OCA\Dossiq\Service\Cases\CaseRebindImpact;
use OCA\Dossiq\Service\Cases\CaseRebindTerms;
use OCA\Dossiq\Service\CaseRebindService;
use OCA\Dossiq\Service\CaseType\EngineRunMigration;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * An object service answering find() and updateObject() from a behaviour.
 */
final class GoldenRebindObjects {
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
	 * @param string     $id       The id.
	 * @param int|string $register The register.
	 * @param int|string $schema   The schema.
	 *
	 * @return mixed
	 */
	public function find(string $id, int|string $register = '', int|string $schema = ''): mixed {
		$this->log[] = ['objects.find' => compact('id', 'register', 'schema')];
		if (($this->behaviour['findThrows'] ?? false) === true) {
			throw new RuntimeException('find failed');
		}

		return ($this->behaviour['cases'][$id] ?? null);
	}//end find()

	/**
	 * Update one object.
	 *
	 * @param string               $objectId      The id.
	 * @param array<string, mixed> $data          The object's complete new data.
	 * @param bool                 $_rbac         Apply register RBAC.
	 * @param bool                 $_multitenancy Apply organisation scoping.
	 *
	 * @return array<string, mixed>
	 */
	public function updateObject(string $objectId, array $data, bool $_rbac = true, bool $_multitenancy = true): array {
		$object = $data;
		if (is_string($object['activity'] ?? null) === true) {
			$object['activity'] = preg_replace('/"timestamp":"[^"]*"/', '"timestamp":"<now>"', $object['activity']);
		}

		$this->log[] = ['objects.updateObject' => ['id' => $objectId, 'object' => $object]];
		if (($this->behaviour['updateThrows'] ?? false) === true) {
			throw new RuntimeException('update failed');
		}

		return $object;
	}//end updateObject()
}//end class

/**
 * Golden test for CaseRebindService.
 *
 * Set GOLDEN_RECORD=1 to rewrite the golden file; a recording run compares
 * nothing, so run once more without it.
 *
 * @covers \OCA\Dossiq\Service\CaseRebindService
 * @covers \OCA\Dossiq\Service\Cases\RebindMove
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class CaseRebindServiceGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The calls each case makes: [method, named arguments, behaviour].
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private static function cases(): array {
		$case = ['id' => 'case-1', 'caseType' => ['id' => 'ct-a'], 'status' => 'st-a1', 'properties' => ['old' => 'x'], 'activity' => '[{"type":"created"}]'];
		$caseTypes = [
			'ct-a' => ['id' => 'ct-a', 'title' => ['nl' => 'Melding'], 'identifier' => 'melding'],
			'ct-b' => ['id' => 'ct-b', 'title' => 'Bezwaar', 'identifier' => 'bezwaar', 'version' => 2],
			'ct-c' => ['id' => 'ct-c', 'title' => 'Melding v2', 'identifier' => 'melding', 'version' => 3],
			'ct-d' => ['id' => 'ct-d', 'title' => 'Concept', 'identifier' => 'concept', 'isDraft' => true],
			'' => ['title' => 'No id'],
		];
		$statuses = [
			'ct-a' => [['id' => 'st-a1', 'name' => 'Nieuw'], ['id' => 'st-a2', 'name' => ['nl' => 'Klaar']]],
			'ct-b' => [['id' => 'st-b1', 'name' => 'Ontvangen'], ['name' => 'no id'], ['id' => 'st-b2']],
		];
		$impact = [
			'complete' => true,
			'dropped' => [['name' => 'old', 'value' => 'x']],
			'ported' => [['source' => 'kept', 'target' => 'moved', 'value' => 1, 'newValue' => '1']],
			'required' => [['name' => 'zeta', 'valid' => true], ['name' => 'alpha', 'valid' => true]],
		];
		$missing = ['complete' => false, 'required' => [['name' => 'zeta', 'valid' => false], ['name' => 'beta', 'valid' => true], ['name' => 'alpha', 'valid' => false]]] + $impact;
		$base = ['cases' => ['case-1' => $case], 'caseTypes' => $caseTypes, 'statuses' => $statuses, 'impact' => $impact,
			'templates' => ['ct-b' => [['id' => 'wf-1', 'version' => 4], ['id' => 'wf-2', 'version' => '7', 'isActive' => true]]],
		];
		$preview = ['caseId' => 'case-1', 'targetCaseTypeId' => 'ct-b', 'targetStatusId' => 'st-b1', 'remap' => ['old' => 'new'], 'properties' => ['zeta' => 'z']];
		$forged = ['actor' => 'mallory', 'actorUid' => 'mallory', 'userId' => 'mallory', 'requestedBy' => 'mallory', 'owner' => 'mallory'];
		$rebind = [
			'caseId' => 'case-1', 'targetCaseTypeId' => 'ct-b', 'targetStatusId' => 'st-b1', 'reason' => '  Wrong type  ',
			'properties' => ['zeta' => 'z'] + $forged, 'actorUid' => 'coord', 'remap' => ['old' => 'new', 'actor' => 'mallory'], 'confirmDropped' => ['old'],
		];
		$asArray = static fn (array $c): array => ['cases' => ['case-1' => $c]] + $base;

		return [
			'options / targets' => ['options', ['caseId' => 'case-1'], $base],
			'options / own type without identifier' => ['options', ['caseId' => 'case-1'], ['caseTypes' => ['ct-a' => ['id' => 'ct-a', 'title' => 'A']] + $caseTypes] + $base],
			'options / no status' => ['options', ['caseId' => 'case-1'], $asArray(['status' => ''] + $case)],
			'options / unknown status' => ['options', ['caseId' => 'case-1'], $asArray(['status' => 'st-zz'] + $case)],
			'options / no case type' => ['options', ['caseId' => 'case-1'], $asArray(['caseType' => ''] + $case)],
			'options / case missing' => ['options', ['caseId' => 'case-9'], $base],
			'options / find throws' => ['options', ['caseId' => 'case-1'], ['findThrows' => true] + $base],
			'options / no object service' => ['options', ['caseId' => 'case-1'], ['noObjectService' => true] + $base],
			'options / no register' => ['options', ['caseId' => 'case-1'], ['config' => ['register' => '']] + $base],
			'options / no case schema' => ['options', ['caseId' => 'case-1'], ['config' => ['case_schema' => '']] + $base],
			'preview / complete' => ['preview', $preview, $base],
			'preview / no status chosen' => ['preview', ['targetStatusId' => ''] + $preview, $base],
			'preview / incomplete' => ['preview', $preview, ['impact' => $missing] + $base],
			'preview / defaults' => ['preview', ['caseId' => 'case-1', 'targetCaseTypeId' => 'ct-b', 'targetStatusId' => 'st-b1'], $base],
			'preview / target refused' => ['preview', $preview, ['refuse' => 'assertTarget'] + $base],
			'preview / case missing' => ['preview', ['caseId' => 'case-9'] + $preview, $base],
			'rebind / happy path' => ['rebind', $rebind, $base],
			'rebind / array activity' => ['rebind', $rebind, $asArray(['activity' => [['type' => 'created']]] + $case)],
			'rebind / no activity' => ['rebind', $rebind, $asArray(['activity' => null] + $case)],
			'rebind / blank activity' => ['rebind', $rebind, $asArray(['activity' => '  '] + $case)],
			'rebind / broken activity' => ['rebind', $rebind, $asArray(['activity' => '{oops'] + $case)],
			'rebind / scalar activity' => ['rebind', $rebind, $asArray(['activity' => '"text"'] + $case)],
			'rebind / two active templates' => ['rebind', $rebind, ['templates' => ['ct-b' => [['id' => 'wf-5', 'isActive' => true], ['id' => 'wf-6', 'isActive' => true]]]] + $base],
			'rebind / no template' => ['rebind', $rebind, ['templates' => []] + $base],
			'rebind / no active template' => ['rebind', $rebind, ['templates' => ['ct-b' => [['id' => 'wf-1'], ['id' => 'wf-3', 'version' => 5]]]] + $base],
			'rebind / defaults' => ['rebind', ['caseId' => 'case-1', 'targetCaseTypeId' => 'ct-b', 'targetStatusId' => 'st-b1', 'reason' => 'r', 'properties' => [], 'actorUid' => 'coord'], $base],
			'rebind / another actor' => ['rebind', ['actorUid' => 'mallory'] + $rebind, $base],
			'rebind / empty actor' => ['rebind', ['actorUid' => ''] + $rebind, $base],
			'rebind / no reason' => ['rebind', ['reason' => ' '] + $rebind, $base],
			'rebind / case missing' => ['rebind', ['caseId' => 'case-9'] + $rebind, $base],
			'rebind / target refused' => ['rebind', $rebind, ['refuse' => 'assertTarget'] + $base],
			'rebind / status refused' => ['rebind', $rebind, ['refuse' => 'assertStatus'] + $base],
			'rebind / drop unconfirmed' => ['rebind', $rebind, ['refuse' => 'assertDropConfirmed'] + $base],
			'rebind / something missing' => ['rebind', $rebind, ['impact' => $missing, 'refuse' => 'assertNothingMissing'] + $base],
			'rebind / engine refuses' => ['rebind', $rebind, ['refuse' => 'migrate'] + $base],
			'rebind / run not moved' => ['rebind', $rebind, ['run' => ['migrated' => false, 'reason' => 'no-run']] + $base],
			'rebind / update throws' => ['rebind', $rebind, ['updateThrows' => true] + $base],
			'rebind / terms throw' => ['rebind', $rebind, ['refuse' => 'rearm'] + $base],
			'may / coordinator' => ['mayRebind', ['uid' => 'coord'], $base],
			'may / other' => ['mayRebind', ['uid' => 'mallory'], $base],
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
			} catch (RefusedException $e) {
				$answer = ['refused' => $e->getRule(), 'sentence' => $e->getSentence(), 'status' => $e->getStatus()];
			} catch (Throwable $e) {
				$answer = ['threw' => get_class($e), 'message' => $e->getMessage()];
			}

			$actual[$name] = ['answer' => $answer, 'calls' => $log];
		}

		$this->assertMatchesGolden(name: 'case-rebind-service', value: $actual);
	}//end testEveryCaseMatchesTheGoldenRecord()

	/**
	 * The acting user reaches the gate, the engine, the journal and the log; forged fields do not.
	 *
	 * @return void
	 */
	public function testTheActingUserIsTheOneEveryStepSees(): void {
		$cases = self::cases();
		[, $args, $behaviour] = $cases['rebind / happy path'];
		$log = [];
		$answer = $this->service(behaviour: $behaviour, log: $log)->rebind(...$args);

		self::assertTrue(condition: $answer['rebound']);
		$actors = [];
		foreach ($log as $entry) {
			$call = (is_array($entry) === true) ? (array)reset($entry) : [];
			foreach (['actorUid', 'uid'] as $key) {
				if (array_key_exists($key, $call) === true) {
					$actors[] = $call[$key];
				}
			}

			if (is_array($entry) === true && isset($entry['objects.updateObject']) === true) {
				$journal = json_decode($entry['objects.updateObject']['object']['activity'], true);
				$actors[] = end($journal)['actor'];
			}

			if (is_array($entry) === true && isset($entry['log.info']) === true) {
				$actors[] = $entry['log.info']['context']['actor'];
			}
		}

		self::assertSame(expected: ['coord', 'coord', 'coord', 'coord'], actual: $actors);

		$refused = $this->service(behaviour: $behaviour, log: $log)->mayRebind(uid: 'mallory');
		self::assertFalse(condition: $refused);
	}//end testTheActingUserIsTheOneEveryStepSees()

	/**
	 * The service over recording doubles.
	 *
	 * @param array<string, mixed> $behaviour How the collaborators answer.
	 * @param array<int, mixed>    $log       The shared call log.
	 *
	 * @return CaseRebindService
	 */
	private function service(array $behaviour, array &$log): CaseRebindService {
		$config = array_merge(['register' => 'dossiq', 'case_schema' => 'case'], ($behaviour['config'] ?? []));
		$objects = new GoldenRebindObjects(behaviour: $behaviour, log: $log);
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

		$refuse = static function (string $method) use ($behaviour): void {
			if (($behaviour['refuse'] ?? '') === $method) {
				throw new RefusedException(rule: 'refused-' . $method, sentence: $method . ' refused');
			}
		};

		$store = $this->recording(class: CaseTypeStore::class, log: $log, answers: [
			'referenceId' => static fn (mixed $value): string => is_array($value) ? (string)($value['id'] ?? '') : (string)$value,
			'everyCaseType' => static fn (mixed ...$args): array => array_values($behaviour['caseTypes']),
			'rowId' => static fn (array $row): string => (string)($row['id'] ?? ''),
			'readCaseType' => static fn (string $caseTypeId): array => ($behaviour['caseTypes'][$caseTypeId] ?? []),
			'asRow' => static fn (mixed $value): array => is_array($value) ? $value : [],
			'rowsOfType' => static fn (string $schemaKey, string $caseTypeId): array => ($behaviour['templates'][$caseTypeId] ?? []),
		]);
		$resolver = $this->recording(class: CaseTypeResolver::class, log: $log, answers: [
			'statusTypesFor' => static fn (string $caseTypeId): array => ($behaviour['statuses'][$caseTypeId] ?? []),
		]);
		$gate = $this->recording(class: CaseRebindGate::class, log: $log, answers: [
			'mayRebind' => static fn (string $uid): bool => $uid === 'coord',
			'assertMayRebind' => static function (string $actorUid, string $reason): string {
				if ($actorUid !== 'coord') {
					throw new RefusedException(rule: 'not-a-coordinator', sentence: 'Only a coordinator may rebind.', status: RefusedException::STATUS_FORBIDDEN);
				}

				if (trim($reason) === '') {
					throw new RefusedException(rule: 'reason-required', sentence: 'A reason is required.');
				}

				return trim($reason);
			},
			'assertTarget' => static fn (mixed ...$args) => $refuse('assertTarget'),
			'assertStatus' => static fn (mixed ...$args) => $refuse('assertStatus'),
			'assertDropConfirmed' => static fn (mixed ...$args) => $refuse('assertDropConfirmed'),
			'assertNothingMissing' => static fn (mixed ...$args) => $refuse('assertNothingMissing'),
			'resultCompatibility' => static fn (array $case, string $targetCaseTypeId): array => ['compatible' => $targetCaseTypeId],
		]);
		$impact = $this->recording(class: CaseRebindImpact::class, log: $log, answers: [
			'compute' => static fn (mixed ...$args): array => $behaviour['impact'],
			'apply' => static fn (array $case, array $impact): array => ['properties' => ['applied' => true]] + $case,
		]);
		$engine = $this->recording(class: EngineRunMigration::class, log: $log, answers: [
			'migrate' => static function (mixed ...$args) use ($refuse, $behaviour): array {
				$refuse('migrate');
				return ($behaviour['run'] ?? ['migrated' => true, 'reason' => 'moved']);
			},
		]);
		$terms = $this->recording(class: CaseRebindTerms::class, log: $log, answers: [
			'rearm' => static function (mixed ...$args) use ($refuse): array {
				$refuse('rearm');
				return ['rearmed' => 1];
			},
		]);
		$text = $this->recording(class: TranslatedText::class, log: $log, answers: [
			'forReader' => static fn (mixed $value): string => is_array($value) ? 'tr:' . json_encode($value) : (string)$value,
		]);

		return new CaseRebindService(
			settingsService: $settings,
			store: $store,
			resolver: $resolver,
			engine: $engine,
			terms: $terms,
			gate: $gate,
			impact: $impact,
			logger: $this->logger(log: $log),
			text: $text,
		);
	}//end service()

	/**
	 * A double whose every answered method logs its arguments by parameter name.
	 *
	 * Arguments left at their declared default are not logged, so a call that
	 * spells a default out reads the same as one that leaves it off.
	 *
	 * @param string                  $class   The class to double.
	 * @param array<int, mixed>       $log     The shared call log.
	 * @param array<string, callable> $answers Method name => answer.
	 *
	 * @return mixed
	 */
	private function recording(string $class, array &$log, array $answers): mixed {
		$double = $this->getMockBuilder(className: $class)->disableOriginalConstructor()->onlyMethods(array_keys($answers))->getMock();
		$short = lcfirst(substr(strrchr($class, '\\'), 1));
		foreach ($answers as $method => $answer) {
			$params = (new ReflectionMethod($class, $method))->getParameters();
			$double->method($method)->willReturnCallback(
				static function (...$args) use ($params, $answer, $short, $method, &$log): mixed {
					$named = [];
					foreach ($params as $i => $param) {
						if (array_key_exists($i, $args) === false) {
							continue;
						}

						if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$i]) {
							continue;
						}

						$named[$param->getName()] = $args[$i];
					}

					$log[] = [$short . '.' . $method => $named];

					return $answer(...$named);
				}
			);
		}

		return $double;
	}//end recording()

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
