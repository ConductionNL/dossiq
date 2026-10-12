<?php

/**
 * Dossiq session definition.
 *
 * One entry of a case type's `sessions[]`: a session a case holds with its
 * parties (a hearing, a zitting, an inspection talk). It says who may be
 * invited, how long before the session the file opens for inspection, whether
 * a party may waive it, and whether minutes and recording consent are
 * required. This class is the single authority on that shape: the service
 * builds every definition through its constructor, so a session that is accepted
 * is a session the service can act on.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Session
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
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Session;

use InvalidArgumentException;

/**
 * A validated session of a case type.
 *
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-case-type-declares-the-sessions-a-case-holds-req-css-1
 */
final class SessionDefinition {

	/**
	 * The status names a session moves through when the case type names none.
	 *
	 * @var array<string, string>
	 */
	public const DEFAULT_STATUSES = [
		'planned'   => 'planned',
		'waived'    => 'afgezien',
		'executed'  => 'executed',
		'cancelled' => 'cancelled',
	];

	/**
	 * The session key.
	 *
	 * @var string
	 */
	public readonly string $key;

	/**
	 * What the session is called.
	 *
	 * @var string
	 */
	public readonly string $label;

	/**
	 * Roles that may be invited; empty means any.
	 *
	 * @var array<int, string>
	 */
	public readonly array $inviteeRoles;

	/**
	 * Fewest invitees a session needs.
	 *
	 * @var int
	 */
	public readonly int $minInvitees;

	/**
	 * Days the file is open before the session.
	 *
	 * @var int
	 */
	public readonly int $inspectionFloorDays;

	/**
	 * Whether a party may waive the session.
	 *
	 * @var bool
	 */
	public readonly bool $waivable;

	/**
	 * Whether closing needs minutes.
	 *
	 * @var bool
	 */
	public readonly bool $minutesRequired;

	/**
	 * Whether audio needs consent.
	 *
	 * @var bool
	 */
	public readonly bool $audioNeedsConsent;

	/**
	 * Hours attendance stays open after the start.
	 *
	 * @var int
	 */
	public readonly int $attendanceGraceHours;

	/**
	 * Status written per step.
	 *
	 * @var array<string, string>
	 */
	public readonly array $statuses;

	/**
	 * Build and validate a session from its stored form.
	 *
	 * @param mixed $config One `sessions[]` entry.
	 *
	 * @throws InvalidArgumentException When the entry is not a well-formed session.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-case-type-declares-the-sessions-a-case-holds-req-css-1
	 */
	public function __construct(mixed $config) {
		if (is_array($config) === false) {
			throw new InvalidArgumentException('A session must be an object.');
		}

		$key = self::text(config: $config, key: 'key');
		if (preg_match('/^[a-z][a-zA-Z0-9-]*$/', $key) !== 1) {
			throw new InvalidArgumentException('A session needs a `key` of letters, digits and dashes.');
		}

		$label = self::text(config: $config, key: 'label');
		if ($label === '') {
			$label = $key;
		}

		$this->key                  = $key;
		$this->label                = $label;
		$this->inviteeRoles         = self::roles(config: $config, key: $key);
		$this->minInvitees          = self::count(config: $config, field: 'minInvitees', default: 1, key: $key);
		$this->inspectionFloorDays  = self::count(config: $config, field: 'inspectionFloorDays', default: 0, key: $key);
		$this->waivable             = self::flag(config: $config, field: 'waivable', default: false);
		$this->minutesRequired      = self::flag(config: $config, field: 'minutesRequired', default: true);
		$this->audioNeedsConsent    = self::flag(config: $config, field: 'recordingConsentRequired', default: true);
		$this->attendanceGraceHours = self::count(config: $config, field: 'attendanceGraceHours', default: 1, key: $key);
		$this->statuses             = self::statuses(config: $config, key: $key);
	}//end __construct()

	/**
	 * The status this session writes at a step.
	 *
	 * @param string $step One of the DEFAULT_STATUSES keys.
	 *
	 * @return string The status value.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-case-type-declares-the-sessions-a-case-holds-req-css-1
	 */
	public function status(string $step): string {
		return ($this->statuses[$step] ?? self::DEFAULT_STATUSES[$step] ?? $step);
	}//end status()

