<?php

/**
 * Who may see a figure, declared on the widget and enforced on the read.
 *
 * 🔑 THE DECLARATION IS ON THE WIDGET, IN THE MANIFEST (D-1). Every other
 * property of a dossiq widget lives there, so the question "who sees this
 * figure" is answered by reading one declaration rather than three components.
 * Scoping the PAGE instead, which is what a dashboard product usually offers,
 * would take a handler's own work away from them to hide a teamleider's
 * figure sitting beside it.
 *
 * 🔴 THE BROWSER IS NOT THE CHECK (ADR-004, D-2). A widget hidden with a
 * `v-if` is the same defect as a frontend route standing in for an access
 * check: the number still arrives in the page's payload and anyone who opens
 * the network tab reads it. So this class is used on the READ, to take the
 * fields a reader may not see OUT of the answer, and the widget then has
 * nothing to render.
 *
 * 🔴 AN UNRESOLVABLE ROLE HIDES THE WIDGET (ADR-102, D-3). A widget declaring
 * a group that no longer exists, after a rename or a typo, is not rendered and
 * the failure is reported. Defaulting to visible would turn a configuration
 * mistake into a disclosure, and it would do it quietly, which is worse: the
 * figure is simply there one day for everyone.
 *
 * 🔴 A WIDGET WITH NO DECLARATION IS NOT SILENTLY PUBLIC HERE, EITHER. It is
 * public, because that is what every dossiq widget was until this change and
 * failing them closed would empty every dashboard on the fleet. What stops
 * that from becoming the permanent answer is not this class but
 * `WidgetDeclaresRolesTest`, which fails the build on a dashboard widget that
 * declares nothing and carries no reason.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Dashboard;

/**
 * Reads the widget role declarations, and says what one reader may receive.
 *
 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md
 */
class WidgetRoles {
	/**
	 * The key a widget declares its audience under.
	 *
	 * @var string
	 */
	public const ROLES_KEY = 'roles';

	/**
	 * Every widget definition on every dashboard page of a manifest.
	 *
	 * Dashboard pages only, and that is the scope of this change rather than
	 * an omission: a widget on a DETAIL page renders the record the reader has
	 * already been granted, and its access question is that record's grants
	 * (`case-grants-name-their-source`), not a role on a tile.
	 *
	 * @param array<string, mixed> $manifest The app manifest.
	 *
	 * @return array<int, array<string, mixed>> The widget definitions, in manifest order.
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-every-dossiq-widget-declares-who-may-see-it-req-wrd-01
	 */
	public function dashboardWidgets(array $manifest): array {
		$widgets = [];
		foreach (($manifest['pages'] ?? []) as $page) {
			if (is_array($page) === false || ($page['type'] ?? '') !== 'dashboard') {
				continue;
			}

			foreach ($this->definitionsOn(page: $page) as $widget) {
				$widgets[] = $widget;
			}
		}

		return $widgets;
	}//end dashboardWidgets()

	/**
	 * The widget definitions declared on one page.
	 *
	 * A placement (`widgetKey` plus grid coordinates) is not a definition: it
	 * says WHERE a widget goes, and counting it as one would ask the same
	 * widget to declare its audience twice, in two places that can disagree.
	 *
	 * @param array<string, mixed> $page One manifest page.
	 *
	 * @return array<int, array<string, mixed>> The definitions.
	 */
	public function definitionsOn(array $page): array {
		$candidates = array_merge(
			(is_array($page['config']['widgets'] ?? null) === true ? $page['config']['widgets'] : []),
			(is_array($page['widgets'] ?? null) === true ? $page['widgets'] : []),
		);

		$definitions = [];
		foreach ($candidates as $widget) {
			if (is_array($widget) === true && isset($widget['widgetKey']) === false && isset($widget['id']) === true) {
				$definitions[] = $widget;
			}
		}

		return $definitions;
	}//end definitionsOn()

