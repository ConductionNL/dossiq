<?php

/**
 * Dossiq package admin tab declaration.
 *
 * A package may declare a tab of its own in the app's Nextcloud admin
 * settings, in its `package.json`:
 *
 * ```json
 * "adminTab": {
 *     "title": "VTH",
 *     "lists": ["caseTypes", "flows", "feeRules", "templates", "connections", "checklists"]
 * }
 * ```
 *
 * `title` is the tab's label. `lists` names the configuration the tab lists,
 * each one of {@see self::LISTS}, in the order the tab shows them. The tab
 * lists what the package installed here, read from the shipped-set ledger
 * ({@see PackageAdminTabs}); a list the package installed nothing into is
 * shown empty. This class reads and checks the declaration; it holds no
 * procedure of its own, so any package can declare a tab.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/vth-cases-run-end-to-end
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

/**
 * Reads and checks a package's `adminTab` declaration.
 *
 * @spec openspec/changes/vth-cases-run-end-to-end
 */
class PackageAdminTab {

	/**
	 * The manifest key that declares the tab.
	 */
	public const KEY = 'adminTab';

	/**
	 * Each list a tab may show, and the ledger schema slugs its rows are recorded under.
	 *
	 * Fee rules live in shillinq (`FeeSchedule`); templates are the content
	 * and mail templates; connections are the app's integration records.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const LISTS = [
		'caseTypes' => ['caseType'],
		'flows' => ['workflowTemplate'],
		'feeRules' => ['FeeSchedule'],
		'templates' => ['contentTemplate', 'emailTemplate'],
		'connections' => ['dossiqIntegration'],
		'checklists' => ['inspectionChecklistTemplate'],
	];

	/**
	 * What is wrong with an `adminTab` value; none when it is absent or well formed.
	 *
	 * @param mixed $adminTab The manifest's `adminTab` value, or null when absent.
	 *
	 * @return array<int, string> One message per problem.
	 *
	 * @spec openspec/changes/vth-cases-run-end-to-end
	 */
	public function errors(mixed $adminTab): array {
		if ($adminTab === null) {
			return [];
		}

		if (is_array($adminTab) === false || ($adminTab !== [] && array_is_list($adminTab) === true)) {
			return ['package.json: adminTab must be an object with a title and lists.'];
		}

		$errors = [];
		if (is_string($adminTab['title'] ?? null) === false || trim($adminTab['title']) === '') {
			$errors[] = 'package.json: adminTab.title is missing.';
		}

		return array_merge($errors, $this->listErrors(lists: ($adminTab['lists'] ?? null)));
	}//end errors()

	/**
	 * What is wrong with an `adminTab.lists` value.
	 *
	 * @param mixed $lists The declared lists.
	 *
	 * @return array<int, string> One message per problem.
	 *
	 * @spec openspec/changes/vth-cases-run-end-to-end
	 */
	private function listErrors(mixed $lists): array {
		if (is_array($lists) === false || $lists === [] || array_is_list($lists) === false) {
			return ['package.json: adminTab.lists must be a non-empty list.'];
		}

		$known = implode(', ', array_keys(self::LISTS));
		$errors = [];
		foreach ($lists as $list) {
			if (is_string($list) === false || isset(self::LISTS[$list]) === false) {
				$errors[] = "package.json: adminTab list '" . $this->describe(value: $list) . "' is not one of " . $known . '.';
			}
		}

		return $errors;
	}//end listErrors()

	/**
	 * The tab a manifest declares: its title and its lists, once each, in declared order.
	 *
	 * @param array<string, mixed> $manifest The decoded `package.json`.
	 *
	 * @return array{title: string, lists: array<int, string>}|null The tab, or null when the
	 *                                                              manifest declares none or a
	 *                                                              malformed one.
	 *
	 * @spec openspec/changes/vth-cases-run-end-to-end
	 */
	public function parse(array $manifest): ?array {
		$adminTab = ($manifest[self::KEY] ?? null);
		if ($adminTab === null || $this->errors(adminTab: $adminTab) !== []) {
			return null;
		}

		return [
			'title' => trim((string)$adminTab['title']),
			'lists' => array_values(array_unique($adminTab['lists'])),
		];
	}//end parse()

	/**
	 * A value as it reads in a message.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The string, or the type of anything else.
	 *
	 * @spec openspec/changes/vth-cases-run-end-to-end
	 */
	private function describe(mixed $value): string {
		if (is_string($value) === true) {
			return $value;
		}

		return gettype($value);
	}//end describe()
}//end class
