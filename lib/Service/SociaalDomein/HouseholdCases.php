<?php

/**
 * The other cases of a household, as the case page shows them.
 *
 * Board DqSociaalDomein draws "Andere zaken van dit huishouden" on a social
 * domain case: the cases of the people in the case's family plan. Two kinds of
 * row, kept apart:
 *
 * - A case in THIS domain that the reader may open is shown in full: its
 *   number, title, case type and status, and a link.
 * - A case in ANOTHER domain is never read here. It appears only after the
 *   reader looked it up on a chosen ground, through
 *   {@see CrossDomainExistence::lookUp()}, which logs every lookup and answers
 *   with nothing but `exists`, `domain` and `contact`. Those three fields are
 *   all such a row carries.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\SociaalDomein
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
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\SociaalDomein;

use OCA\Dossiq\Service\Support\TrustedRevealRead;

/**
 * Builds the household rows of a case.
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
class HouseholdCases {
	/**
	 * The case type categories of each domain, as the case types spell them.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const DOMAIN_CATEGORIES = SociaalDomeinStore::DOMAIN_CATEGORIES;

	/**
	 * Constructor.
	 *
	 * @param SociaalDomeinStore   $store     The one reader of the social domain register.
	 * @param CrossDomainExistence $existence The logged cross-domain lookup.
	 */
	public function __construct(
		private readonly SociaalDomeinStore $store,
		private readonly CrossDomainExistence $existence,
	) {
	}//end __construct()

	/**
	 * The BSNs of the people in the case's family plan, the case's own requester left in.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, string> The BSNs, or none without a plan.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function householdBsns(array $case): array {
		$plan = $this->store->read(schema: 'gezinsplan', id: (string)($case['gezinsplanId'] ?? ''));
		if ($plan === null) {
			return [];
		}

		$bsns = [];
		foreach ((array)($plan['gezinsleden'] ?? []) as $member) {
			if (is_array($member) === false) {
				continue;
			}

			$bsn = trim((string)($member['bsn'] ?? ''));
			if ($bsn !== '') {
				$bsns[$bsn] = true;
			}
		}

		// A numeric BSN comes back from array_keys() as an int; a BSN is a string.
		return array_map('strval', array_keys($bsns));
	}//end householdBsns()

	/**
	 * The domain a case type category belongs to, or '' when it is none of the three.
	 *
	 * @param string $category The case type category.
	 *
	 * @return string The domain.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function domainOf(string $category): string {
		return $this->store->domainOfCategory(category: $category);
	}//end domainOf()

	/**
	 * The domain of a case, from its case type's category, or '' when none of the three.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string The domain.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function ownDomain(array $case): string {
		$cache = [];
		$type  = $this->caseType(id: (string)($case['caseType'] ?? ''), cache: $cache);

		return $this->domainOf(category: trim((string)($type['category'] ?? '')));
	}//end ownDomain()

	/**
	 * The household's other cases in this case's own domain (its case type
	 * category) that the reader may open.
	 *
	 * @param array<string, mixed>   $case    The case the page shows.
	 * @param array<int, string>     $bsns    The household's BSNs.
	 * @param callable(string): bool $canRead Whether the reader may open a case.
	 *
	 * @return array<int, array<string, mixed>> One row per case: `caseId`,
	 *         `identifier`, `title`, `caseTypeTitle`, `statusLabel`,
	 *         `statusRole`, `access` (`full`).
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function sameDomain(array $case, array $bsns, callable $canRead): array {
		$caseTypes = [];
		$ownType   = $this->caseType(id: (string)($case['caseType'] ?? ''), cache: $caseTypes);
		$category  = trim((string)($ownType['category'] ?? ''));
		if ($category === '') {
			return [];
		}

		$rows = [];
		foreach ($bsns as $bsn) {
			// The BSN is shown only on request in OpenRegister; finding people by it is trusted server work.
			$people = (new TrustedRevealRead())->run(fn () => $this->store->rows(schema: 'brpPerson', filters: ['citizenServiceNumber' => $bsn]));
			foreach ($people as $person) {
				foreach ($this->casesOf(personId: (string)($person['id'] ?? '')) as $other) {
					$otherId = (string)($other['id'] ?? '');
					if ($otherId === (string)($case['id'] ?? '') || isset($rows[$otherId]) === true || $canRead($otherId) !== true) {
						continue;
					}

					$type = $this->caseType(id: (string)($other['caseType'] ?? ''), cache: $caseTypes);
					if (trim((string)($type['category'] ?? '')) === $category) {
						$rows[$otherId] = $this->row(case: $other, caseType: $type);
					}
				}
			}
		}

		return array_values($rows);
	}//end sameDomain()

	/**
	 * Read the case a page shows.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The case, or null when it does not resolve.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function readCase(string $caseId): ?array {
		return $this->store->read(schema: 'case', id: $caseId);
	}//end readCase()

	/**
	 * The cases a person brought in, each with an id.
	 *
	 * @param string $personId The BRP person uuid.
	 *
	 * @return array<int, array<string, mixed>> The cases.
	 */
	private function casesOf(string $personId): array {
		if ($personId === '') {
			return [];
		}

		return array_values(
			array_filter(
				$this->store->rows(schema: 'case', filters: ['initiatorSourceId' => $personId]),
				static fn (array $row): bool => (string)($row['id'] ?? '') !== ''
			)
		);
	}//end casesOf()

	/**
	 * A same-domain row, in full.
	 *
	 * @param array<string, mixed> $case     The case.
	 * @param array<string, mixed> $caseType Its case type.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(array $case, array $caseType): array {
		return [
			'caseId'        => (string)$case['id'],
			'identifier'    => (string)($case['identifier'] ?? ''),
			'title'         => (string)($case['title'] ?? ''),
			'caseTypeTitle' => (string)($caseType['title'] ?? ''),
			'statusLabel'   => (string)($case['statusPublicLabel'] ?? ''),
			'statusRole'    => (string)($case['statusRole'] ?? ''),
			'access'        => 'full',
		];
	}//end row()

	/**
	 * Look the household up in the other domains, on a chosen ground: one
	 * logged lookup per person, and nothing but `exists`, `domain` and
	 * `contact` per domain that holds an open case.
	 *
	 * @param array<int, string> $bsns      The household's BSNs.
	 * @param string             $ownDomain The case's own domain, which is left out.
	 * @param string             $ground    The chosen ground.
	 * @param string             $requester The uid of whoever is asking.
	 *
	 * @return array<int, array{exists: bool, domain: string, contact: string}> One row per domain and contact.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-another-domain-answers-only-that-a-case-exists-req-xdv-01
	 */
	public function otherDomains(array $bsns, string $ownDomain, string $ground, string $requester): array {
		$this->existence->assertGround(ground: $ground);

		$rows = [];
		foreach ($bsns as $bsn) {
			foreach ($this->existence->lookUp(bsn: $bsn, ownDomain: $ownDomain, ground: $ground, requester: $requester) as $found) {
				$row = [
					'exists'  => (bool)($found['exists'] ?? false),
					'domain'  => (string)($found['domain'] ?? ''),
					'contact' => (string)($found['contact'] ?? ''),
				];
				$rows[$row['domain'] . '|' . $row['contact']] = $row;
			}
		}

		return array_values($rows);
	}//end otherDomains()

	/**
	 * A case type, read once per request.
	 *
	 * @param string                              $id    The case type uuid.
	 * @param array<string, array<string, mixed>> $cache The case types read so far.
	 *
	 * @return array<string, mixed> The case type, or an empty array.
	 */
	private function caseType(string $id, array &$cache): array {
		if ($id === '') {
			return [];
		}

		if (array_key_exists($id, $cache) === false) {
			$cache[$id] = ($this->store->read(schema: 'caseType', id: $id) ?? []);
		}

		return $cache[$id];
	}//end caseType()
}//end class
