<?php

/**
 * Dossiq case date normaliser.
 *
 * The one class in this app that turns a submitted value into a stored date,
 * and the one class that resolves the time zone it is read in.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One rule for what a case date is, and one answer to which zone it is in.
 *
 * Nine write paths used to normalise a case date nine ways, and none of them
 * read the administered zone, so the same submitted day could be stored as
 * three different instants. Every write path now calls this class.
 *
 * The zone is resolved once per request: the engine working calendar answers
 * first, the tenant configuration second, and Europe/Amsterdam last. The
 * fallback is logged once, not once per date.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
 */
class CaseDateNormaliser {
	/**
	 * The zone used when neither the engine calendar nor the tenant answers.
	 *
	 * It is the value the register already declares as the default for
	 * `tenantConfiguration.timezone`, so the fallback is administered too,
	 * just not per tenant.
	 *
	 * @var string
	 */
	public const FALLBACK_TIMEZONE = 'Europe/Amsterdam';

	/**
	 * The engine service that resolves a working calendar.
	 *
	 * Duck-typed through {@see SettingsService::getOpenRegisterClass()}: an
	 * instance without OpenRegister installed resolves nothing and falls
	 * through to the tenant setting.
	 *
	 * @var string
	 */
	public const ENGINE_CALENDAR_CLASS = 'OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService';

	/**
	 * The accessor openregister `calendar-time-zone` (openregister#3688) adds
	 * to a working calendar.
	 *
	 * Until that lands, `method_exists()` is false and the tenant setting
	 * answers. The switch lives here rather than in nine controllers.
	 *
	 * @var string
	 */
	public const ENGINE_CALENDAR_ZONE_METHOD = 'getTimeZone';

