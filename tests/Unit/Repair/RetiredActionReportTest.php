<?php

/**
 * Unit tests for the retired automatic action report of RewriteRetiredFlowNodes.
 *
 * OpenRegister's flow classes are absent here, so the step skips the flows
 * and only reports the stored automatic actions of a retired type. The map
 * names two retired types; the object service answers per type, or fails.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RewriteRetiredFlowNodes;
use OCA\Dossiq\Service\Flow\RetiredNodeMap;
use OCA\Dossiq\Service\Flow\RetiredNodeRewriter;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Characterisation tests for the retired automatic action report.
 *
 * @covers \OCA\Dossiq\Repair\RewriteRetiredFlowNodes
 */
class RetiredActionReportTest extends TestCase {

	/**
	 * Every search, output line and log line, in order.
	 *
	 * @var array<int, string>
	 */
	private array $said = [];

	/**
	 * The step without OpenRegister's flow classes.
	 *
	 * @param array<string, mixed>  $actions   Rows (or an exception) per retired type.
	 * @param array<string, string> $config    The app config.
	 * @param bool                  $available Whether OpenRegister is available.
	 * @param bool                  $service   Whether its object service resolves.
	 * @param array<string, string> $types     The retired action types and their reasons.
	 *
	 * @return RewriteRetiredFlowNodes The step.
	 */
	private function step(
		array $actions,
		array $config=['register' => 'dossiq', 'automatic_action_schema' => 'automaticAction'],
		bool $available=true,
		bool $service=true,
		array $types=['scheduleReminder' => 'the engine schedules', 'sendSms' => 'no channel'],
	): RewriteRetiredFlowNodes {
		$said = &$this->said;
		$objects = new class($actions, $said) {
			/**
			 * @param array<string, mixed> $actions The answers.
			 * @param array<int, string>   $said    The shared record.
			 */
			public function __construct(private array $actions, private array &$said) {
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed Its answer.
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->said[] = 'system';
				return $operation();
			}

			/**
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters, bool $_rbac=true, bool $_multitenancy=true): array {
				$this->said[] = 'search '.$register.'/'.$schema.' '.json_encode($filters).' rbac='.var_export($_rbac, true);
				$answer = ($this->actions[$filters['type']] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);
		$settings->method('getObjectService')->willReturn($service === true ? $objects : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($config[$key] ?? '')
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not installed'));
		$map = $this->createMock(RetiredNodeMap::class);
		$map->method('retiredActionTypes')->willReturn($types);

		$logger = new class($said) extends AbstractLogger {
			/**
			 * @param array<int, string> $said The shared record.
			 */
			public function __construct(private array &$said) {
			}

			/**
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->said[] = 'log-'.$level.': '.$message.' '.json_encode($context);
			}
		};

		return new RewriteRetiredFlowNodes($container, $settings, $map, $this->createMock(RetiredNodeRewriter::class), $logger);
	}//end step()

	/**
	 * The output double.
	 *
	 * @return IOutput The output.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $text): void {
			$this->said[] = 'info: '.$text;
		});
		$output->method('warning')->willReturnCallback(function (string $text): void {
			$this->said[] = 'warning: '.$text;
		});

		return $output;
	}//end recordingOutput()

	/**
	 * Each stored action of a retired type is named and left alone; a type that cannot be read is logged.
	 *
	 * @return void
	 */
	public function testEachRetiredActionIsReported(): void {
		$this->step(
			[
				'scheduleReminder' => [['slug' => 'na-24-uur', 'id' => 'a-1'], ['id' => 'a-2', 'uuid' => 'u-2'], ['uuid' => 'u-3'], []],
				'sendSms' => new RuntimeException('store down'),
			]
		)->run($this->recordingOutput());

		$kept = ', which is retired (the engine schedules). It is kept, but it will not run.';
		self::assertSame(
			[
				'info: Dossiq: OpenRegister flows are not available; no stored flow to rewrite.',
				'system',
				'search dossiq/automaticAction {"type":"scheduleReminder","_limit":1000} rbac=false',
				'warning: Dossiq: automatic action "na-24-uur" has type scheduleReminder'.$kept,
				'log-warning: Dossiq: automatic action "na-24-uur" has type scheduleReminder'.$kept.' {"app":"dossiq","action":"na-24-uur"}',
				'warning: Dossiq: automatic action "a-2" has type scheduleReminder'.$kept,
				'log-warning: Dossiq: automatic action "a-2" has type scheduleReminder'.$kept.' {"app":"dossiq","action":"a-2"}',
				'warning: Dossiq: automatic action "u-3" has type scheduleReminder'.$kept,
				'log-warning: Dossiq: automatic action "u-3" has type scheduleReminder'.$kept.' {"app":"dossiq","action":"u-3"}',
				'warning: Dossiq: automatic action "?" has type scheduleReminder'.$kept,
				'log-warning: Dossiq: automatic action "?" has type scheduleReminder'.$kept.' {"app":"dossiq","action":"?"}',
				'system',
				'search dossiq/automaticAction {"type":"sendSms","_limit":1000} rbac=false',
				'log-warning: Dossiq: could not read the stored automatic actions of a retired type {"app":"dossiq","type":"sendSms","exception":"store down"}',
			],
			$this->said
		);
	}//end testEachRetiredActionIsReported()

	/**
	 * Without retired types, OpenRegister, its object service or its configuration, nothing is read.
	 *
	 * @return void
	 */
	public function testNothingIsReadWhenThereIsNothingToReport(): void {
		$flowsSkipped = 'info: Dossiq: OpenRegister flows are not available; no stored flow to rewrite.';

		$this->step([], types: [])->run($this->recordingOutput());
		$this->step([], available: false)->run($this->recordingOutput());
		$this->step([], service: false)->run($this->recordingOutput());
		$this->step([], config: ['register' => 'dossiq'])->run($this->recordingOutput());
		$this->step([], config: ['automatic_action_schema' => 'automaticAction'])->run($this->recordingOutput());

		self::assertSame(array_fill(0, 5, $flowsSkipped), $this->said);
	}//end testNothingIsReadWhenThereIsNothingToReport()
}//end class
