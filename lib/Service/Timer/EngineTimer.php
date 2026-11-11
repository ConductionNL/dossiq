<?php

/**
 * Dossiq's calls on the OpenRegister timer engine, shared by its timers.
 *
 * Each dossiq timer (advice, bezwaar archive, DSO deadline, milestone stall)
 * arms one engine timer per subject and cancels it again. The engine is
 * resolved lazily so dossiq runs without OpenRegister; a missing engine and
 * a refusing one both read as "unavailable", and a refusal is logged in the
 * owning timer's own sentence.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Timer;

use Closure;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\TimerChange;
use OCA\OpenRegister\Kernel\Contracts\TimerSubjectRef;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Arms and cancels one subject's engine timer.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
final class EngineTimer implements EngineTimerOutcomes {

	/**
	 * Build the calls for one timer.
	 *
	 * @param SettingsService $settingsService Resolves the engine.
	 * @param LoggerInterface $logger          Logs engine refusals.
	 * @param string          $ownerKey        The log context key the owner's id goes under.
	 * @param string          $armRefused      Logged when the engine refuses to arm.
	 * @param string          $cancelRefused   Logged when the engine refuses to cancel.
	 * @param string          $subjectSuffix   Keeps the subject apart from the owner's other timers.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly string $ownerKey,
		private readonly string $armRefused,
		private readonly string $cancelRefused,
		private readonly string $subjectSuffix = '',
	) {
	}//end __construct()

	/**
	 * The id of a register object, from its id, uuid or @self id.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string The id, '' when it has none.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
	 */
	public static function objectId(array $object): string {
		return (string) ($object['id'] ?? ($object['uuid'] ?? ($object['@self']['id'] ?? '')));
	}//end objectId()

	/**
	 * Arm the timer the configuration describes.
	 *
	 * The configuration is built only once the engine is known to be there.
	 *
	 * @param Closure $config  Builds the timer configuration array.
	 * @param string  $ownerId The owning object's id, as logged.
	 *
	 * @return string ARMED, or UNAVAILABLE when the engine is absent or refused.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
	 */
	public function arm(Closure $config, string $ownerId): string {
		$engine = $this->engine();
		if ($engine === null) {
			return self::UNAVAILABLE;
		}

		try {
			$engine->arm(config: $config(), actor: null);
		} catch (Throwable $e) {
			$this->logger->error($this->armRefused, [$this->ownerKey => $ownerId, 'error' => $e->getMessage()]);
			return self::UNAVAILABLE;
		}

		return self::ARMED;
	}//end arm()

	/**
	 * Cancel the owner's timers on this subject (and only those).
	 *
	 * @param string $ownerId The owning object's id.
	 * @param string $reason  Why.
	 *
	 * @return string|null UNAVAILABLE when the engine is absent or refused, null when done.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
	 */
	public function cancel(string $ownerId, string $reason): ?string {
		$engine = $this->engine();
		if ($engine === null) {
			return self::UNAVAILABLE;
		}

		try {
			$engine->cancelForSubject(
				subject: new TimerSubjectRef(type: 'object', uuid: $ownerId.$this->subjectSuffix),
				change: new TimerChange(reason: $reason, actor: null)
			);
		} catch (Throwable $e) {
			$this->logger->error($this->cancelRefused, [$this->ownerKey => $ownerId, 'error' => $e->getMessage()]);
			return self::UNAVAILABLE;
		}

		return null;
	}//end cancel()

	/**
	 * The engine, or null when OpenRegister does not ship it.
	 *
	 * @return object|null
	 */
	private function engine(): ?object {
		return $this->settingsService->getOpenRegisterClass(self::ENGINE_CLASS);
	}//end engine()
}//end class
