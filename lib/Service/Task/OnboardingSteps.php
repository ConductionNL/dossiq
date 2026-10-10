<?php

/**
 * Onboarding Steps
 *
 * A tenant's onboarding steps are tasks in OpenRegister's task engine
 * (remove-casetask task 7.1). Each step is one engine Task: its `taskKey` is
 * the step, its `organisation` and its `objectUuid` are the tenant (the
 * Organisation's uuid, which is also the uuid of the tenant's audit anchor),
 * and its state is the engine's CMMN state. dossiq keeps no onboarding row of
 * its own.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Task
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
 * @spec openspec/specs/tenant-onboarding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Task;

use OCA\Dossiq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes a tenant's onboarding steps as engine tasks.
 *
 * @spec openspec/specs/tenant-onboarding/spec.md
 */
class OnboardingSteps {
	/**
	 * The engine kind every onboarding step carries, so an inbox can tell one
	 * from case work.
	 */
	public const KIND = 'dossiq.onboarding';

	/**
	 * How dossiq's onboarding status maps onto the engine state (decision 144).
	 *
	 * `skipped` has no state of its own: it is `terminated` with the outcome
	 * `skipped`, so the step leaves every inbox and the outcome says why.
	 */
	public const STATE_FOR_STATUS = [
		'pending' => 'available',
		'in_progress' => 'active',
		'completed' => 'completed',
		'skipped' => 'terminated',
	];

	/**
	 * The outcome a skipped step carries.
	 */
	public const OUTCOME_SKIPPED = 'skipped';

	/**
	 * OpenRegister's task service, by name.
	 */
	private const TASK_SERVICE = 'OCA\OpenRegister\Service\Task\TaskService';

	/**
	 * OpenRegister's form-aware completion, by name.
	 */
	private const COMPLETION_SERVICE = 'OCA\OpenRegister\Service\Task\TaskFormCompletion';

