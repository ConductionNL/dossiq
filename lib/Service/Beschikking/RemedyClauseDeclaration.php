<?php

/**
 * The remedy open against a case type's decisions, declared once.
 *
 * A besluit has to say how to object to it: what you may lodge, within how
 * long, and with whom. Today that sentence lives in whichever document template
 * somebody last edited, which means a change in the law is forty template edits
 * and a guess about which ones were missed.
 *
 * 🔑 SO THE CASE TYPE DECLARES IT AND THE DOCUMENT PRINTS IT (D-4). Two case
 * types sharing one template print different terms, because the term is the
 * case type's and not the template's, and a change in the law is one
 * configuration change.
 *
 * 🔴 AN ABSENT DECLARATION IS NOT A DEFAULT CLAUSE. It warns at publication and
 * names the case type. Filling in six weeks and a bezwaar because that is
 * usually right would print a clause nobody chose onto a decision somebody has
 * to act on, and there would be nothing anywhere saying it was a guess.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
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
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

/**
 * Reads the remedy a case type declares, and writes the clause its decisions print.
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md
 */
class RemedyClauseDeclaration {

	/**
	 * The case type property holding the declaration.
	 *
	 * @var string
	 */
	public const DECLARATION = 'remedy';

	/**
	 * The kinds this app knows how to write a sentence for.
	 *
	 * A kind outside this list is printed literally rather than refused: a
	 * bestuursorgaan with a remedy nobody here anticipated should still get its
	 * clause on the decision, and a refusal would take the whole decision down
	 * over a word.
	 *
	 * @var array<string, array{verb: string, english: string}>
	 */
	private const KINDS = [
		'bezwaar' => ['verb' => 'bezwaar maken', 'english' => 'lodge an objection'],
		'beroep' => ['verb' => 'beroep instellen', 'english' => 'appeal'],
		'zienswijze' => ['verb' => 'een zienswijze indienen', 'english' => 'submit a view'],
	];

	/**
	 * Whether a case type declares a remedy at all.
	 *
	 * Separate from {@see self::declarationFor()} because the two answer
	 * different questions: this one is what publication warns about, and that
	 * one is what the document prints.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return bool True when a kind, a term and a body are all named.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md#requirement-the-remedy-clause-is-declared-on-the-case-type-and-printed-req-dec-03
	 */
	public function isDeclared(array $caseType): bool {
		$declaration = $this->declarationFor(caseType: $caseType);

		return ($declaration['kind'] !== '' && $declaration['termDays'] > 0 && $declaration['body'] !== '');
	}//end isDeclared()

	/**
	 * The declaration on a case type, normalised.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array{kind: string, termDays: int, body: string, enabled: bool} The declaration.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md#requirement-the-remedy-clause-is-declared-on-the-case-type-and-printed-req-dec-03
	 */
	public function declarationFor(array $caseType): array {
		$declared = ($caseType[self::DECLARATION] ?? null);
		if (is_string($declared) === true) {
			$decoded = json_decode($declared, true);
			$declared = [];
			if (is_array($decoded) === true) {
				$declared = $decoded;
			}
		}

		if (is_array($declared) === false) {
			$declared = [];
		}

		return [
			'kind' => strtolower(trim((string)($declared['kind'] ?? ''))),
			'termDays' => max(0, (int)($declared['termDays'] ?? 0)),
			'body' => trim((string)($declared['body'] ?? '')),
			'enabled' => (($declared['enabled'] ?? true) !== false),
		];
	}//end declarationFor()

	/**
	 * How many days the remedy term runs, or 0 when none is declared.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return int The term in days.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md#requirement-sending-a-decision-starts-the-remedy-term-req-dec-04
	 */
	public function termDaysFor(array $caseType): int {
		$declaration = $this->declarationFor(caseType: $caseType);
		if ($declaration['enabled'] === false) {
			return 0;
		}

		return $declaration['termDays'];
	}//end termDaysFor()

	/**
	 * The clause this case type's decisions print, in Dutch.
	 *
	 * Two sentences, because one sentence carrying a verb, a term and a body is
	 * the sentence people skim past. The first says they may act, the second
	 * says by when and where.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return string The clause, '' when the case type declares no remedy.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md#requirement-the-remedy-clause-is-declared-on-the-case-type-and-printed-req-dec-03
	 */
	public function clauseFor(array $caseType): string {
		$declaration = $this->declarationFor(caseType: $caseType);
		if ($declaration['enabled'] === false || $this->isDeclared(caseType: $caseType) === false) {
			return '';
		}

		$verb = ($declaration['kind'] . ' indienen');
		if (array_key_exists($declaration['kind'], self::KINDS) === true) {
			$verb = self::KINDS[$declaration['kind']]['verb'];
		}

		return 'U kunt ' . $verb . ' tegen dit besluit. Doe dat binnen '
			. $declaration['termDays'] . ' dagen bij ' . $declaration['body'] . '.';
	}//end clauseFor()

	/**
	 * The same clause in English, for a decision written in English.
	 *
	 * A separate sentence rather than a translation of the Dutch one, for the
	 * reason the acknowledgement records: these are two letters, not one letter
	 * with branches.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return string The clause, '' when the case type declares no remedy.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md#requirement-the-remedy-clause-is-declared-on-the-case-type-and-printed-req-dec-03
	 */
	public function clauseInEnglishFor(array $caseType): string {
		$declaration = $this->declarationFor(caseType: $caseType);
		if ($declaration['enabled'] === false || $this->isDeclared(caseType: $caseType) === false) {
			return '';
		}

		$verb = ('submit a ' . $declaration['kind']);
		if (array_key_exists($declaration['kind'], self::KINDS) === true) {
			$verb = self::KINDS[$declaration['kind']]['english'];
		}

		return 'You can ' . $verb . ' against this decision. Do that within '
			. $declaration['termDays'] . ' days, with ' . $declaration['body'] . '.';
	}//end clauseInEnglishFor()

	/**
	 * What publishing this case type should say out loud about the remedy.
	 *
	 * A warning and not a refusal: a case type whose decisions genuinely carry
	 * no remedy exists, and refusing would make it unpublishable. What must not
	 * happen is the clause going missing quietly, because a besluit that does
	 * not say how to object to it is one somebody cannot object to in time.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, string> The warnings, empty when the declaration is complete.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md#requirement-the-remedy-clause-is-declared-on-the-case-type-and-printed-req-dec-03
	 */
	public function publicationWarnings(array $caseType): array {
		$declaration = $this->declarationFor(caseType: $caseType);
		if ($declaration['enabled'] === false) {
			return [];
		}

		if ($this->isDeclared(caseType: $caseType) === true) {
			return [];
		}

		$title = trim((string)($caseType['title'] ?? ''));
		if ($title === '') {
			$title = 'this case type';
		}

		return [
			'The decisions of ' . $title . ' declare no remedy, so they print no '
			. 'bezwaarclausule. Name the kind, the term in days and the body it is lodged with.',
		];
	}//end publicationWarnings()
}//end class