	/**
	 * The roles one widget declares.
	 *
	 * @param array<string, mixed> $widget One widget definition.
	 *
	 * @return array<int, string> The roles, empty when it declares none.
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-every-dossiq-widget-declares-who-may-see-it-req-wrd-01
	 */
	public function rolesOf(array $widget): array {
		$declared = ($widget[self::ROLES_KEY] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$roles = [];
		foreach ($declared as $role) {
			$role = trim((string)$role);
			if ($role !== '') {
				$roles[] = $role;
			}
		}

		return $roles;
	}//end rolesOf()

	/**
	 * Whether one reader may see one widget.
	 *
	 * Three answers, because two would hide the one that matters. `visible`
	 * and `hidden` are the ordinary pair; `unresolvable` is a widget declaring
	 * a role no group answers to, which is hidden from EVERYONE and reported,
	 * rather than hidden from the people who happen not to hold it.
	 *
	 * @param array<string, mixed> $widget The widget definition.
	 * @param array<int, string> $held The groups this reader is in.
	 * @param array<int, string> $known Every group that exists on this instance.
	 *
	 * @return string One of `visible`, `hidden`, `unresolvable`.
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-a-widget-answers-nothing-to-a-reader-who-may-not-see-it-req-wrd-02
	 */
	public function verdictFor(array $widget, array $held, array $known): string {
		$roles = $this->rolesOf(widget: $widget);
		if ($roles === []) {
			// Undeclared is public, because every dossiq widget was until this
			// change and failing them closed would empty every dashboard on
			// the fleet overnight. The structural test is what keeps this from
			// becoming the permanent answer.
			return 'visible';
		}

		foreach ($roles as $role) {
			if (in_array($role, $known, true) === false) {
				return 'unresolvable';
			}
		}

		foreach ($roles as $role) {
			if (in_array($role, $held, true) === true) {
				return 'visible';
			}
		}

		return 'hidden';
	}//end verdictFor()

	/**
	 * The payload fields one widget would show.
	 *
	 * Both the value and the fields its caption interpolates: a tile hidden
	 * with its caption tokens left in the payload leaks the same figure one
	 * sentence further down.
	 *
	 * @param array<string, mixed> $widget The widget definition.
	 *
	 * @return array<int, string> The field names.
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-a-widget-answers-nothing-to-a-reader-who-may-not-see-it-req-wrd-02
	 */
	public function fieldsOf(array $widget): array {
		$content = ($widget['content'] ?? ($widget['props']['content'] ?? []));
		if (is_array($content) === false) {
			return [];
		}

		$fields = [];
		$value = trim((string)($content['valueField'] ?? ''));
		if ($value !== '') {
			$fields[] = $value;
		}

		$caption = (string)($content['caption'] ?? '');
		if ($caption !== '' && preg_match_all('/\{([A-Za-z0-9_]+)\}/', $caption, $matches) > 0) {
			foreach ($matches[1] as $token) {
				if (in_array($token, $fields, true) === false) {
					$fields[] = $token;
				}
			}
		}

		return $fields;
	}//end fieldsOf()

	/**
	 * The fields to take out of a dashboard payload for one reader.
	 *
	 * A field is withheld only when EVERY widget that shows it is hidden from
	 * this reader. Two tiles may read one count, and removing it because one
	 * of them is restricted would blank the other for somebody entitled to it.
	 *
	 * @param array<string, mixed> $manifest The app manifest.
	 * @param array<int, string> $held The groups this reader is in.
	 * @param array<int, string> $known Every group that exists.
	 *
	 * @return array{withhold: array<int, string>, unresolvable: array<int, string>}
	 *         The fields to remove, and the widgets that could not be resolved.
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-a-widget-answers-nothing-to-a-reader-who-may-not-see-it-req-wrd-02
	 */
	public function withheldFields(array $manifest, array $held, array $known): array {
		$shown = [];
		$restricted = [];
		$unresolvable = [];

		foreach ($this->dashboardWidgets(manifest: $manifest) as $widget) {
			$verdict = $this->verdictFor(widget: $widget, held: $held, known: $known);
			$fields = $this->fieldsOf(widget: $widget);

			if ($verdict === 'unresolvable') {
				$unresolvable[] = (string)$widget['id'];
			}

			foreach ($fields as $field) {
				if ($verdict === 'visible') {
					$shown[$field] = true;
					continue;
				}

				$restricted[$field] = true;
			}
		}

		$withhold = [];
		foreach (array_keys($restricted) as $field) {
			if (isset($shown[$field]) === false) {
				$withhold[] = (string)$field;
			}
		}

		return ['withhold' => $withhold, 'unresolvable' => $unresolvable];
	}//end withheldFields()

	/**
	 * A dashboard payload with the fields this reader may not see taken out.
	 *
	 * Removed, not zeroed. A zero is a figure, and a reader who is shown one
	 * has been told something false about the caseload rather than nothing at
	 * all.
	 *
	 * @param array<string, mixed> $payload The computed payload.
	 * @param array<int, string> $withhold The fields to remove.
	 *
	 * @return array<string, mixed> The payload, narrowed.
	 *
	 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md#requirement-a-widget-answers-nothing-to-a-reader-who-may-not-see-it-req-wrd-02
	 */
	public function narrow(array $payload, array $withhold): array {
		foreach ($withhold as $field) {
			unset($payload[$field]);
		}

		return $payload;
	}//end narrow()
}//end class
