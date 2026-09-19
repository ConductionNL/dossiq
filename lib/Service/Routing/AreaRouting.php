<?php

/**
 * The oldest routing rule in Dutch municipal work: the area team handles the area.
 *
 * 🔑 THE AREA IS READ OFF THE CASE, NOT RESOLVED PER DECISION (D-5). A case
 * carries the wijk and the buurt it was resolved into when its address was
 * set. Asking a boundary service at routing time would make every routing
 * decision depend on that service being up, and an unreachable service would
 * route the case to nobody with nothing to say why.
 *
 * 🔑 `districtTeam` GETS ITS READER HERE. It has been declared on the sociaal
 * domein schemas with no PHP reading it, which is a configuration field an
 * administrator can fill in and watch do nothing. It wins over the rule's own
 * map, because a value written on the case is a decision somebody made about
 * THIS case.
 *
 * 🔴 A CASE OUTSIDE EVERY BOUNDARY IS A CASE, NOT AN ERROR (D-6). Addresses
 * fall outside boundaries: new developments, water, an address abroad, a case
 * with no address at all. So the resolution always answers, and it says
 * whether it answered by falling back. Silence there is routing that did
 * nothing while looking like routing that worked.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Routing
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
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Routing;

/**
 * Turns the area held on a case into the team and role a rule routes to.
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */
class AreaRouting {
	/**
	 * The case field naming a team chosen for this case directly.
	 *
	 * @var string
	 */
	public const DISTRICT_TEAM = 'districtTeam';

	/**
	 * The case fields the predicate may read, in the order it reads them.
	 *
	 * District before neighbourhood, because a rule written over wijken is
	 * the common one and a buurt rule is the exception that overrides it.
	 *
	 * @var array<int, string>
	 */
	public const AREA_FIELDS = ['district', 'neighbourhood'];

	/**
	 * The case-type field naming where a case outside every boundary goes.
	 *
	 * @var string
	 */
	public const FALLBACK_ROLE_TYPE = 'areaFallbackRoleType';

	/**
	 * The team and role one rule resolves to for one case.
	 *
	 * Always answers. `fallbackUsed` is the honest half: it says the address
	 * could not be placed, so a list can find those cases and an administrator
	 * can see that a boundary set needs attention.
	 *
	 * @param array<string, mixed> $rule The routing rule.
	 * @param array<string, mixed> $case The case, carrying its held area.
	 * @param array<string, mixed>|null $caseType The case type, carrying the fallback.
	 *
	 * @return array{roleType: string, team: string, fallbackUsed: bool, reason: string}
	 *         What to route to, and whether the area decided it.
	 *
	 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md#requirement-the-case-holds-the-area-it-is-in-and-routing-reads-it-req-rtp-04
	 */
	public function resolve(array $rule, array $case, ?array $caseType = null): array {
		$roleType = (string)($rule['roleType'] ?? '');
		$declaredTeam = trim((string)($rule['team'] ?? ''));

		// A team written on the case itself wins. `districtTeam` is where a
		// gemeente records that THIS case belongs to THAT area team, whatever
		// the address resolved to, and a rule's map overruling it would make
		// the field unusable the moment a rule existed.
		$onTheCase = trim((string)($case[self::DISTRICT_TEAM] ?? ''));
		if ($onTheCase !== '') {
			return [
				'roleType' => $roleType,
				'team' => $onTheCase,
				'fallbackUsed' => false,
				'reason' => '',
			];
		}

		$map = ($rule['areaTeams'] ?? null);
		if (is_array($map) === false || $map === []) {
			// The rule says nothing about area, so this is an ordinary rule
			// and the fallback does not apply to it. Answering `fallbackUsed`
			// here would mark every case in the gemeente.
			return [
				'roleType' => $roleType,
				'team' => $declaredTeam,
				'fallbackUsed' => false,
				'reason' => '',
			];
		}

		foreach (self::AREA_FIELDS as $field) {
			$value = trim((string)($case[$field] ?? ''));
			if ($value === '') {
				continue;
			}

			$team = $this->lookup(map: $map, value: $value);
			if ($team !== '') {
				return [
					'roleType' => $roleType,
					'team' => $team,
					'fallbackUsed' => false,
					'reason' => '',
				];
			}
		}

		return $this->fallback(rule: $rule, case: $case, caseType: $caseType);
	}//end resolve()

	/**
	 * The team declared for one area value, matched without case sensitivity.
	 *
	 * A boundary set writes `Zuid` and an administrator types `zuid`. A match
	 * that failed on the capital would send every case in the wijk to the
	 * fallback, which is a rule that looks configured and routes nowhere.
	 *
	 * @param array<string, mixed> $map The rule's area-to-team map.
	 * @param string $value The area held on the case.
	 *
	 * @return string The team, '' when the map does not name this area.
	 */
	private function lookup(array $map, string $value): string {
		foreach ($map as $area => $team) {
			if (strcasecmp(trim((string)$area), $value) === 0) {
				return trim((string)$team);
			}
		}

		return '';
	}//end lookup()

	/**
	 * Where a case that could not be placed goes, and what to say about it.
	 *
	 * @param array<string, mixed> $rule The routing rule.
	 * @param array<string, mixed> $case The case.
	 * @param array<string, mixed>|null $caseType The case type.
	 *
	 * @return array{roleType: string, team: string, fallbackUsed: bool, reason: string} The fallback.
	 *
	 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md#requirement-the-case-holds-the-area-it-is-in-and-routing-reads-it-req-rtp-04
	 */
	private function fallback(array $rule, array $case, ?array $caseType): array {
		$held = [];
		foreach (self::AREA_FIELDS as $field) {
			$value = trim((string)($case[$field] ?? ''));
			if ($value !== '') {
				$held[] = $value;
			}
		}

		$reason = 'This case holds no area, so the case type\'s fallback was used.';
		if ($held !== []) {
			$reason = sprintf(
				'No team is declared for %s, so the case type\'s fallback was used.',
				implode(', ', $held)
			);
		}

		$declared = trim((string)($caseType[self::FALLBACK_ROLE_TYPE] ?? ''));
		$roleType = (string)($rule['roleType'] ?? '');
		if ($declared !== '') {
			$roleType = $declared;
		}

		return [
			// A case type that declares no fallback keeps the rule's own
			// roleType: the work reaches the pool it always would have, and
			// the flag is what says the area did not decide it. Routing to
			// nobody would be a case nobody sees.
			'roleType' => $roleType,
			'team' => '',
			'fallbackUsed' => true,
			'reason' => $reason,
		];
	}//end fallback()
}//end class
