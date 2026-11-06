<?php

/**
 * Dossiq SubjectLinkRule.
 *
 * The RGBZ rule on a `subject` link: a case hangs as the subject under one
 * case only, and a subject chain may not loop back. A link that breaks it is
 * refused with a card notice naming the cases.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Relation
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
 * @spec openspec/changes/case-page-answers-in-card-notices/specs/related-case-linking/spec.md#requirement-a-link-the-rgbz-forbids-is-refused-in-a-card-notice
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Relation;

use OCA\Dossiq\Service\Notice\CaseActNotices;

/**
 * Refuses a subject link the RGBZ forbids.
 *
 * @spec openspec/changes/case-page-answers-in-card-notices/specs/related-case-linking/spec.md#requirement-a-link-the-rgbz-forbids-is-refused-in-a-card-notice
 */
class SubjectLinkRule {

	/**
	 * How far up a subject chain is walked when looking for a loop.
	 *
	 * @var int
	 */
	private const CHAIN_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param CaseRelationStore $store   Reads cases and who links to them.
	 * @param CaseRelationCodec $codec   Reads the links a case holds.
	 * @param CaseActNotices    $notices The refusal notice.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseRelationStore $store,
		private readonly CaseRelationCodec $codec,
		private readonly CaseActNotices $notices,
	) {
	}//end __construct()

	/**
	 * Why the target may not hang as the subject of the origin, or null.
	 *
	 * @param array{origin: array<string, mixed>|null, target: array<string, mixed>|null} $pair The two cases, both readable.
	 * @param array{from: string, to: string, type: string}                               $link The link.
	 *
	 * @return array<string, mixed>|null `{ ok: false, reason, target, other, message, notice }`, or null.
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/related-case-linking/spec.md#requirement-a-link-the-rgbz-forbids-is-refused-in-a-card-notice
	 */
	public function refusal(array $pair, array $link): ?array {
		$target = $this->identifierOf(case: ($pair['target'] ?? []));

		$holder = $this->holderOf(caseId: $link['to']);
		if ($holder !== null && $this->idOf(case: $holder) !== $link['from']) {
			return $this->refused(rule: 'subject_taken', target: $target, other: $this->identifierOf(case: $holder));
		}

		if ($this->hangsUnder(caseId: $link['from'], ancestorId: $link['to']) === true) {
			return $this->refused(rule: 'subject_cycle', target: $target, other: $this->identifierOf(case: ($pair['origin'] ?? [])));
		}

		return null;
	}//end refusal()

	/**
	 * Whether a case hangs, directly or further up, under another as its subject.
	 *
	 * @param string $caseId     The case.
	 * @param string $ancestorId The case it might hang under.
	 *
	 * @return bool
	 */
	private function hangsUnder(string $caseId, string $ancestorId): bool {
		$seen    = [$caseId];
		$steps   = 0;
		$current = $this->holderOf(caseId: $caseId);
		while ($current !== null && $steps < self::CHAIN_LIMIT) {
			$currentId = $this->idOf(case: $current);
			if ($currentId === $ancestorId) {
				return true;
			}

			if (in_array($currentId, $seen, true) === true) {
				return false;
			}

			$seen[]  = $currentId;
			$steps++;
			$current = $this->holderOf(caseId: $currentId);
		}

		return false;
	}//end hangsUnder()

	/**
	 * The case that holds this case as its subject, or null.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<string, mixed>|null
	 */
	private function holderOf(string $caseId): ?array {
		$property = ($this->codec->typedProperty(natureRelationship: 'subject') ?? '');
		foreach ($this->store->relationRows(caseUuid: $caseId, incoming: true) as $row) {
			$rowId = (string)($row['id'] ?? ($row['uuid'] ?? ''));
			if ($rowId === '' || $rowId === $caseId) {
				continue;
			}

			$holder = $this->store->fetchCase(caseUuid: $rowId);
			if ($holder === null) {
				continue;
			}

			if ($this->codec->hasPair(relations: $this->codec->decode(case: $holder), caseId: $caseId, natureRelationship: 'subject') === true
				|| in_array($caseId, ($this->codec->typedLinks(case: $holder)[$property] ?? []), true) === true
			) {
				return $holder;
			}
		}//end foreach

		return null;
	}//end holderOf()

	/**
	 * The refusal for one broken rule.
	 *
	 * @param string $rule   `subject_taken` or `subject_cycle`.
	 * @param string $target The case that would hang as the subject.
	 * @param string $other  The other case the notice names.
	 *
	 * @return array<string, mixed>
	 */
	private function refused(string $rule, string $target, string $other): array {
		$notice = $this->notices->linkRefused(rule: $rule, target: $target, other: $other);

		return [
			'ok' => false,
			'reason' => $rule,
			'target' => $target,
			'other' => $other,
			'message' => $notice['title'] . ' ' . $notice['text'],
			'notice' => $notice,
		];
	}//end refused()

	/**
	 * The uuid of a case row.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string
	 */
	private function idOf(array $case): string {
		return (string)($case['id'] ?? ($case['uuid'] ?? ($case['@self']['id'] ?? '')));
	}//end idOf()

	/**
	 * The number a handler knows a case by, else its title, else its uuid.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string
	 */
	private function identifierOf(array $case): string {
		foreach (['identifier', 'title'] as $field) {
			$value = trim((string)($case[$field] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return $this->idOf(case: $case);
	}//end identifierOf()
}//end class
