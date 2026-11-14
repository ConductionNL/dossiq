<?php

/**
 * Dossiq flow timer engine
 *
 * OpenRegister's flow timer service as the app's timers use it: absent when
 * OpenRegister does not ship it, and a refusal from it is logged and read as
 * "unavailable" rather than thrown at the caller.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared plumbing of the timer services; each timer carries its own spec
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use Closure;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\TimerChange;
use OCA\OpenRegister\Kernel\Contracts\TimerSubjectRef;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Arms and cancels one kind of timer on OpenRegister's flow timer engine.
 *
 * @spec exclude shared plumbing of the timer services; each timer carries its own spec
 */
final class FlowTimerEngine {

	/**
	 * The OpenRegister class of the flow timer engine.
	 */
	public const ENGINE_CLASS = 'OCA\\OpenRegister\\Service\\Flow\\Timer\\FlowTimerService';

	/**
	 * The timer was armed; the same word every timer interface reports.
	 */
	public const ARMED = 'armed';

	/**
	 * The engine is absent or refused; the same word every timer interface reports.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the engine.
	 * @param LoggerInterface $logger          Records a refusal.
	 * @param string          $owner           Who logs, e.g. "Dossiq advice".
	 * @param string          $timer           The timer named in the log, e.g. "advice timer".
	 *
	 * @spec exclude shared plumbing of the timer services; each timer carries its own spec
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly string $owner,
		private readonly string $timer,
	) {
	}//end __construct()

	/**
	 * Arm a timer built by the given config, once the engine is known to be there.
	 *
	 * @param Closure(): array<string, mixed> $config     Builds the engine config.
	 * @param array<string, string>           $logContext What the log names the subject by.
	 *
	 * @return string ARMED, or UNAVAILABLE when the engine is absent or refused.
	 *
	 * @spec exclude shared plumbing of the timer services; each timer carries its own spec
	 */
	public function arm(Closure $config, array $logContext): string {
		$engine = $this->engine();
		if ($engine === null) {
			return self::UNAVAILABLE;
		}

		$built = $config();

		try {
			$engine->arm(config: $built, actor: null);
		} catch (Throwable $e) {
			$this->logger->error(
				$this->owner.': the engine refused the '.$this->timer,
				$logContext + ['error' => $e->getMessage()]
			);
			return self::UNAVAILABLE;
		}

		return self::ARMED;
	}//end arm()

	/**
	 * Cancel every timer of a subject.
	 *
	 * @param string                $subjectUuid The object uuid whose timers end.
	 * @param string                $reason      Why, recorded on each timer.
	 * @param array<string, string> $logContext  What the log names the subject by.
	 *
	 * @return string|null UNAVAILABLE when the engine is absent or refused, null when done.
	 *
	 * @spec exclude shared plumbing of the timer services; each timer carries its own spec
	 */
	public function cancel(string $subjectUuid, string $reason, array $logContext): ?string {
		$engine = $this->engine();
		if ($engine === null) {
			return self::UNAVAILABLE;
		}

		try {
			$engine->cancelForSubject(
				subject: new TimerSubjectRef(type: 'object', uuid: $subjectUuid),
				change: new TimerChange(reason: $reason, actor: null)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				$this->owner.': the engine refused to cancel the '.$this->timer,
				$logContext + ['error' => $e->getMessage()]
			);
			return self::UNAVAILABLE;
		}

		return null;
	}//end cancel()

	/**
	 * One escalation rule of normal priority that notifies no role and opens no incident.
	 *
	 * @param string $trigger The trigger: slaBreached or preBreach.
	 * @param int    $offset  The offset from the trigger.
	 * @param string $unit    The unit of the offset.
	 * @param string $message The message key.
	 *
	 * @return array<string, mixed> The rule.
	 *
	 * @spec exclude shared plumbing of the timer services; each timer carries its own spec
	 */
	public static function rule(string $trigger, int $offset, string $unit, string $message): array {
		return [
			'trigger'        => $trigger,
			'offset'         => $offset,
			'offsetUnit'     => $unit,
			'notifyRole'     => [],
			'escalateToRole' => [],
			'priority'       => 'normal',
			'message'        => $message,
			'openIncident'   => false,
		];
	}//end rule()

	/**
	 * The engine, or null when OpenRegister does not ship it.
	 *
	 * @return object|null
	 */
	private function engine(): ?object {
		return $this->settingsService->getOpenRegisterClass(self::ENGINE_CLASS);
	}//end engine()
}//end class
