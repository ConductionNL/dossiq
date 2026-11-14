<?php

/**
 * The case read and patch that CaseAttentionFlagService and CaseAssignmentService share.
 *
 * Both services read one case and patch one case the same way: no
 * OpenRegister, no register or no case schema is `case_not_found` with a
 * warning naming the service; a lookup that throws is `case_not_found` with
 * the lookup's message; a case that is not there is `case_not_found` without
 * a warning; a patch that throws is `<act>_write_failed` with its message.
 * These pin every one of those answers, and the warnings, on both services.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-claim-action/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\CaseAssignmentService;
use OCA\Dossiq\Service\CaseAttentionFlagService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;

class CaseReadAndPatchCopiesTest extends TestCase {

	/**
	 * Every warning written, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * What the doubled object service was asked to patch, as [id, data, register, schema].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $patches = [];

	/**
	 * A settings service over a doubled object service.
	 *
	 * @param string $mode     How the object service answers: `row`, `null`, `boom` or `absent`.
	 * @param string $register The configured register.
	 * @param string $schema   The configured case schema.
	 *
	 * @return SettingsService The settings service.
	 */
	private function settings(string $mode, string $register = 'dossiq', string $schema = 'case'): SettingsService {
		$test = $this;
		$objects = new class ($mode, $test) {
			/**
			 * @param string                     $mode How to answer.
			 * @param CaseReadAndPatchCopiesTest $test Where patches are recorded.
			 */
			public function __construct(private string $mode, private CaseReadAndPatchCopiesTest $test) {
			}

			/**
			 * One object, or a failure.
			 *
			 * @param string $id       The uuid.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed>|null The object.
			 */
			public function find(string $id, string $register, string $schema): ?array {
				if ($this->mode === 'boom') {
					throw new RuntimeException('store down');
				}

				if ($this->mode === 'null') {
					return null;
				}

				return ['id' => $id, 'assignee' => '  sanne  ', 'register' => $register, 'schema' => $schema];
			}

			/**
			 * A patch, or a failure.
			 *
			 * @param string               $objectId The uuid.
			 * @param array<string, mixed> $data     The changes.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The patched object.
			 */
			public function patchObject(string $objectId, array $data, string $register, string $schema): array {
				if ($this->mode === 'boom') {
					throw new RuntimeException('write refused');
				}

				$this->test->recordPatch(patch: [$objectId, $data, $register, $schema]);
				return $data;
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($mode === 'absent' ? null : $objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? $register : ($key === 'case_schema' ? $schema : ''))
		);

		return $settings;
	}//end settings()

	/**
	 * Record a patch the doubled object service received.
	 *
	 * @param array<int, mixed> $patch The patch.
	 *
	 * @return void
	 */
	public function recordPatch(array $patch): void {
		$this->patches[] = $patch;
	}//end recordPatch()

	/**
	 * A logger that keeps every warning.
	 *
	 * @return AbstractLogger The logger.
	 */
	private function logger(): AbstractLogger {
		$test = $this;
		return new class ($test) extends AbstractLogger {
			/**
			 * @param CaseReadAndPatchCopiesTest $test Where warnings go.
			 */
			public function __construct(private CaseReadAndPatchCopiesTest $test) {
			}

			/**
			 * Keep a warning.
			 *
			 * @param mixed              $level   The level.
			 * @param string|Stringable  $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->test->recordWarning(warning: [(string)$level . ' ' . (string)$message, $context]);
			}
		};
	}//end logger()

	/**
	 * Record a warning.
	 *
	 * @param array{0: string, 1: array<string, mixed>} $warning The warning.
	 *
	 * @return void
	 */
	public function recordWarning(array $warning): void {
		$this->warnings[] = $warning;
	}//end recordWarning()

	/**
	 * The two services, each with its name, its read, its patch and its act.
	 *
	 * @return array<string, array{0: string, 1: string}> Per copy: the owner and the act.
	 */
	public static function copies(): array {
		return [
			'attention flag' => ['CaseAttentionFlagService', 'attention'],
			'assignment'     => ['CaseAssignmentService', 'assignment'],
		];
	}//end copies()

	/**
	 * The service a copy names, over the given settings.
	 *
	 * @param string          $owner    The service's short name.
	 * @param SettingsService $settings The settings.
	 *
	 * @return object The service.
	 */
	private function service(string $owner, SettingsService $settings): object {
		if ($owner === 'CaseAttentionFlagService') {
			return new CaseAttentionFlagService(settingsService: $settings, logger: $this->logger());
		}

		return new CaseAssignmentService(settingsService: $settings, logger: $this->logger());
	}//end service()

	/**
	 * Read a case through a copy, answering what came back or the error code.
	 *
	 * @param string          $owner    The service's short name.
	 * @param SettingsService $settings The settings.
	 *
	 * @return mixed The read, or `error:<code>`.
	 */
	private function read(string $owner, SettingsService $settings): mixed {
		$method = ($owner === 'CaseAttentionFlagService' ? 'read' : 'readAssignee');
		$service = $this->service(owner: $owner, settings: $settings);
		try {
			return (new ReflectionMethod($service, $method))->invoke($service, 'c-1');
		} catch (RuntimeException $e) {
			return 'error:' . $e->getMessage();
		}
	}//end read()

	/**
	 * Patch a case through a copy, answering null or the error code.
	 *
	 * @param string          $owner    The service's short name.
	 * @param SettingsService $settings The settings.
	 *
	 * @return string|null Null when written, `error:<code>` when refused.
	 */
	private function patch(string $owner, SettingsService $settings): ?string {
		$service = $this->service(owner: $owner, settings: $settings);
		try {
			if ($owner === 'CaseAttentionFlagService') {
				(new ReflectionMethod($service, 'write'))->invoke($service, 'c-1', ['needsAttention' => true]);
				return null;
			}

			(new ReflectionMethod($service, 'write'))->invoke($service, 'c-1', 'sanne', ['claimedAt' => 'now']);
			return null;
		} catch (RuntimeException $e) {
			return 'error:' . $e->getMessage();
		}
	}//end patch()

	/**
	 * Without OpenRegister, or without a register or case schema, both reads and patches are not found.
	 *
	 * @param string $owner The service's short name.
	 *
	 * @return void
	 *
	 * @dataProvider copies
	 */
	public function testAnUnreadyStoreIsNotFoundWithAWarning(string $owner): void {
		$unready = [
			'OpenRegister unavailable'   => $this->settings(mode: 'absent'),
			'case schema not configured' => $this->settings(mode: 'row', register: ''),
			'case schema not configured ' => $this->settings(mode: 'row', schema: ''),
		];
		foreach ($unready as $warning => $settings) {
			$this->warnings = [];
			self::assertSame('error:case_not_found', $this->read(owner: $owner, settings: $settings));
			self::assertSame('error:case_not_found', $this->patch(owner: $owner, settings: $settings));
			$line = ['warning Dossiq ' . $owner . ': ' . trim($warning), ['app' => 'dossiq']];
			self::assertSame([$line, $line], $this->warnings);
		}

		self::assertSame([], $this->patches);
	}//end testAnUnreadyStoreIsNotFoundWithAWarning()

	/**
	 * A lookup that throws is not found, and the warning carries the lookup's message.
	 *
	 * @param string $owner The service's short name.
	 * @param string $act   The act the patch is named after.
	 *
	 * @return void
	 *
	 * @dataProvider copies
	 */
	public function testAFailingStoreIsNotFoundOrNotWritten(string $owner, string $act): void {
		$settings = $this->settings(mode: 'boom');

		self::assertSame('error:case_not_found', $this->read(owner: $owner, settings: $settings));
		self::assertSame('error:' . $act . '_write_failed', $this->patch(owner: $owner, settings: $settings));
		self::assertSame(
			[
				['warning Dossiq ' . $owner . ': case lookup failed: store down', ['app' => 'dossiq', 'caseId' => 'c-1']],
				['warning Dossiq ' . $owner . ': ' . $act . ' write failed: write refused', ['app' => 'dossiq', 'caseId' => 'c-1']],
			],
			$this->warnings
		);
	}//end testAFailingStoreIsNotFoundOrNotWritten()

	/**
	 * A case that is not there is not found, without a warning.
	 *
	 * @param string $owner The service's short name.
	 *
	 * @return void
	 *
	 * @dataProvider copies
	 */
	public function testAnAbsentCaseIsNotFoundQuietly(string $owner): void {
		self::assertSame('error:case_not_found', $this->read(owner: $owner, settings: $this->settings(mode: 'null')));
		self::assertSame([], $this->warnings);
	}//end testAnAbsentCaseIsNotFoundQuietly()

	/**
	 * A case that is there is read from the configured register and schema, and a patch reaches it.
	 *
	 * @return void
	 */
	public function testAPresentCaseIsReadAndPatched(): void {
		$settings = $this->settings(mode: 'row');

		self::assertSame(
			['id' => 'c-1', 'assignee' => '  sanne  ', 'register' => 'dossiq', 'schema' => 'case'],
			$this->read(owner: 'CaseAttentionFlagService', settings: $settings)
		);
		self::assertSame('sanne', $this->read(owner: 'CaseAssignmentService', settings: $settings));
		self::assertNull($this->patch(owner: 'CaseAttentionFlagService', settings: $settings));
		self::assertNull($this->patch(owner: 'CaseAssignmentService', settings: $settings));
		self::assertSame(
			[
				['c-1', ['needsAttention' => true], 'dossiq', 'case'],
				['c-1', ['assignee' => 'sanne', 'claimedAt' => 'now'], 'dossiq', 'case'],
			],
			$this->patches
		);
		self::assertSame([], $this->warnings);
	}//end testAPresentCaseIsReadAndPatched()
}//end class
