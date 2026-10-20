<?php

/**
 * Case types as views on the case list.
 *
 * A case type a user pinned is a view of the Cases page, not a menu entry of
 * dossiq's own (Ruben, 19 October 2026): the library's "My views" section at
 * the bottom of the menu holds the views a user added. This class names that
 * view and what it filters on, and the menu entry that points at it, so the
 * manifest delta and the repair step that converts old pins agree.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The view of the case list for one case type, and its menu entry.
 *
 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
 */
final class CaseTypeViews {

	/**
	 * The page the views filter.
	 *
	 * @var string
	 */
	public const PAGE = 'Cases';

	/**
	 * The manifest key a page declares its default views under.
	 *
	 * @var string
	 */
	public const DEFAULT_VIEWS_KEY = 'defaultSavedViews';

	/**
	 * The user preference (PreferencesController's `pref_` namespace) the
	 * library keeps the views a user added to the menu under.
	 *
	 * @var string
	 */
	public const MENU_PREFERENCE = 'pref_cn_saved_view_menu';

	/**
	 * The slug of the Woo requests view the Cases page ships for everybody.
	 *
	 * @var string
	 */
	public const WOO_VIEW = 'woo-requests';

	/**
	 * The slug prefix of a per-user case type view.
	 *
	 * @var string
	 */
	public const SLUG_PREFIX = 'case-type-';

	/**
	 * The slug of the view for one case type: the shipped Woo view for the Woo
	 * request case type, a per-user one for every other.
	 *
	 * @param string $caseTypeId The case type uuid.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
	 */
	public function slugFor(string $caseTypeId): string {
		if ($caseTypeId === MenuCaseTypesService::DEFAULT_CASE_TYPE) {
			return self::WOO_VIEW;
		}

		return self::SLUG_PREFIX . $caseTypeId;
	}//end slugFor()

	/**
	 * The default views to add for the case types a user pinned: one per case
	 * type, filtering on it with the working-list rule of the Woo view. The
	 * Woo request case type is left out: the page ships that view itself.
	 *
	 * @param array<int, array{id: string, title: string}> $caseTypes The pinned case types.
	 *
	 * @return array<int, array{slug: string, name: string, filters: array<string, mixed>}> The views.
	 *
	 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
	 */
	public function views(array $caseTypes): array {
		$views = [];
		foreach ($caseTypes as $caseType) {
			$id = (string)$caseType['id'];
			if ($this->slugFor(caseTypeId: $id) === self::WOO_VIEW) {
				continue;
			}

			$views[] = [
				'slug' => $this->slugFor(caseTypeId: $id),
				'name' => (string)$caseType['title'],
				'filters' => ['caseType' => $id, 'statusHiddenInLists' => false, 'isDraft' => false],
			];
		}

		return $views;
	}//end views()

	/**
	 * The menu entries for the case types a user pinned, in their order.
	 *
	 * @param array<int, array{id: string, title: string}> $caseTypes The pinned case types.
	 *
	 * @return array<int, array{id: string, page: string, name: string}> The entries.
	 *
	 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
	 */
	public function menuEntries(array $caseTypes): array {
		return array_map(
			fn (array $caseType): array => [
				'id' => 'default:' . $this->slugFor(caseTypeId: (string)$caseType['id']),
				'page' => self::PAGE,
				'name' => (string)$caseType['title'],
			],
			array_values($caseTypes)
		);
	}//end menuEntries()
}//end class
