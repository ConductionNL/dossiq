<?php

/**
 * FormsIntakeService golden test
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
 * @spec openspec/specs/leaf-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FormsIntakeService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden test for FormsIntakeService::caseFor(): the answer, every store and settings call, and the logs.
 *
 * @covers \OCA\Dossiq\Service\FormsIntakeService
 * @uses \OCA\Dossiq\Service\Intake\IntakeFormBinding
 */
class FormsIntakeServiceGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Run one submission in a world and observe it.
	 *
	 * @param array<string, mixed> $world The settings, the store, the submitter and the submission.
	 *
	 * @return array<string, mixed> The answer, the calls in order and the log lines.
	 */
	private function observe(array $world): array {
		$calls = [];
		$logger = new class extends AbstractLogger {
			/**
			 * The log lines.
			 *
			 * @var array<int, mixed>
			 */
			public array $lines = [];

			/**
			 * Keep one log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, $context];
			}
		};

		$store = new class($world, $calls) {
			/**
			 * @param array<string, mixed> $world The world.
			 * @param array<int, mixed>    $calls The call log.
			 */
			public function __construct(private array $world, private array &$calls) {
			}

			/**
			 * Answer the case type lookup.
			 *
			 * @param array<string, mixed> $params The query.
			 *
			 * @return mixed The rows.
			 */
			public function findAll(array $params = []): mixed {
				$this->calls[] = ['findAll', $params];
				if (($this->world['find'] ?? null) === 'throw') {
					throw new RuntimeException('store down');
				}

				return ($this->world['find'] ?? []);
			}

			/**
			 * Record a plain save.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return mixed The saved row.
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): mixed {
				$this->calls[] = ['saveObject', $object, $register, $schema];
				if (($this->world['save'] ?? null) === 'throw') {
					throw new RuntimeException('save failed');
				}

				return ($this->world['save'] ?? ['id' => 'case-saved']);
			}
		};

		$submitter = null;
		if (array_key_exists('submit', $world) === true) {
			$submitter = $this->submitterFor(answer: $world['submit'], calls: $calls);
		}

		if (($world['submitter'] ?? null) === 'no submit method') {
			$submitter = new \stdClass();
		}

		$config = ($world['config'] ?? []) + ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType'];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(
			static function () use (&$calls, $world, $store): ?object {
				$calls[] = ['getObjectService'];
				$asked = count(array_filter($calls, static fn (array $call): bool => $call[0] === 'getObjectService'));
				$none = ($world['noStore'] ?? false);
				if ($none === true || ($none === 'second' && $asked === 2)) {
					return null;
				}

				return $store;
			}
		);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use (&$calls, $config, $world): string {
				$calls[] = ['getConfigValue', $key];
				$asked = count(array_filter($calls, static fn (array $call): bool => $call === ['getConfigValue', $key]));
				if ($asked === 2 && array_key_exists($key, ($world['configSecond'] ?? [])) === true) {
					return ($world['configSecond'][$key] ?? $default);
				}

				return ($config[$key] ?? $default);
			}
		);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static function (string $class) use (&$calls, $submitter): ?object {
				$calls[] = ['getOpenRegisterClass', $class];
				return $submitter;
			}
		);

		$service = new FormsIntakeService(settingsService: $settings, logger: $logger);
		$answer = $service->caseFor(formHash: $world['form'], answers: ($world['answers'] ?? ['Vraag' => 'Antwoord']));

		return ['answer' => $answer, 'calls' => $calls, 'logs' => $logger->lines];
	}//end observe()

	/**
	 * A form submit double that records its call and answers or throws.
	 *
	 * @param mixed             $answer What submit() answers, or 'throw' / 'throw findings' / 'throw bad findings'.
	 * @param array<int, mixed> $calls  The call log.
	 *
	 * @return object The submitter.
	 */
	private function submitterFor(mixed $answer, array &$calls): object {
		return new class($answer, $calls) {
			/**
			 * @param mixed             $answer The answer.
			 * @param array<int, mixed> $calls  The call log.
			 */
			public function __construct(private mixed $answer, private array &$calls) {
			}

			/**
			 * Record a submit.
			 *
			 * @param array<string, string>     $destination    The destination.
			 * @param array<string, mixed>|null $mapping        The mapping.
			 * @param array<string, mixed>      $payload        The answers.
			 * @param mixed                     $subject        The subject.
			 * @param string|null               $idempotencyKey The idempotency key.
			 * @param string                    $scope          The scope.
			 *
			 * @return mixed The answer.
			 */
			public function submit(
				array $destination,
				?array $mapping,
				array $payload,
				mixed $subject = null,
				?string $idempotencyKey = null,
				string $scope = ''
			): mixed {
				$this->calls[] = ['submit', $destination, $mapping, $payload, $scope];
				if ($this->answer === 'throw') {
					throw new RuntimeException('submit down');
				}

				if ($this->answer === 'throw findings' || $this->answer === 'throw bad findings') {
					$findings = 'not a list';
					if ($this->answer === 'throw findings') {
						$findings = ['a' => ['property' => 'title', 'code' => 'required']];
					}

					throw new class('refused', $findings) extends RuntimeException {
						/**
						 * @param string $message  The message.
						 * @param mixed  $findings The findings.
						 */
						public function __construct(string $message, private mixed $findings) {
							parent::__construct(message: $message);
						}

						/**
						 * The findings.
						 *
						 * @return mixed The findings.
						 */
						public function getFindings(): mixed {
							return $this->findings;
						}
					};
				}

				return $this->answer;
			}
		};
	}//end submitterFor()

	/**
	 * Every path through caseFor().
	 *
	 * @return void
	 */
	public function testGoldenCaseFor(): void {
		$type = [
			'id' => 'type-1',
			'title' => 'Melding',
			'intakeFormRef' => 'form-abc',
			'intakeFormMapping' => ['fields' => [['field' => 'Vraag', 'property' => 'title', 'type' => 'text']], 'fixed' => ['priority' => 'normal']],
		];
		$other = ['id' => 'type-2', 'intakeFormRef' => 'form-other'];
		$jsonRow = new class($type) implements \JsonSerializable {
			/**
			 * @param array<string, mixed> $row The row.
			 */
			public function __construct(private array $row) {
			}

			/**
			 * The row.
			 *
			 * @return mixed The row.
			 */
			public function jsonSerialize(): mixed {
				return $this->row;
			}
		};
		$bound = ['form' => 'form-abc', 'find' => [$type]];
		$worlds = [
			'empty form hash' => ['form' => ''],
			'blank form hash' => ['form' => "  \t"],
			'unbound form' => ['form' => 'form-lunch', 'find' => [$other]],
			'store ignores the filter' => ['form' => 'form-abc', 'find' => [$other, $type]],
			'form hash trimmed' => ['form' => ' form-abc '] + $bound,
			'ref trimmed on the row' => ['form' => 'form-abc', 'find' => [['intakeFormRef' => ' form-abc '] + $type]],
			'no store at lookup' => ['noStore' => true] + $bound,
			'no store after the lookup' => ['noStore' => 'second'] + $bound,
			'no case type schema' => ['config' => ['case_type_schema' => '']] + $bound,
			'no register' => ['config' => ['register' => '']] + $bound,
			'register blank after the lookup' => ['configSecond' => ['register' => '']] + $bound,
			'register unset after the lookup' => ['configSecond' => ['register' => null]] + $bound,
			'case schema unset' => ['config' => ['case_schema' => null]] + $bound,
			'type without a mapping' => [
				'form' => 'form-abc',
				'find' => [['id' => 'type-3', 'intakeFormRef' => 'form-abc']],
				'answers' => ['Onderwerp' => 'Lekkage', 'Toelichting' => 'Kelder'],
			],
			'no case schema' => ['config' => ['case_schema' => '']] + $bound,
			'lookup throws' => ['form' => 'form-abc', 'find' => 'throw'],
			'results wrapper' => ['form' => 'form-abc', 'find' => ['results' => [$type], 'total' => 1]],
			'results not a list' => ['form' => 'form-abc', 'find' => ['results' => 'x', $type]],
			'store answers no array' => ['form' => 'form-abc', 'find' => 'nothing'],
			'json row' => ['form' => 'form-abc', 'find' => [$jsonRow]],
			'scalar row' => ['form' => 'form-abc', 'find' => [7, $type]],
			'plain save, id' => $bound + ['answers' => ['Vraag' => 'Lekkage', 'Extra' => 'x']],
			'plain save, uuid wins over id' => $bound + ['save' => ['uuid' => ' u-1 ', 'id' => 'i-1']],
			'plain save, blank uuid' => $bound + ['save' => ['uuid' => ' ', 'id' => 'i-2']],
			'plain save, @self uuid' => $bound + ['save' => ['@self' => ['uuid' => 's-u', 'id' => 's-i']]],
			'plain save, @self id' => $bound + ['save' => ['@self' => ['id' => 's-i']]],
			'plain save, @self not an array' => $bound + ['save' => ['@self' => 'x']],
			'plain save, no id' => $bound + ['save' => ['title' => 'x']],
			'plain save, json row' => $bound + ['save' => $jsonRow],
			'plain save throws' => $bound + ['save' => 'throw'],
			'submitter without submit' => $bound + ['submitter' => 'no submit method'],
			'submit answers id' => $bound + ['submit' => ['id' => 'case-sub', 'reference' => 'Z-1']],
			'submit answers nothing' => $bound + ['submit' => null],
			'submit throws' => $bound + ['submit' => 'throw'],
			'submit refuses with findings' => $bound + ['submit' => 'throw findings'],
			'submit refuses, findings not a list' => $bound + ['submit' => 'throw bad findings'],
			'no case schema, no answers' => ['config' => ['case_schema' => ''], 'answers' => []] + $bound,
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'forms-intake-case-for', value: $observed);
	}//end testGoldenCaseFor()
}//end class
