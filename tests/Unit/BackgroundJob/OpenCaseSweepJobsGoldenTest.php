<?php

/**
 * The two open-case sweeps, recorded call by call.
 *
 * The silence sweep and the payment projection read the same open cases from
 * the same configured register. Every early return, every per-case outcome
 * and every log line is recorded here and compared with a golden file, so a
 * change to how either job reads or walks the cases shows up as a diff.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Dossiq\BackgroundJob\AutoCloseOnSilenceJob;
use OCA\Dossiq\BackgroundJob\PaymentStateProjectionJob;
use OCA\Dossiq\Service\Lifecycle\SilenceCloseService;
use OCA\Dossiq\Service\Money\CasePaymentReader;
use OCA\Dossiq\Service\Money\CasePaymentState;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Both sweeps match their recording.
 *
 * @covers \OCA\Dossiq\BackgroundJob\AutoCloseOnSilenceJob
 * @covers \OCA\Dossiq\BackgroundJob\PaymentStateProjectionJob
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\BackgroundJob\OpenCaseSweep
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
 */
class OpenCaseSweepJobsGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The calls, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $log = [];

	/**
	 * The open cases every world's store answers with.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function cases(): array {
		return [
			['title' => 'no id'],
			['id' => 'c-warn', 'paymentState' => 'outstanding'],
			['@self' => ['id' => 'c-close'], 'paymentState' => 'paid'],
			['id' => 'c-none'],
			['id' => 'c-boom'],
			['id' => 'c-failing-warn'],
		];
	}//end cases()

	/**
	 * The worlds: which job, whether openregister and the money app are there, the store, the config.
	 *
	 * @return array<string, array{0: string, 1: bool, 2: bool, 3: array<string, string>}>
	 */
	public static function worlds(): array {
		$config = ['register' => 'dossiq', 'case_schema' => 'case'];

		return [
			'silence, every outcome' => ['silence', true, true, $config],
			'silence without openregister' => ['silence', false, true, $config],
			'silence without a store' => ['silence', true, false, $config],
			'silence without a register' => ['silence', true, true, ['case_schema' => 'case']],
			'silence without a case schema' => ['silence', true, true, ['register' => 'dossiq']],
			'payment, every outcome' => ['payment', true, true, $config],
			'payment without the money app' => ['payment', false, true, $config],
			'payment without a store' => ['payment', true, false, $config],
			'payment without a case schema' => ['payment', true, true, ['register' => 'dossiq']],
		];
	}//end worlds()

	/**
	 * The sweep matches the recording.
	 *
	 * @param string                $job       `silence` or `payment`.
	 * @param bool                  $installed Whether openregister / the money app is installed.
	 * @param bool                  $store     Whether there is an object service.
	 * @param array<string, string> $config    The settings.
	 *
	 * @dataProvider worlds
	 *
	 * @return void
	 */
	public function testTheSweepMatchesTheRecording(string $job, bool $installed, bool $store, array $config): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key) use ($config): string {
				$this->log[] = ['getConfigValue' => $key];
				return ($config[$key] ?? '');
			}
		);
		$settings->method('getObjectService')->willReturnCallback(
			function () use ($store): ?object {
				$this->log[] = ['getObjectService' => $store];
				if ($store === false) {
					return null;
				}

				return $this->store();
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturnCallback(
			function () use ($installed): array {
				$this->log[] = ['getInstalledApps' => $installed];
				return ($installed === true) ? ['openregister'] : ['files'];
			}
		);
		$apps->method('isInstalled')->willReturnCallback(
			function (string $app) use ($installed): bool {
				$this->log[] = ['isInstalled' => $app];
				return $installed;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message, 'context' => $context];
				}
			);
		}

		if ($job === 'silence') {
			$instance = new AutoCloseOnSilenceJob(
				$this->createMock(ITimeFactory::class),
				$this->silence(),
				$settings,
				$apps,
				$logger,
				$this->createMock(BackgroundServiceAccount::class)
			);
			(new ReflectionMethod($instance, 'sweep'))->invoke($instance);
		} else {
			$instance = new PaymentStateProjectionJob(
				$this->createMock(ITimeFactory::class),
				$this->payments(),
				$settings,
				$apps,
				$logger,
				$this->createMock(BackgroundServiceAccount::class)
			);
			(new ReflectionMethod($instance, 'work'))->invoke($instance);
		}

		$name = trim(strtolower((string)preg_replace('/[^a-z]+/i', '-', $this->dataName())), '-');
		$this->assertMatchesGolden(name: 'open-case-sweeps/' . $name, value: $this->log);
	}//end testTheSweepMatchesTheRecording()

	/**
	 * A silence service that warns c-warn and c-failing-warn, closes c-close, and fails on c-boom.
	 *
	 * @return SilenceCloseService
	 */
	private function silence(): SilenceCloseService {
		$silence = $this->createMock(SilenceCloseService::class);
		$silence->method('decide')->willReturnCallback(
			function (array $case, DateTimeImmutable $today): array {
				$id = (string)($case['id'] ?? ($case['@self']['id'] ?? ''));
				$this->log[] = ['decide' => $id];
				if ($id === 'c-boom') {
					throw new RuntimeException('no case type');
				}

				$actions = ['c-warn' => 'warn', 'c-failing-warn' => 'warn', 'c-close' => 'close'];
				return ['action' => ($actions[$id] ?? 'none'), 'closesOn' => '2026-12-01'];
			}
		);
		$silence->method('warn')->willReturnCallback(
			function (string $caseId, array $case, string $closesOn): void {
				$this->log[] = ['warn' => $caseId, 'closesOn' => $closesOn];
				if ($caseId === 'c-failing-warn') {
					throw new RuntimeException('mail down');
				}
			}
		);
		$silence->method('close')->willReturnCallback(
			function (string $caseId, array $decision): void {
				$this->log[] = ['close' => $caseId, 'decision' => $decision['action']];
			}
		);

		return $silence;
	}//end silence()

	/**
	 * A payment reader: c-warn is paid now, c-close is still paid, c-none is stale, the rest are outstanding.
	 *
	 * @return CasePaymentReader
	 */
	private function payments(): CasePaymentReader {
		$payments = $this->createMock(CasePaymentReader::class);
		$payments->method('stateOf')->willReturnCallback(
			function (string $caseId): array {
				$this->log[] = ['stateOf' => $caseId];
				$states = ['c-warn' => CasePaymentState::PAID, 'c-close' => CasePaymentState::PAID, 'c-none' => CasePaymentState::STALE];
				return ['paymentState' => ($states[$caseId] ?? CasePaymentState::OUTSTANDING), 'paymentStateCheckedAt' => '2026-10-07T10:00:00+00:00'];
			}
		);

		return $payments;
	}//end payments()

	/**
	 * A store that records every call and refuses to patch c-boom.
	 *
	 * @return object
	 */
	private function store(): object {
		return new class ($this, self::cases()) {
			/**
			 * Constructor.
			 *
			 * @param OpenCaseSweepJobsGoldenTest      $test  The test keeping the log.
			 * @param list<array<string, mixed>>       $cases The open cases.
			 */
			public function __construct(private readonly OpenCaseSweepJobsGoldenTest $test, private readonly array $cases) {
			}

			/**
			 * Search by slug.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->test->log[] = ['searchObjectsBySlug' => [$register, $schema, $filters]];
				return $this->cases;
			}

			/**
			 * Run as the system.
			 *
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->test->log[] = ['runAsSystem' => true];
				return $operation();
			}

			/**
			 * Patch one object, called with named fields or with one patch object.
			 *
			 * @param mixed ...$args The arguments, by name.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(mixed ...$args): array {
				$patch = ($args['patch'] ?? null);
				if (is_object($patch) === true) {
					$args = ['objectId' => $patch->objectId, 'data' => $patch->data, 'register' => $patch->target->register, 'schema' => $patch->target->schema];
				}

				$this->test->log[] = ['patchObject' => [$args['objectId'], $args['data'], ($args['register'] ?? null), ($args['schema'] ?? null)]];
				if ($args['objectId'] === 'c-boom') {
					throw new RuntimeException('locked');
				}

				return ['id' => $args['objectId']];
			}
		};
	}//end store()
}//end class