	/**
	 * The rules a session of this definition is held to.
	 *
	 * @return SessionRules The rules.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-planning-a-session-keeps-the-inspection-floor-and-the-invitee-roles-req-css-2
	 */
	public function rules(): SessionRules {
		return new SessionRules(definition: $this);
	}//end rules()

	/**
	 * The session as a listing answers it.
	 *
	 * @return array<string, mixed> The public shape.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-case-type-declares-the-sessions-a-case-holds-req-css-1
	 */
	public function toArray(): array {
		return [
			'key'                      => $this->key,
			'label'                    => $this->label,
			'inviteeRoles'             => $this->inviteeRoles,
			'minInvitees'              => $this->minInvitees,
			'inspectionFloorDays'      => $this->inspectionFloorDays,
			'waivable'                 => $this->waivable,
			'minutesRequired'          => $this->minutesRequired,
			'recordingConsentRequired' => $this->audioNeedsConsent,
			'attendanceGraceHours'     => $this->attendanceGraceHours,
			'statuses'                 => $this->statuses,
		];
	}//end toArray()

	/**
	 * The invitee roles, as a list of non-empty strings.
	 *
	 * @param array<array-key, mixed> $config The entry.
	 * @param string                  $key    The session key, for the message.
	 *
	 * @return array<int, string> The roles.
	 *
	 * @throws InvalidArgumentException When the value is not a list of strings.
	 */
	private static function roles(array $config, string $key): array {
		$value = ($config['inviteeRoles'] ?? []);
		if (is_array($value) === false) {
			throw new InvalidArgumentException("Session '$key' has `inviteeRoles` that is not a list.");
		}

		$roles = [];
		foreach ($value as $role) {
			if (is_string($role) === false || trim($role) === '') {
				throw new InvalidArgumentException("Session '$key' names an invitee role that is not a word.");
			}

			$roles[] = trim($role);
		}

		return array_values(array_unique($roles));
	}//end roles()

	/**
	 * The status names, defaults filled in.
	 *
	 * @param array<array-key, mixed> $config The entry.
	 * @param string                  $key    The session key, for the message.
	 *
	 * @return array<string, string> The statuses.
	 *
	 * @throws InvalidArgumentException When `statuses` is malformed.
	 */
	private static function statuses(array $config, string $key): array {
		$value = ($config['statuses'] ?? []);
		if (is_array($value) === false) {
			throw new InvalidArgumentException("Session '$key' has `statuses` that is not an object.");
		}

		$statuses = self::DEFAULT_STATUSES;
		foreach ($value as $step => $status) {
			if (isset(self::DEFAULT_STATUSES[$step]) === false) {
				throw new InvalidArgumentException("Session '$key' names a status for an unknown step '$step'.");
			}

			if (is_string($status) === false || trim($status) === '') {
				throw new InvalidArgumentException("Session '$key' gives step '$step' an empty status.");
			}

			$statuses[$step] = trim($status);
		}

		return $statuses;
	}//end statuses()

	/**
	 * A non-negative whole number, or a default when absent.
	 *
	 * @param array<array-key, mixed> $config  The entry.
	 * @param string                  $field   The field.
	 * @param int                     $default The value when absent.
	 * @param string                  $key     The session key, for the message.
	 *
	 * @return int The value.
	 *
	 * @throws InvalidArgumentException When the value is not a non-negative integer.
	 */
	private static function count(array $config, string $field, int $default, string $key): int {
		if (array_key_exists($field, $config) === false || $config[$field] === null) {
			return $default;
		}

		$value = $config[$field];
		if (is_int($value) === false || $value < 0) {
			throw new InvalidArgumentException("Session '$key' needs `$field` to be a whole number of zero or more.");
		}

		return $value;
	}//end count()

	/**
	 * A boolean, or a default when absent.
	 *
	 * @param array<array-key, mixed> $config  The entry.
	 * @param string                  $field   The field.
	 * @param bool                    $default The value when absent.
	 *
	 * @return bool The value.
	 */
	private static function flag(array $config, string $field, bool $default): bool {
		$value = ($config[$field] ?? null);
		if (is_bool($value) === false) {
			return $default;
		}

		return $value;
	}//end flag()

	/**
	 * A trimmed string value, or '' when absent or not a string.
	 *
	 * @param array<array-key, mixed> $config The entry.
	 * @param string                  $key    The key.
	 *
	 * @return string The value.
	 */
	private static function text(array $config, string $key): string {
		$value = ($config[$key] ?? '');
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class
