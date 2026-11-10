<?php

/**
 * Objection DecisionService golden test
 *
 * Pins what drafting, publishing (raising the decision with the deciding
 * app and recording its reference), applying a decision to its objection
 * and the case lookups read, write, log and answer.
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
 * @spec openspec/specs/bezwaar-decision/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\DecisionService;
use OCA\Dossiq\Service\Bezwaar\DecisionValidator;
use OCA\Dossiq\Service\BezwaarDecisionDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Object\ObjectPatch;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden test for the objection DecisionService.
 *
 * @covers \OCA\Dossiq\Service\Bezwaar\DecisionService
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class DecisionServiceGoldenTest extends TestCase {
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
		$config = ($world['config'] ?? []) + ['register' => 'reg', 'bezwaar_decision_schema' => 'dec', 'bezwaar_schema' => 'obj'];
		$objects = ($world['objects'] ?? []);
		$entity = fn (mixed $value): mixed => (is_array($value) === true || is_string($value) === true ? $this->entity(value: $value) : $value);
		$objectService = $this->double(
			ObjectServiceInterface::class,
			[
				'find' => ($objects['find'] ?? null) === 'throw' ? 'throw' : static fn (int|string $id, mixed ...$rest): mixed => $entity(($objects['find'] ?? [])[$id] ?? null),
				'saveObject' => ($objects['save'] ?? null) === 'throw' ? 'throw' : static fn (array $object, mixed ...$rest): mixed => $entity(($objects['save'] ?? null) ?? ($object + ['id' => 'dec-new'])),
				'patchObject' => ($objects['patch'] ?? null) === 'throw' ? 'throw' : static fn (ObjectPatch $patch, mixed ...$rest): mixed => $entity(($objects['patch'] ?? null) ?? (['id' => $patch->objectId, 'patched' => true] + $patch->data)),
			]
		);
		$settings = $this->double(
			SettingsService::class,
			[
				'getObjectService' => ($world['noService'] ?? false) === true ? null : $objectService,
				'getConfigValue' => static fn (string $key, string $default = ''): string => (string)($config[$key] ?? $default),
			]
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if (array_key_exists('actor', $world) === false || $world['actor'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($world['actor'] ?? 'jurist-1');
		}

		$session->method('getUser')->willReturn($user);

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

		$raise = ($world['raise'] ?? 'decision-ref-1');
		if ($raise === 'other') {
			$raise = static function (mixed ...$args): string {
				throw new \LogicException('not a runtime failure');
			};
		}

		$service = new DecisionService(
			settingsService: $settings,
			userSession: $session,
			transitions: $this->double(StatusTransitionService::class, ['execute' => ($world['transition'] ?? [])]),
			logger: $logger,
			decisionDelegation: $this->double(BezwaarDecisionDelegationService::class, ['raiseBezwaarDecision' => $raise]),
			validator: $this->double(
				DecisionValidator::class,
				[
					'assertDraftable' => ($world['draftable'] ?? null),
					'assertPublishable' => ($world['publishable'] ?? null),
					'computeProceskostenTotal' => ($world['total'] ?? null),
				]
			),
		);

		try {
			$answer = ($world['call'])($service);
		} catch (\Throwable $thrown) {
			$answer = [
				'thrown' => get_class($thrown),
				'message' => $thrown->getMessage(),
				'code' => $thrown->getCode(),
				'previous' => ($thrown->getPrevious()?->getMessage()),
			];
		}

		return json_decode((string)json_encode(['answer' => $answer, 'calls' => $this->calls, 'logs' => $logger->lines], JSON_PRESERVE_ZERO_FRACTION), true);
	}//end observe()

	/**
	 * Every operation across its guards and its collaborators' answers.
	 *
	 * @return void
	 */
	public function testGoldenDecisionService(): void {
		$draft = static fn (array $payload = ['dispositionType' => 'dismissed', 'reasoning' => 'r']): \Closure => static fn (DecisionService $s): array => $s->draft(objectionId: 'obj-1', payload: $payload);
		$publish = static fn (string $id = 'dec-1'): \Closure => static fn (DecisionService $s): array => $s->publish(decisionId: $id);
		$apply = static fn (string $objection = 'obj-1'): \Closure => static function (DecisionService $s) use ($objection): string {
			$s->applyToBezwaar(objectionId: $objection, decisionId: 'dec-1');
			return 'done';
		};
		$forObjection = static fn (string $id): \Closure => static fn (DecisionService $s): ?string => $s->caseIdForObjection(objectionId: $id);
		$forDecision = static fn (string $id): \Closure => static fn (DecisionService $s): ?string => $s->caseIdForDecision(decisionId: $id);
		$decision = [
			'id' => 'dec-1',
			'bezwaar' => 'obj-1',
			'title' => 'Beslissing op bezwaar',
			'dispositionType' => 'upheld_revoke',
			'reasoning' => 'Gegrond',
			'legalBasis' => 'Awb 7:11',
			'replacementDecision' => 'besluit-2',
			'bezwaarmaker' => 'piet',
			'authorisedRepresentative' => 'advocaat',
			'primairBeslisser' => 'team-a',
			'advisoryOpinion' => 'bac-1',
		];
		$objection = ['id' => 'obj-1', 'case' => ' case-1 '];

		$worlds = [
			'draft' => ['call' => $draft()],
			'draft, owned fields dropped and link forced' => ['call' => $draft(['dispositionType' => 'dismissed', 'bezwaar' => 'evil', 'status' => 'final', 'publishedAt' => 'x', 'notifiedRecipients' => ['y'], 'note' => 'n'])],
			'draft, save answers a scalar' => ['objects' => ['save' => 'odd'], 'call' => $draft()],
			'draft, save fails' => ['objects' => ['save' => 'throw'], 'call' => $draft()],
			'draft, refused by the validator' => ['draftable' => 'throw', 'call' => $draft()],
			'draft, no object service' => ['noService' => true, 'call' => $draft()],
			'draft, no register' => ['config' => ['register' => ''], 'call' => $draft()],
			'draft, no decision schema' => ['config' => ['bezwaar_decision_schema' => ''], 'call' => $draft()],
			'publish' => ['objects' => ['find' => ['dec-1' => $decision]], 'call' => $publish()],
			'publish, with proceskosten' => ['objects' => ['find' => ['dec-1' => $decision + ['legalCostsCompensation' => ['awarded' => true, 'awardedPoints' => 2]]]], 'total' => 1250.0, 'call' => $publish()],
			'publish, a total without an earlier block' => ['objects' => ['find' => ['dec-1' => $decision]], 'total' => 0.0, 'call' => $publish()],
			'publish, a scalar cost block' => ['objects' => ['find' => ['dec-1' => $decision + ['legalCostsCompensation' => 'x']]], 'total' => 5.5, 'call' => $publish()],
			'publish, no objection on the decision' => ['objects' => ['find' => ['dec-1' => array_diff_key($decision, ['bezwaar' => 1])]], 'call' => $publish()],
			'publish, the subject label from onderwerp' => ['objects' => ['find' => ['dec-1' => ['onderwerp' => 'Onderwerp', 'bezwaar' => 'obj-1']]], 'call' => $publish()],
			'publish, a bare decision, no session user' => ['objects' => ['find' => ['dec-1' => ['id' => 'dec-1']]], 'actor' => null, 'call' => $publish()],
			'publish, the same recipient twice' => ['objects' => ['find' => ['dec-1' => ['bezwaarmaker' => 'x', 'authorisedRepresentative' => 'x', 'primairBeslisser' => '', 'advisoryOpinion' => '']]], 'actor' => 'x', 'call' => $publish()],
			'publish, not found' => ['objects' => ['find' => []], 'call' => $publish()],
			'publish, read fails' => ['objects' => ['find' => 'throw'], 'call' => $publish()],
			'publish, read answers a scalar' => ['objects' => ['find' => ['dec-1' => 'plain']], 'call' => $publish()],
			'publish, refused by the validator' => ['objects' => ['find' => ['dec-1' => $decision]], 'publishable' => 'throw', 'call' => $publish()],
			'publish, the deciding app is unavailable' => ['objects' => ['find' => ['dec-1' => $decision]], 'raise' => 'throw', 'call' => $publish()],
			'publish, the deciding app fails otherwise' => ['objects' => ['find' => ['dec-1' => $decision]], 'raise' => 'other', 'call' => $publish()],
			'publish, the reference cannot be recorded' => ['objects' => ['find' => ['dec-1' => $decision], 'patch' => 'throw'], 'call' => $publish()],
			'publish, the patch answers a scalar' => ['objects' => ['find' => ['dec-1' => $decision], 'patch' => 'odd'], 'total' => 3.0, 'call' => $publish()],
			'publish, no object service' => ['noService' => true, 'call' => $publish()],
			'publish, no decision schema' => ['config' => ['bezwaar_decision_schema' => ''], 'call' => $publish()],
			'publish, no register' => ['config' => ['register' => ''], 'call' => $publish()],
			'apply' => ['objects' => ['find' => ['obj-1' => $objection]], 'call' => $apply()],
			'apply, objection without a case' => ['objects' => ['find' => ['obj-1' => ['id' => 'obj-1']]], 'call' => $apply()],
			'apply, objection missing' => ['objects' => ['find' => []], 'call' => $apply()],
			'apply, transition fails' => ['objects' => ['find' => ['obj-1' => $objection]], 'transition' => 'throw', 'call' => $apply()],
			'apply, no object service' => ['noService' => true, 'call' => $apply()],
			'apply, no objection schema' => ['config' => ['bezwaar_schema' => ''], 'call' => $apply()],
			'apply, no register' => ['config' => ['register' => ''], 'call' => $apply()],
			'case of an objection' => ['objects' => ['find' => ['obj-1' => $objection]], 'call' => $forObjection(' obj-1 ')],
			'case of an objection, blank id' => ['call' => $forObjection('  ')],
			'case of an objection, none' => ['objects' => ['find' => ['obj-1' => ['case' => '  ']]], 'call' => $forObjection('obj-1')],
			'case of an objection, missing' => ['objects' => ['find' => []], 'call' => $forObjection('obj-1')],
			'case of an objection, no schema' => ['config' => ['bezwaar_schema' => ''], 'call' => $forObjection('obj-1')],
			'case of an objection, no object service' => ['noService' => true, 'call' => $forObjection('obj-1')],
			'case of a decision' => ['objects' => ['find' => ['dec-1' => $decision, 'obj-1' => $objection]], 'call' => $forDecision(' dec-1')],
			'case of a decision, blank id' => ['call' => $forDecision('')],
			'case of a decision, missing' => ['objects' => ['find' => []], 'call' => $forDecision('dec-1')],
			'case of a decision, no schema' => ['config' => ['bezwaar_decision_schema' => ''], 'call' => $forDecision('dec-1')],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'objection-decision-service', value: $observed);
	}//end testGoldenDecisionService()
}//end class
