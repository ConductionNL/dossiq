<?php

/**
 * Dossiq TermMoveHistory.
 *
 * Why a term's date is not the date it was.
 *
 * WHAT A HANDLER SEES WITHOUT THIS. A deadline that quietly became a different
 * deadline. The case page shows one date, the applicant was told another, and
 * nothing on the page says which of the two is wrong or why. The move itself
 * is legitimate: an extension was granted, a pause ran, a closure was added to
 * the organisation calendar and every term over it recomputed. The absence of
 * a record is what turns a lawful act into an argument.
 *
 * 🔑 IT READS THE ENGINE'S HISTORY AND KEEPS NONE OF ITS OWN. The engine
 * supersedes a timer when its moment changes and writes an event saying so
 * (`FlowTimerEvent::TYPE_SUPERSEDED`). That is the record. A dossiq journal of
 * the same moves would be a second account of one fact, and the two would
 * disagree the first time a move happened through a path dossiq did not make
 * itself, which is exactly the calendar-recompute case this panel exists for.
 *
 * WHAT IT CANNOT SHOW YET, AND SAYS SO. A move caused by a CALENDAR change
 * arrives once openregister's `calendar-change-recomputes-timers` lands. Until
 * then this reads the moves that already happen, which are the extensions and
 * the pauses, and the panel is not dark: it is showing everything there is.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The moves an instance's deadline has already made.
 *
 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
 */
class TermMoveHistory {
	/**
	 * The engine's timer service, resolved by name.
	 *
	 * @var string
	 */
	public const ENGINE_TIMERS = 'OCA\\OpenRegister\\Service\\Flow\\Timer\\FlowTimerService';

	/**
	 * The event type that means "this deadline became another one".
	 *
	 * @var string
	 */
	public const TYPE_SUPERSEDED = 'superseded';

	/**
	 * Constructor.
	 *
	 * @param SettingsService      $settings The by-name lookup into OpenRegister.
	 * @param LoggerInterface|null $logger   Where an unreadable history is noted.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly ?LoggerInterface $logger = null,
	) {

	}//end __construct()

	/**
	 * Every move one term instance's deadline has made, oldest first.
	 *
	 * Answers an empty list for an instance with no engine timer, which is
	 * every instance created before the timers existed, and for an engine
	 * that is not installed. Those two absences mean "nothing moved as far as
	 * anyone can tell", and the panel says that rather than implying nothing
	 * moved.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 *
	 * @return array<int, array<string, mixed>> The moves.
	 *
	 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function movesFor(array $instance): array {
		$timerId = trim((string)($instance['engineTimerId'] ?? ''));
		if ($timerId === '') {
			return [];
		}

		$engine = $this->settings->getOpenRegisterClass(self::ENGINE_TIMERS);
		if ($engine === null || method_exists($engine, 'history') === false) {
			return [];
		}

		try {
			$events = $engine->history($timerId);
		} catch (Throwable $e) {
			// Logged rather than swallowed: an empty Moved dates section and
			// an unreadable one look the same on the page, and only one of
			// them means the deadline never moved.
			$this->logger?->warning(
				'Dossiq termijn: the timer history could not be read; the moved dates section will read as empty',
				['timer' => $timerId, 'error' => $e->getMessage()],
			);

			return [];
		}

		$moves = [];
		foreach ((is_iterable($events) === true ? $events : []) as $event) {
			$row = $this->asRow(event: $event);
			if ($row !== null) {
				$moves[] = $row;
			}
		}

		return $moves;
	}//end movesFor()

	/**
	 * One superseded event as a row, or null when it is another kind.
	 *
	 * @param mixed $event The engine event, entity or array.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	private function asRow(mixed $event): ?array {
		$data = $event;
		if (is_object($event) === true && method_exists($event, 'jsonSerialize') === true) {
			$data = $event->jsonSerialize();
		}

		if (is_array($data) === false) {
			return null;
		}

		if (trim((string)($data['type'] ?? '')) !== self::TYPE_SUPERSEDED) {
			return null;
		}

		return [
			'at' => (string)($data['occurredAt'] ?? ($data['created'] ?? '')),
			// The reason the engine recorded. Not defaulted to a sentence of
			// dossiq's own: a move whose reason is blank is a gap in the
			// record, and writing "unknown" over it would hide that.
			'reason' => (string)($data['reason'] ?? ''),
			'from' => (string)($data['previousFireAt'] ?? ''),
			'to' => (string)($data['fireAt'] ?? ''),
		];
	}//end asRow()
}//end class