	/**
	 * The most recent engine failure, or ''.
	 *
	 * @var string
	 */
	private string $lastError = '';

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settings   OpenRegister availability.
	 * @param ContainerInterface $container  Resolves the engine's services.
	 * @param LoggerInterface    $logger     Logger.
	 * @param EngineInboxQuery   $inboxQuery The one engine inbox read dossiq shares.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly EngineInboxQuery $inboxQuery,
	) {
	}//end __construct()

	/**
	 * The most recent engine failure, or ''.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md
	 */
	public function lastError(): string {
		return $this->lastError;
	}//end lastError()

	/**
	 * The tenant's onboarding steps, keyed by step.
	 *
	 * Each row says `status` in dossiq's onboarding vocabulary (pending,
	 * in_progress, completed, skipped), translated back from the engine state.
	 *
	 * @param string $tenantId The Organisation's uuid.
	 * @param string $actor    Who is reading.
	 *
	 * @return array<string, array{id: string, step: string, status: string, completedBy: string, completedAt: string}> The steps.
	 *
	 * The read goes through EngineInboxQuery, the one place dossiq builds
	 * OpenRegister's inbox criteria; a failed read leaves `lastError()` set.
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md
	 */
	public function forTenant(string $tenantId, string $actor): array {
		if (trim($tenantId) === '') {
			return [];
		}

		$rows = $this->inboxQuery->rows(
			criteria: ['uid' => $actor, 'isAdmin' => true, 'scope' => 'all', 'objectUuid' => $tenantId, 'kind' => self::KIND],
			limit: 100,
			failure: ['Dossiq: could not read the onboarding steps', ['tenantId' => $tenantId]]
		);
		$this->lastError = $this->inboxQuery->lastError();

		$steps = [];
		foreach ($rows as $row) {
			$step = self::field(row: $row, key: 'taskKey');
			if ($step === '') {
				continue;
			}

			$steps[$step] = [
				'id' => self::field(row: $row, key: 'uuid'),
				'step' => $step,
				'status' => self::statusFor(state: self::field(row: $row, key: 'state'), outcome: self::field(row: $row, key: 'outcome')),
				'completedBy' => self::field(row: $row, key: 'completedBy'),
				'completedAt' => self::field(row: $row, key: 'completedAt'),
			];
		}

		return $steps;
	}//end forTenant()

	/**
	 * Put one step on the engine, through its trusted import path.
	 *
	 * @param string               $tenantId The Organisation's uuid.
	 * @param string               $step     The step.
	 * @param string               $title    What the task says.
	 * @param string               $status   The onboarding status it starts in.
	 * @param string|null          $actor    Who creates it.
	 * @param array<string, mixed> $metadata Anything the step carries along.
	 *
	 * @return string The engine task's uuid, or '' when it was not written.
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md
	 */
	public function open(string $tenantId, string $step, string $title, string $status, ?string $actor, array $metadata = []): string {
		if (array_key_exists($status, self::STATE_FOR_STATUS) === false || $this->engineAvailable() === false) {
			return '';
		}

		$payload = [
			'key' => $step,
			'title' => $title,
			'kind' => self::KIND,
			'appId' => 'dossiq',
			'organisation' => $tenantId,
			'objectUuid' => $tenantId,
			'state' => self::STATE_FOR_STATUS[$status],
		];
		if ($status === 'skipped') {
			$payload['outcome'] = self::OUTCOME_SKIPPED;
		}

		if ($metadata !== []) {
			$payload['metadata'] = ['dossiq' => $metadata];
		}

		try {
			// Positional: resolved by name from an app that need not be
			// installed, so dossiq binds to its parameter order, not names.
			$service = $this->container->get(self::TASK_SERVICE);
			$task = $service->import($payload, $actor);
		} catch (Throwable $e) {
			$this->lastError = $e->getMessage();
			$this->logger->error('Dossiq: the engine refused an onboarding step', ['tenantId' => $tenantId, 'step' => $step, 'exception' => $e->getMessage()]);
			return '';
		}

		return self::field(row: $task, key: 'uuid');
	}//end open()

	/**
	 * Complete one step through the engine's completion verb.
	 *
	 * @param string      $taskId The engine task's uuid.
	 * @param string|null $actor  Who completes it.
	 *
	 * @return bool Whether the engine accepted it.
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md
	 */
	public function complete(string $taskId, ?string $actor): bool {
		if (trim($taskId) === '' || $this->engineAvailable() === false) {
			return false;
		}

		try {
			$completion = $this->container->get(self::COMPLETION_SERVICE);
			$completion->complete($taskId, 'done', null, null, [], $actor);
		} catch (Throwable $e) {
			$this->lastError = $e->getMessage();
			$this->logger->warning('Dossiq: the engine refused to complete an onboarding step', ['task' => $taskId, 'exception' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end complete()

	/**
	 * Translate an engine state back into dossiq's onboarding status.
	 *
	 * @param string $state   The engine state.
	 * @param string $outcome The engine outcome.
	 *
	 * @return string The onboarding status.
	 *
	 * @spec openspec/specs/tenant-onboarding/spec.md
	 */
	public static function statusFor(string $state, string $outcome): string {
		if ($state === 'terminated' && $outcome === self::OUTCOME_SKIPPED) {
			return 'skipped';
		}

		$status = array_search($state, self::STATE_FOR_STATUS, true);
		if ($status === false || $status === 'skipped') {
			return $state;
		}

		return $status;
	}//end statusFor()

	/**
	 * Whether OpenRegister is there to hold the steps.
	 *
	 * The engine's services themselves are resolved inside each verb's own
	 * try block, so a service that will not resolve is logged with the verb
	 * that needed it rather than answered as an empty value here.
	 *
	 * @return bool True when OpenRegister is available.
	 */
	private function engineAvailable(): bool {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			$this->lastError = 'OpenRegister is not available.';
			return false;
		}

		return true;
	}//end engineAvailable()

	/**
	 * One field of an engine row, array or entity, as a string.
	 *
	 * @param mixed  $row The row.
	 * @param string $key The field.
	 *
	 * @return string The value, or ''.
	 */
	private static function field(mixed $row, string $key): string {
		$value = '';
		if (is_array($row) === true) {
			$value = ($row[$key] ?? '');
		}

		if (is_object($row) === true && method_exists($row, 'get'.ucfirst($key)) === true) {
			$value = $row->{'get'.ucfirst($key)}();
		}

		if ($value instanceof \DateTimeInterface) {
			return $value->format(DATE_ATOM);
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string) $value;
	}//end field()
}//end class
