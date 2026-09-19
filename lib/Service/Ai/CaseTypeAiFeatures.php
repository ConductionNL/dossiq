<?php

/**
 * Which AI features a case type switched on, and where they appear.
 *
 * 🔴 UNDECLARED MEANS NOTHING RENDERS AND NOTHING IS CALLED. This is the whole
 * of REQ-AIC-01 and it is a privacy rule before it is a feature flag: a case
 * type that says nothing about AI must not have its cases sent anywhere to find
 * out what is available. So the absence of a declaration is answered here,
 * locally, and no request leaves the instance.
 *
 * 🔴 FEATURES ARE NAMED BY HERMIQ'S OWN SLUG. One feature is one thing across
 * the two apps; a dossiq-local alias would be a second vocabulary that drifts,
 * and the drift would look like a feature quietly switching itself off.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Ai
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://dossiq.app
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-a-case-type-declares-which-ai-features-are-on-and-where-they-appear-req-aic-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Ai;

/**
 * Reads a case type's declared AI features.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-a-case-type-declares-which-ai-features-are-on-and-where-they-appear-req-aic-01
 */
class CaseTypeAiFeatures {

	/**
	 * The case detail page.
	 *
	 * @var string
	 */
	public const SURFACE_CASE = 'case';

	/**
	 * The create form.
	 *
	 * @var string
	 */
	public const SURFACE_INTAKE = 'intake';

	/**
	 * Declared, and deliberately off.
	 *
	 * 🔑 `none` IS A DECLARATION, not an absence. An administrator who has
	 * considered a feature and switched it off has said something, and a later
	 * reader needs to tell that apart from a case type nobody has looked at.
	 *
	 * @var string
	 */
	public const SURFACE_NONE = 'none';

	/**
	 * The surfaces a feature may be placed on.
	 *
	 * @var array<int, string>
	 */
	public const SURFACES = [self::SURFACE_CASE, self::SURFACE_INTAKE, self::SURFACE_NONE];

	/**
	 * The case type's configuration key carrying the declaration.
	 *
	 * @var string
	 */
	public const DECLARATION = 'aiFeatures';

	/**
	 * The features a case type declares for one surface.
	 *
	 * @param array<string, mixed> $caseType The case type record.
	 * @param string               $surface  One of SURFACES.
	 *
	 * @return array<int, string> The feature slugs, in declaration order.
	 *
	 * @psalm-return list<string>
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-a-case-type-declares-which-ai-features-are-on-and-where-they-appear-req-aic-01
	 */
	public function onSurface(array $caseType, string $surface): array {
		$features = [];
		foreach ($this->declared(caseType: $caseType) as $slug => $declaredSurface) {
			if ($declaredSurface === $surface && $surface !== self::SURFACE_NONE) {
				$features[] = $slug;
			}
		}

		return $features;
	}//end onSurface()

	/**
	 * Whether this case type has anything to ask hermiq about at all.
	 *
	 * The caller checks this BEFORE it calls, which is what keeps an
	 * undeclared case type off the network entirely rather than merely
	 * rendering nothing afterwards.
	 *
	 * @param array<string, mixed> $caseType The case type record.
	 *
	 * @return bool True when at least one feature is placed on a surface.
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-a-case-type-declares-which-ai-features-are-on-and-where-they-appear-req-aic-01
	 */
	public function anyDeclared(array $caseType): bool {
		foreach ($this->declared(caseType: $caseType) as $surface) {
			if ($surface !== self::SURFACE_NONE) {
				return true;
			}
		}

		return false;
	}//end anyDeclared()

	/**
	 * The declaration, normalised to slug => surface.
	 *
	 * An entry naming a surface this app does not know is dropped rather than
	 * guessed at: placing a feature somewhere nobody asked for is worse than
	 * not placing it, and the author gets a feature that visibly does not
	 * appear rather than one that appears in the wrong place.
	 *
	 * @param array<string, mixed> $caseType The case type record.
	 *
	 * @return array<string, string> slug => surface.
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-a-case-type-declares-which-ai-features-are-on-and-where-they-appear-req-aic-01
	 */
	public function declared(array $caseType): array {
		$raw = ($caseType[self::DECLARATION] ?? null);
		if (is_array($raw) === false) {
			return [];
		}

		$declared = [];
		foreach ($raw as $slug => $surface) {
			if (is_string($slug) === false || trim($slug) === '' || is_string($surface) === false) {
				continue;
			}

			$surface = strtolower(trim($surface));
			if (in_array($surface, self::SURFACES, true) === false) {
				continue;
			}

			$declared[trim($slug)] = $surface;
		}

		return $declared;
	}//end declared()
}//end class
