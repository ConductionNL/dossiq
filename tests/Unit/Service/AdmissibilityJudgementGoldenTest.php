<?php

/**
 * AdmissibilityJudgement golden test
 *
 * Pins what recording an admissibility verdict reads, writes, closes, sends,
 * logs and answers, across its refusals, the declaration forms and the
 * collaborators' answers.
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
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Intake\AdmissibilityJudgement;
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\Notification\ApplicantMessage;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden test for AdmissibilityJudgement::judge().
 *
 * @covers \OCA\Dossiq\Service\Intake\AdmissibilityJudgement
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Support\StoredArray
 */
class AdmissibilityJudgementGoldenTest extends TestCase {
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
	 * Arguments left at their declared default are not recorded.
	 *
	 * @param string               $class     The collaborator class.
	 * @param array<string, mixed> $behaviour Per method: a value, or a closure over the named args.
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

						$named[$param->getName()] = (is_object($value) === true ? 'object' : $value);
					}

					$short = substr($class, (strrpos($class, '\\') + 1));
					$this->calls[] = [$short . '::' . $method, $named];
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
	 * Run one verdict against a world and record every effect.
	 *
	 * @param array<string, mixed> $world The answers per collaborator and the verdict.
	 *
	 * @return array<string, mixed> The answer or exception, the calls and the log lines.
	 */
	private function observe(array $world): array {
		$this->calls = [];
		$logger = new class extends AbstractLogger {
			/**
			 * The lines logged.
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

		$failing = static function (string $what) use ($world): \Closure {
			return static function (mixed ...$named) use ($world, $what): mixed {
				if (($world['fails'] ?? '') === $what) {
					throw new RuntimeException($what . ' failed');
				}

				if ($what === 'save') {
					return $named['case'];
				}

				return ($world[$what] ?? []);
			};
		};
		$caseType = ($world['caseType'] ?? ['id' => 'ct-1', AdmissibilityJudgement::DECLARATION => ['enabled' => true, 'inadmissibleResultType' => 'rt-niet']]);
		$service = new AdmissibilityJudgement(
			caseTypes: $this->double(
				CaseTypeResolver::class,
				[
					'effectiveCaseType' => static function (mixed ...$named) use ($world, $caseType): array {
						if (($world['fails'] ?? '') === 'caseType') {
							throw new RuntimeException('case type unreadable');
						}

						return $caseType;
					},
				]
			),
			store: $this->double(
				CaseStatusStore::class,
				[
					'loadCase' => (array_key_exists('case', $world) === true ? $world['case'] : ['id' => 'case-1', 'caseType' => ' ct-1 ', 'title' => 'Aanvraag']),
					'saveCase' => $failing('save'),
				]
			),
			endings: $this->double(CaseEndingActs::class, ['finish' => $failing('finish')]),
			letters: $this->double(
				ApplicantMessage::class,
				[
					'declares' => true,
					'send' => ($world['send'] ?? ['sent' => true, 'channel' => 'portal']),
				]
			),
			logger: $logger,
			l10n: $this->double(IL10N::class, ['t' => static fn (string $text, mixed $parameters = []): string => $text]),
		);

		try {
			$answer = $service->judge(
				caseId: ($world['caseId'] ?? 'case-1'),
				verdict: ($world['verdict'] ?? 'inadmissible'),
				reason: ($world['reason'] ?? ' Te laat ingediend '),
				judgedBy: ($world['judgedBy'] ?? 'anna'),
			);
		} catch (\Throwable $thrown) {
			$answer = ['thrown' => get_class($thrown), 'message' => $thrown->getMessage()];
			if ($thrown instanceof RefusedException) {
				$answer += ['rule' => $thrown->getRule(), 'status' => $thrown->getStatus(), 'sentence' => $thrown->getSentence()];
			}
		}

		$json = (string)json_encode(['answer' => $answer, 'calls' => $this->calls, 'logs' => $logger->lines], JSON_PRESERVE_ZERO_FRACTION);
		$json = (string)preg_replace('/"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d"/', '"<now>"', $json);

		return json_decode($json, true);
	}//end observe()

	/**
	 * Every path through judge(), across its refusals and its collaborators' answers.
	 *
	 * @return void
	 */
	public function testGoldenJudge(): void {
		$on = static fn (array $declaration): array => ['id' => 'ct-1', AdmissibilityJudgement::DECLARATION => $declaration];
		$worlds = [
			'unknown verdict' => ['verdict' => 'maybe'],
			'verdict differs in case' => ['verdict' => 'Inadmissible'],
			'verdict with spaces' => ['verdict' => ' inadmissible '],
			'empty reason' => ['reason' => ''],
			'blank reason' => ['reason' => "  \t"],
			'unknown verdict and no reason' => ['verdict' => 'x', 'reason' => ''],
			'case not found' => ['case' => null],
			'case without case type' => ['case' => ['id' => 'case-1']],
			'case type unreadable' => ['fails' => 'caseType'],
			'declaration absent' => ['caseType' => ['id' => 'ct-1']],
			'declaration disabled' => ['caseType' => $on(['enabled' => false, 'inadmissibleResultType' => 'rt'])],
			'declaration enabled as text' => ['caseType' => $on(['enabled' => 'true', 'inadmissibleResultType' => 'rt'])],
			'declaration as JSON' => ['caseType' => ['id' => 'ct-1', AdmissibilityJudgement::DECLARATION => '{"enabled":true,"inadmissibleResultType":" rt-json ","moment":" own-moment "}']],
			'declaration as broken JSON' => ['caseType' => ['id' => 'ct-1', AdmissibilityJudgement::DECLARATION => '{nope']],
			'declaration as JSON scalar' => ['caseType' => ['id' => 'ct-1', AdmissibilityJudgement::DECLARATION => '1']],
			'declaration a number' => ['caseType' => ['id' => 'ct-1', AdmissibilityJudgement::DECLARATION => 7]],
			'admissible' => ['verdict' => 'admissible'],
			'admissible without result type' => ['verdict' => 'admissible', 'caseType' => $on(['enabled' => true])],
			'admissible, save fails' => ['verdict' => 'admissible', 'fails' => 'save'],
			'inadmissible without result type' => ['caseType' => $on(['enabled' => true, 'inadmissibleResultType' => '  '])],
			'inadmissible' => ['finish' => ['closed' => true, 'status' => 'afgehandeld']],
			'inadmissible, declared moment' => ['caseType' => $on(['enabled' => true, 'inadmissibleResultType' => 'rt', 'moment' => 'told-later'])],
			'inadmissible, blank moment' => ['caseType' => $on(['enabled' => true, 'inadmissibleResultType' => 'rt', 'moment' => ' '])],
			'inadmissible, letter not sent' => ['send' => ['sent' => false, 'reason' => 'no-address']],
			'inadmissible, close fails' => ['fails' => 'finish'],
			'inadmissible, save fails' => ['fails' => 'save'],
			'inadmissible, judge empty' => ['judgedBy' => ''],
			'case names another person' => [
				'case' => ['id' => 'case-1', 'caseType' => ' ct-1 ', 'owner' => 'bob', 'assignee' => 'bob', 'judgedBy' => 'bob'],
				'judgedBy' => 'anna',
			],
			'another case' => [
				'caseId' => 'case-2',
				'case' => ['id' => 'case-2', 'caseType' => ' ct-1 ', 'title' => 'Aanvraag'],
				'finish' => ['closed' => true, 'status' => 'afgehandeld'],
			],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'admissibility-judgement', value: $observed);
	}//end testGoldenJudge()
}//end class