	/**
	 * The date shapes this app accepts on a write.
	 *
	 * Deliberately narrower than what `DateTimeImmutable` swallows. PHP reads
	 * `31-01-2028` as 31 January without complaint, and a caseworker who
	 * meant 31 January 2028 in a `d-m-Y` habit gets a deadline nobody
	 * checked. An unreadable value is refused instead.
	 *
	 * @var string
	 */
	private const ISO_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})'
		. '(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?\s*(Z|[+-]\d{2}:?\d{2})?)?$/';

	/**
	 * The resolved zone for this request.
	 *
	 * @var DateTimeZone|null
	 */
	private ?DateTimeZone $zone = null;

	/**
	 * Whether the fallback has already been logged for this request.
	 *
	 * @var bool
	 */
	private bool $fallbackLogged = false;

	/**
	 * Constructor.
	 *
	 * @param TenantContext $tenantContext The tenant bound to this request.
	 * @param TenantConfigurationService $tenantConfiguration Reads `tenantConfiguration.timezone`.
	 * @param SettingsService $settingsService Resolves engine classes when OpenRegister is installed.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly TenantContext $tenantContext,
		private readonly TenantConfigurationService $tenantConfiguration,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The zone every case date is read and written in.
	 *
	 * @return DateTimeZone The engine calendar zone, the tenant zone, or the fallback.
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function timeZone(): DateTimeZone {
		if ($this->zone !== null) {
			return $this->zone;
		}

		$identifier = $this->engineCalendarZone();
		if ($identifier === null) {
			$identifier = $this->tenantZone();
		}

		if ($identifier === null) {
			$identifier = self::FALLBACK_TIMEZONE;
			$this->logFallbackOnce();
		}

		$this->zone = new DateTimeZone($identifier);
		return $this->zone;
	}//end timeZone()

	/**
	 * Parse a submitted value, refusing anything unreadable.
	 *
	 * A value carrying its own offset keeps that instant and is re-read in
	 * the administered zone. A value without one is read in the administered
	 * zone. A bare date is midnight in the administered zone. So the same day
	 * submitted all three ways is one instant.
	 *
	 * @param mixed $value The submitted value.
	 * @param string $field The field name to name in the refusal.
	 *
	 * @return DateTimeImmutable The parsed moment, in the administered zone.
	 *
	 * @throws InvalidArgumentException When the value is empty or not an ISO 8601 date.
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function parse(mixed $value, string $field): DateTimeImmutable {
		$parsed = $this->tryParse(value: $value);
		if ($parsed === null) {
			throw new InvalidArgumentException(
				$field . ' is not a date this system can read. Use an ISO 8601 value, '
				. 'for example 2028-01-31 or 2028-01-31T09:00:00+01:00. Received: '
				. $this->describe(value: $value)
			);
		}

		return $parsed;
	}//end parse()

	/**
	 * Parse a stored or reported value, answering null when it cannot be read.
	 *
	 * For read, report and analytics paths only. A write path calls
	 * {@see parse()}: a null return is what let every caller invent its own
	 * fallback, and two of them stored a typo or substituted today.
	 *
	 * @param mixed $value The value to read.
	 *
	 * @return DateTimeImmutable|null The parsed moment, or null.
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function tryParse(mixed $value): ?DateTimeImmutable {
		$zone = $this->timeZone();
		if ($value instanceof DateTimeInterface === true) {
			return DateTimeImmutable::createFromInterface($value)->setTimezone($zone);
		}

		if (is_string($value) === false) {
			return null;
		}

		$trimmed = trim($value);
		if ($trimmed === '') {
			return null;
		}

		if (preg_match(self::ISO_PATTERN, $trimmed, $parts) !== 1) {
			return null;
		}

		if (checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) === false) {
			return null;
		}

		try {
			$parsed = new DateTimeImmutable($trimmed, $zone);
		} catch (Throwable) {
			return null;
		}

		return $parsed->setTimezone($zone);
	}//end tryParse()

	/**
	 * A submitted value as the calendar date it names, `Y-m-d`.
	 *
	 * @param mixed $value The submitted value.
	 * @param string $field The field name to name in the refusal.
	 *
	 * @return string The date as `Y-m-d`.
	 *
	 * @throws InvalidArgumentException When the value is unreadable.
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function toCalendarDate(mixed $value, string $field): string {
		return $this->parse(value: $value, field: $field)->format(format: 'Y-m-d');
	}//end toCalendarDate()

	/**
	 * A stored or reported value as `Y-m-d`, or null when it cannot be read.
	 *
	 * @param mixed $value The value to read.
	 *
	 * @return string|null The date as `Y-m-d`, or null.
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function toCalendarDateOrNull(mixed $value): ?string {
		return $this->tryParse(value: $value)?->format(format: 'Y-m-d');
	}//end toCalendarDateOrNull()

	/**
	 * A submitted value as a moment, ATOM with a real offset.
	 *
	 * @param mixed $value The submitted value.
	 * @param string $field The field name to name in the refusal.
	 *
	 * @return string The moment in ATOM form.
	 *
	 * @throws InvalidArgumentException When the value is unreadable.
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function toMoment(mixed $value, string $field): string {
		return $this->parse(value: $value, field: $field)->format(format: DateTimeInterface::ATOM);
	}//end toMoment()

	/**
	 * Now, in the administered zone.
	 *
	 * @return DateTimeImmutable The current moment.
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable('now', $this->timeZone());
	}//end now()

	/**
	 * Midnight today, in the administered zone.
	 *
	 * Nine paths asked PHP for `today` and got midnight in whatever
	 * `date.timezone` the process carried, which is how a deadline moved a
	 * day between two servers.
	 *
	 * @return DateTimeImmutable Midnight in the administered zone.
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function today(): DateTimeImmutable {
		return $this->now()->setTime(0, 0, 0);
	}//end today()

	/**
	 * Now, as a stored moment.
	 *
	 * @return string The current moment in ATOM form.
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function nowAsMoment(): string {
		return $this->now()->format(DateTimeInterface::ATOM);
	}//end nowAsMoment()

	/**
	 * Today, as a stored calendar date.
	 *
	 * @return string Today as `Y-m-d` in the administered zone.
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function todayAsCalendarDate(): string {
		return $this->now()->format('Y-m-d');
	}//end todayAsCalendarDate()

	/**
	 * An already parsed moment as a stored calendar date.
	 *
	 * @param DateTimeInterface $moment The moment to store.
	 *
	 * @return string The date as `Y-m-d` in the administered zone.
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function formatCalendarDate(DateTimeInterface $moment): string {
		return DateTimeImmutable::createFromInterface($moment)
			->setTimezone($this->timeZone())
			->format('Y-m-d');
	}//end formatCalendarDate()

	/**
	 * An already parsed moment as a stored moment.
	 *
	 * @param DateTimeInterface $moment The moment to store.
	 *
	 * @return string The moment in ATOM form, in the administered zone.
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function formatMoment(DateTimeInterface $moment): string {
		return DateTimeImmutable::createFromInterface($moment)
			->setTimezone($this->timeZone())
			->format(DateTimeInterface::ATOM);
	}//end formatMoment()

	/**
	 * Build a moment from a year, month and day in the administered zone.
	 *
	 * Used by the holiday computus, which knows a calendar day and must not
	 * inherit the process zone to turn it into an instant.
	 *
	 * @param int $year The year.
	 * @param int $month The month.
	 * @param int $day The day.
	 *
	 * @return DateTimeImmutable Midnight on that day, in the administered zone.
	 *
	 * @spec openspec/changes/one-date-write-path/specs/case-management/spec.md
	 */
	public function fromParts(int $year, int $month, int $day): DateTimeImmutable {
		return $this->now()->setDate($year, $month, $day)->setTime(0, 0, 0);
	}//end fromParts()

	/**
	 * The zone the engine working calendar counts in, when it states one.
	 *
	 * @return string|null The IANA identifier, or null.
	 */
	private function engineCalendarZone(): ?string {
		if (class_exists(self::ENGINE_CALENDAR_CLASS) === false) {
			return null;
		}

		try {
			$service = $this->settingsService->getOpenRegisterClass(self::ENGINE_CALENDAR_CLASS);
			if ($service === null || method_exists($service, 'resolve') === false) {
				return null;
			}

			$calendar = $service->resolve(null, null);
			if (is_object($calendar) === false
				|| method_exists($calendar, self::ENGINE_CALENDAR_ZONE_METHOD) === false
			) {
				return null;
			}

			$identifier = $calendar->{self::ENGINE_CALENDAR_ZONE_METHOD}();
			return $this->acceptZone(identifier: $identifier);
		} catch (Throwable $exception) {
			$this->logger->debug(
				'Dossiq: the engine working calendar did not answer a time zone',
				['error' => $exception->getMessage()]
			);
			return null;
		}
	}//end engineCalendarZone()

	/**
	 * The zone this tenant administers.
	 *
	 * @return string|null The IANA identifier, or null.
	 */
	private function tenantZone(): ?string {
		try {
			if ($this->tenantContext->isBound() === false) {
				return null;
			}

			$config = $this->tenantConfiguration->getConfig($this->tenantContext->getTenantId());
			if (is_array($config) === false) {
				return null;
			}

			return $this->acceptZone(identifier: $config['timezone'] ?? null);
		} catch (Throwable $exception) {
			$this->logger->debug(
				'Dossiq: the tenant did not answer a time zone',
				['error' => $exception->getMessage()]
			);
			return null;
		}
	}//end tenantZone()

	/**
	 * Accept an identifier only when it is one the administrator may choose.
	 *
	 * @param mixed $identifier The candidate identifier.
	 *
	 * @return string|null The identifier, or null.
	 */
	private function acceptZone(mixed $identifier): ?string {
		if (is_string($identifier) === false) {
			return null;
		}

		$trimmed = trim($identifier);
		if (in_array($trimmed, TenantConfigurationService::ALLOWED_TIMEZONES, true) === false) {
			return null;
		}

		return $trimmed;
	}//end acceptZone()

	/**
	 * Record the fallback once per request, not once per date.
	 *
	 * @return void
	 */
	private function logFallbackOnce(): void {
		if ($this->fallbackLogged === true) {
			return;
		}

		$this->fallbackLogged = true;
		$this->logger->info(
			'Dossiq: no working calendar and no tenant time zone, case dates use ' . self::FALLBACK_TIMEZONE
		);
	}//end logFallbackOnce()

	/**
	 * Describe a refused value without echoing an unbounded string.
	 *
	 * @param mixed $value The refused value.
	 *
	 * @return string A short description.
	 */
	private function describe(mixed $value): string {
		if (is_string($value) === true) {
			if ($value === '') {
				return 'an empty value';
			}

			return '"' . mb_substr($value, 0, 40) . '"';
		}

		if ($value === null) {
			return 'no value';
		}

		return get_debug_type($value);
	}//end describe()
}//end class
