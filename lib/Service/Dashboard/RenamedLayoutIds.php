<?php

/**
 * The widget, page and menu ids dossiq renamed, and how a stored layout follows them.
 *
 * A layout an administrator arranged in buildiq is stored as a keyed manifest
 * delta: pages, widgets and menu entries matched by `id`, a grid cell naming
 * its widget by `widgetId`, a KPI row and an `__order` listing ids. When the
 * bundled manifest renames an id, a stored delta that still names the old one
 * matches nothing and the arrangement silently falls away. This class rewrites
 * those references, and only those: a widget's `type` is a registry key and
 * keeps its name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Dashboard;

/**
 * Old id => new id, and the rewrite of a stored manifest delta.
 *
 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
 */
final class RenamedLayoutIds {

	/**
	 * Every renamed id, old => new.
	 *
	 * @var array<string, string>
	 */
	public const IDS = [
		'dt-atrisk' => 'processing-time-at-risk',
		'dt-avg' => 'processing-time-average',
		'dt-breakdown' => 'processing-time-breakdown',
		'dt-charts' => 'processing-time-charts',
		'dt-charts-distribution' => 'processing-time-charts-distribution',
		'dt-charts-throughput' => 'processing-time-charts-throughput',
		'dt-charts-trend' => 'processing-time-charts-trend',
		'dt-closed' => 'processing-time-closed',
		'dt-monthly' => 'processing-time-monthly',
		'dt-over-norm' => 'processing-time-over-target',
		'dt-within-by-type' => 'processing-time-within-by-type',
		'dt-within-norm' => 'processing-time-within-target',
		'dt-woo' => 'processing-time-case-type-deadlines',
		'td-annual' => 'deadline-report-annual-card',
		'td-annual-payments' => 'deadline-report-annual-payments-list',
		'deadline-report-annual' => 'deadline-report-annual-card',
		'deadline-report-annual-payments' => 'deadline-report-annual-payments-list',
		'td-annual-warnings' => 'deadline-report-annual-warnings',
		'td-due-week' => 'deadline-report-due-week',
		'td-notices' => 'deadline-report-notices',
		'td-on-time' => 'deadline-report-on-time',
		'td-on-time-by-type' => 'deadline-report-on-time-by-type',
		'td-overdue' => 'deadline-report-overdue',
		'td-penalty' => 'deadline-report-penalty',
		'td-quarterly' => 'deadline-report-quarterly',
		'td-register-notice' => 'deadline-report-register-notice',
		'td-reports' => 'deadline-report-reports',
		'td-running' => 'deadline-report-running',
		'family-plan' => 'goal-plan',
		'family-plan-panel' => 'goal-plan-panel',
		'case-pipelinq-contact-moments' => 'linked-contact-moments',
		'WooReports' => 'DocumentReviewReports',
		'WooReportsMenu' => 'DocumentReviewReportsMenu',
		'/woo-reports' => '/document-review-reports',
		'analytics-series:procest-sla-compliance-by-type' => 'analytics-series:dossiq-sla-compliance-by-type',
		'analytics-series:procest-sla-processing-time-distribution' => 'analytics-series:dossiq-sla-processing-time-distribution',
		'analytics-series:procest-sla-monthly-trend' => 'analytics-series:dossiq-sla-monthly-trend',
		'analytics-series:procest-sla-weekly-throughput' => 'analytics-series:dossiq-sla-weekly-throughput',
	];

	/**
	 * The keys whose string value names an id.
	 *
	 * @var list<string>
	 */
	private const ID_KEYS = ['id', 'widgetId', 'route'];

	/**
	 * The keys whose list of strings names ids.
	 *
	 * @var list<string>
	 */
	private const ID_LIST_KEYS = ['kpiRow', 'widgetIds'];

	/**
	 * The prefix of a widget's visibility field (`visible.<widget id>`).
	 */
	private const VISIBLE_PREFIX = 'visible.';

	/**
	 * A stored delta with every renamed id rewritten.
	 *
	 * @param mixed       $value A delta, or any part of one.
	 * @param string|null $key   The key the value sits under, null at the top.
	 *
	 * @return mixed The value with renamed ids rewritten.
	 *
	 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
	 */
	public static function rewrite(mixed $value, ?string $key = null): mixed {
		if (is_string($value) === true) {
			return self::rewriteString(value: $value, key: $key);
		}

		if (is_array($value) === false) {
			return $value;
		}

		if ($key === '__order') {
			// `{"widgets": ["w2", "w1"]}`: each list names ids.
			return array_map(static fn (mixed $list): mixed => self::renameList(list: $list), $value);
		}

		if ($key !== null && in_array($key, self::ID_LIST_KEYS, true) === true) {
			return self::renameList(list: $value);
		}

		$out = [];
		foreach ($value as $childKey => $child) {
			if (is_int($childKey) === true) {
				$out[$childKey] = self::rewrite(value: $child);
				continue;
			}

			$out[(self::IDS[$childKey] ?? $childKey)] = self::rewrite(value: $child, key: $childKey);
		}

		return $out;
	}//end rewrite()

	/**
	 * A list of ids with each renamed id replaced.
	 *
	 * @param mixed $list The list.
	 *
	 * @return mixed
	 */
	private static function renameList(mixed $list): mixed {
		if (is_array($list) === false) {
			return $list;
		}

		$out = [];
		foreach ($list as $index => $id) {
			$out[$index] = $id;
			if (is_string($id) === true) {
				$out[$index] = (self::IDS[$id] ?? $id);
			}
		}

		return $out;
	}//end renameList()

	/**
	 * One string, rewritten when its key says it names an id.
	 *
	 * @param string      $value The string.
	 * @param string|null $key   Its key.
	 *
	 * @return string
	 */
	private static function rewriteString(string $value, ?string $key): string {
		if ($key !== null && in_array($key, self::ID_KEYS, true) === true) {
			return (self::IDS[$value] ?? $value);
		}

		if ($key === 'field' && str_starts_with($value, self::VISIBLE_PREFIX) === true) {
			$id = substr($value, strlen(self::VISIBLE_PREFIX));
			if (isset(self::IDS[$id]) === true) {
				return self::VISIBLE_PREFIX . self::IDS[$id];
			}
		}

		return $value;
	}//end rewriteString()
}//end class
