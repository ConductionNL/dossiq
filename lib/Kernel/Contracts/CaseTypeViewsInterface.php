<?php

/**
 * Contract of CaseTypeViews
 *
 * The public surface of CaseTypeViews that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of CaseTypeViews.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypeViewsInterface {

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
	 * The views every user gets for case types the instance names by
	 * identifier (lib/Settings/case-type-views.json).
	 *
	 * @return array<int, array{slug: string, name: string, description: string, caseTypeIdentifier: string}> The declared views.
	 *
	 * @spec openspec/specs/case-lists/spec.md
	 */
	public function declaredViews(): array;

	/**
	 * The declared views whose case type this instance has, each filtering on it,
	 * under "More default views" (`secondary`) and described so the panel does
	 * not show the case type's id while it resolves the filter.
	 *
	 * @param array<string, array{id: string, title: string}> $byIdentifier The instance's case types, by identifier.
	 *
	 * @return array<int, array{slug: string, name: string, description: string, secondary: bool, filters: array<string, mixed>}> The views.
	 *
	 * @spec openspec/specs/case-lists/spec.md
	 */
	public function instanceViews(array $byIdentifier): array;

	/**
	 * The menu entries for the case types a user pinned, in their order.
	 *
	 * @param array<int, array{id: string, title: string, icon?: string}> $caseTypes The pinned case types.
	 *
	 * @return array<int, array{id: string, page: string, name: string, icon?: string}> The entries.
	 *
	 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
	 */
	public function menuEntries(array $caseTypes): array;

	/**
	 * The default views to add for the case types a user pinned: one per case
	 * type, filtering on it with the working-list rule of the Woo view, with
	 * the case type's icon for its menu entry, described by the case type's
	 * title and placed under "More default views" (`secondary`). The Woo
	 * request case type is left out: the page ships that view itself.
	 *
	 * @param array<int, array{id: string, title: string, icon?: string}> $caseTypes The pinned case types.
	 *
	 * @return array<int, array{slug: string, name: string, description: string, secondary: bool, filters: array<string, mixed>, icon?: string}>
	 *
	 * @spec openspec/changes/case-type-pins-are-views/specs/case-type-navigation/spec.md
	 */
	public function views(array $caseTypes): array;
}//end interface
