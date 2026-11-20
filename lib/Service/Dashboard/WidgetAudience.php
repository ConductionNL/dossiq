<?php

/**
 * The groups that may see a dashboard widget dossiq contributes, and whether a user is in one.
 *
 * The audience is declared per widget id: the app setting `widget_roles` (a
 * JSON object of widget id to a list of group ids) overrides the shipped
 * default in `lib/Settings/widget_roles.json`. A widget with no entry, or an
 * empty list, is seen by everybody, so an unconfigured instance renders as
 * before. An administrator outside the groups does not see the widget either.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Dashboard
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
 * @spec openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Dashboard;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\IGroupManager;

/**
 * Reads a widget's declared groups and answers whether a user is in one of them.
 *
 * @spec openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md
 */
class WidgetAudience {

	/**
	 * The app setting that overrides the shipped audience per widget.
	 *
	 * @var string
	 */
	public const SETTING = 'widget_roles';

	/**
	 * The shipped audience per widget.
	 *
	 * @var string
	 */
	private const SHIPPED = __DIR__ . '/../../Settings/widget_roles.json';

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settings Reads the `widget_roles` setting.
	 * @param IGroupManager            $groups   Answers group membership.
	 *
	 * @spec openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settings,
		private readonly IGroupManager $groups,
	) {
	}//end __construct()

	/**
	 * The groups declared for a widget: the configured list, else the shipped one, else none.
	 *
	 * @param string $widgetId The widget id.
	 *
	 * @return list<string> The group ids; empty means everybody.
	 *
	 * @spec openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md#requirement-req-dwv-001-a-contributed-case-widget-declares-the-roles-that-may-see-it
	 */
	public function groupsFor(string $widgetId): array {
		$configured = $this->decoded(json: $this->settings->getConfigValue(key: self::SETTING, default: ''));
		if (array_key_exists($widgetId, $configured) === true) {
			return $this->groupList(value: $configured[$widgetId]);
		}

		return $this->groupList(value: ($this->shipped()[$widgetId] ?? []));
	}//end groupsFor()

	/**
	 * Every widget the app ships an audience for, with its declared groups.
	 *
	 * @return array<string, list<string>> Group ids by widget id.
	 *
	 * @spec openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md#requirement-req-dwv-004-an-administrator-sets-the-audience-per-widget
	 */
	public function all(): array {
		$audiences = [];
		foreach (array_keys($this->shipped()) as $widgetId) {
			$audiences[(string)$widgetId] = $this->groupsFor(widgetId: (string)$widgetId);
		}

		return $audiences;
	}//end all()

	/**
	 * Whether a user may see a widget: always when it declares no group, else only as a member of one.
	 *
	 * @param string      $widgetId The widget id.
	 * @param string|null $userId   The user, or null when nobody is signed in.
	 *
	 * @return bool True when the user is in the audience.
	 *
	 * @spec openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md#requirement-req-dwv-002-a-user-outside-the-audience-never-sees-the-widget
	 */
	public function admits(string $widgetId, ?string $userId): bool {
		$groups = $this->groupsFor(widgetId: $widgetId);
		if ($groups === []) {
			return true;
		}

		if ($userId === null || $userId === '') {
			return false;
		}

		foreach ($groups as $groupId) {
			if ($this->groups->isInGroup($userId, $groupId) === true) {
				return true;
			}
		}

		return false;
	}//end admits()

	/**
	 * The shipped audience per widget id.
	 *
	 * @return array<string, mixed> The lists by widget id.
	 */
	private function shipped(): array {
		$raw = file_get_contents(self::SHIPPED);
		if ($raw === false) {
			return [];
		}

		$widgets = $this->decoded(json: $raw)['widgets'] ?? [];
		if (is_array($widgets) === false) {
			return [];
		}

		return $widgets;
	}//end shipped()

	/**
	 * A JSON object as an array; anything else as an empty one.
	 *
	 * @param string $json The JSON text.
	 *
	 * @return array<string, mixed> The decoded object.
	 */
	private function decoded(string $json): array {
		$value = json_decode($json, true);
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end decoded()

	/**
	 * The non-empty strings of a list.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return list<string> The group ids.
	 */
	private function groupList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$groups = [];
		foreach ($value as $groupId) {
			if (is_string($groupId) === true && trim($groupId) !== '') {
				$groups[] = trim($groupId);
			}
		}

		return $groups;
	}//end groupList()
}//end class
