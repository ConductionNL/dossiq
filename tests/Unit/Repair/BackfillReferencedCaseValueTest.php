<?php

/**
 * Unit tests for the referenced-value backfill, through its statusRole step.
 *
 * The searches and the patch are the trait's protected methods, overridden
 * in a subclass so each schema answers its own pages and each case its own
 * patch result; the output and the logger record every line.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\BackfillCaseStatusRole;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Characterisation tests for BackfillReferencedCaseValue.
 *
 * @covers \OCA\Dossiq\Repair\BackfillReferencedCaseValue
 * @covers \OCA\Dossiq\Repair\BackfillCaseStatusRole
 * @uses \OCA\Dossiq\Repair\CaseValueBackfill
 * @uses \OCA\Dossiq\Repair\PendingCaseValue
 */
class BackfillReferencedCaseValueTest extends TestCase {

	/**
	 * Every output and log line, and every search and patch, in order.
	 *
	 * @var array<int, string>
	 */
	private array $said = [];

	/**
	 * The step over the given pages and patch answers.
	 *
	 * @param array<string, mixed>  $pages   Rows (or an exception) per schema, a list of pages each.
	 * @param array<string, mixed>  $patched The stored answer (or an exception) per case uuid.
	 * @param array<string, string> $config  The app config.
	 * @param bool                  $service Whether OpenRegister is there.
	 *
	 * @return BackfillCaseStatusRole The step.
	 */
	private function step(array $pages, array $patched=[], array $config=['register' => 'dossiq', 'case_schema' => 'case', 'status_type_schema' => 'statusType'], bool $service=true): BackfillCaseStatusRole {
		$said = &$this->said;
		$objects = new class($said) {
			/**
			 * @param array<int, string> $said The shared record.
			 */
			public function __construct(public array &$said) {
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
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($service === true ? $objects : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default=''): string => ($config[$key] ?? $default)
		);

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

		return new class($settings, $logger, $pages, $patched, $said) extends BackfillCaseStatusRole {
			/**
			 * @param array<string, mixed> $pages   The pages.
			 * @param array<string, mixed> $patched The patch answers.
			 * @param array<int, string>   $said    The shared record.
			 */
			public function __construct(SettingsService $settings, AbstractLogger $logger, private array $pages, private array $patched, private array &$said) {
				parent::__construct($settings, $logger);
			}

			/**
			 * @param object               $objectService The object service.
			 * @param int|string           $register      The register.
			 * @param int|string           $schema        The schema.
			 * @param array<string, mixed> $filters       The filters.
			 *
			 * @return array<int, array<string, mixed>> The page.
			 */
			protected function searchObjectsAsArraysUnscoped(object $objectService, int|string $register, int|string $schema, array $filters=[]): array {
				$this->said[] = 'search '.$register.'/'.$schema.' '.json_encode($filters);
				$answer = ($this->pages[$schema] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return ($answer[intdiv((int)$filters['_offset'], (int)$filters['_limit'])] ?? []);
			}

			/**
			 * @param object               $objectService The object service.
			 * @param int|string           $register      The register.
			 * @param int|string           $schema        The schema.
			 * @param string               $id            The case uuid.
			 * @param array<string, mixed> $changes       The changes.
			 *
			 * @return array<string, mixed>|null The stored case.
			 */
			protected function patchObjectAsArray(object $objectService, int|string $register, int|string $schema, string $id, array $changes): ?array {
				$this->said[] = 'patch '.$register.'/'.$schema.' '.$id.' '.json_encode($changes);
				$answer = ($this->patched[$id] ?? null);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		};
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

		return $output;
	}//end recordingOutput()

	/**
	 * Every case is read first, then written; each outcome is counted and reported.
	 *
	 * @return void
	 */
	public function testEveryCaseIsReadThenWrittenAndCounted(): void {
		$statuses = [
			['id' => 's1', 'role' => 'behandelaar'],
			['uuid' => 'S2', 'role' => 'coordinator'],
			['@self' => ['id' => 's3'], 'role' => ''],
			['id' => 's4', 'role' => 7],
		];
		$firstPage = array_fill(0, 199, ['id' => 'c-right', 'status' => 's1', 'statusRole' => 'behandelaar']);
		$firstPage[] = ['id' => 'c1', 'status' => 's1'];
		$cases = [
			$firstPage,
			[
				['uuid' => 'c2', 'status' => ['id' => 'S2']],
				['@self' => ['id' => 'c3'], 'status' => ['uuid' => 's1'], 'statusRole' => 'old'],
				['id' => 'c4', 'status' => 's3'],
				['id' => 'c5'],
				['status' => 's1'],
				['id' => 'c6', 'status' => 's1'],
			],
		];
		$patched = [
			'c1' => ['statusRole' => 'behandelaar'],
			'c2' => ['statusRole' => 'other'],
			'c3' => new RuntimeException('refused'),
			'c6' => null,
		];

		$this->step(['statusType' => [$statuses], 'case' => $cases], $patched)->run($this->recordingOutput());

		self::assertSame(
			[
				'system',
				'search dossiq/statusType {"_limit":200,"_offset":0}',
				'search dossiq/case {"_limit":200,"_offset":0}',
				'search dossiq/case {"_limit":200,"_offset":200}',
				'patch dossiq/case c1 []',
				'patch dossiq/case c2 []',
				'patch dossiq/case c3 []',
				'log-error: Dossiq: the statusRole backfill failed for one case {"app":"dossiq","uuid":"c3","exception":"refused"}',
				'patch dossiq/case c6 []',
				'info: statusRole backfill: 1 filled, 2 written but not confirmed, 1 failed, 202 already right or without a role.',
			],
			$this->said
		);
	}//end testEveryCaseIsReadThenWrittenAndCounted()

	/**
	 * Without OpenRegister, its configuration, any referred value or readable cases, the step says why and writes nothing.
	 *
	 * @return void
	 */
	public function testEachSkipSaysWhy(): void {
		$this->step([], service: false)->run($this->recordingOutput());
		$this->step([], config: ['register' => 'dossiq', 'case_schema' => 'case'])->run($this->recordingOutput());
		$this->step(['statusType' => [[['id' => 's1', 'role' => '']]]])->run($this->recordingOutput());
		$this->step(['statusType' => [[['id' => 's1', 'role' => 'r']]], 'case' => new RuntimeException('store down')])->run($this->recordingOutput());

		self::assertSame(
			[
				'info: OpenRegister unavailable, skipping the statusRole backfill.',
				'info: statusRole backfill: the case or status type schema is not configured, skipped.',
				'system',
				'search dossiq/statusType {"_limit":200,"_offset":0}',
				'info: statusRole backfill: 0 filled, 0 written but not confirmed, 0 failed, 0 already right or without a role.',
				'system',
				'search dossiq/statusType {"_limit":200,"_offset":0}',
				'search dossiq/case {"_limit":200,"_offset":0}',
				'log-warning: Dossiq: the statusRole backfill could not read the cases {"app":"dossiq","error":"store down"}',
				'info: statusRole backfill: the cases could not be read, skipped.',
			],
			$this->said
		);
	}//end testEachSkipSaysWhy()
}//end class
