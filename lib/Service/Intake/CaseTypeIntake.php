<?php

/**
 * Dossiq Case Type Intake
 *
 * What a case type's `portalIntake` does to a received case of that type,
 * whichever path wrote it: the origin written into the answers as sent, the
 * requester's party, and the answers that answer the case type's own
 * questions as property entries (decision a1; procedures are configuration,
 * decision 182). Pure: it reads the case and the case type and answers the
 * fields to merge, and asks a callback for the property entries.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

/**
 * The changes a received case gets from its case type's intake.
 *
 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
 */
class CaseTypeIntake {

	/**
	 * The case type key that holds the intake.
	 */
	public const KEY = 'portalIntake';

	private const NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';

	/**
	 * The case type's intake, or [] when it declares none.
	 *
	 * @param array<string, mixed> $caseType The effective case type.
	 *
	 * @return array{answersAt: string, origin: string, party: bool, properties: bool, dossierAnswer: string, copy: array<string, string>}|array{}
	 *
	 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
	 */
	public function configOf(array $caseType): array {
		$intake = ($caseType[self::KEY] ?? null);
		if (is_array($intake) === false || $intake === []) {
			return [];
		}

		return [
			'answersAt' => $this->name(value: ($intake['answersAt'] ?? '')),
			'origin' => trim((string)($intake['origin'] ?? '')),
			'party' => (($intake['party'] ?? false) === true),
			'properties' => (($intake['properties'] ?? false) === true),
			'dossierAnswer' => $this->name(value: ($intake['dossierAnswer'] ?? '')),
			'copy' => $this->copyOf(copy: ($intake['copy'] ?? [])),
		];
	}//end configOf()

	/**
	 * The fields to merge into the received case, or [] when there are none.
	 *
	 * @param array<string, mixed>                                      $case          The case as it is about to be stored.
	 * @param array<string, mixed>                                      $config        The case type's intake (configOf()).
	 * @param callable(array<string, mixed>): array<int, array<string, string>> $propertiesOf Property entries for answers.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
	 */
	public function changesFor(array $case, array $config, callable $propertiesOf): array {
		if ($config === []) {
			return [];
		}

		$changes = [];
		$answers = $this->answersOf(case: $case, config: $config);

		if ($config['answersAt'] !== '') {
			$kept = $this->keptAnswers(case: $case, config: $config, answers: $answers);
			if ($kept !== $answers) {
				$answers = $kept;
				$changes[$config['answersAt']] = $answers;
			}
		}

		$subject = trim((string)($case['portalSubject'] ?? ''));
		if ($config['party'] === true && $subject !== '' && trim((string)($case['portalParty'] ?? '')) === '') {
			$changes['portalParty'] = 'subject:' . $subject;
		}

		if ($config['properties'] === true) {
			$entries = $this->newEntries(case: $case, entries: $propertiesOf($answers));
			if ($entries !== []) {
				$changes['properties'] = array_merge($this->entriesOf(case: $case), $entries);
			}
		}

		return $changes;
	}//end changesFor()

	/**
	 * The dossier the case's answers name, or ''.
	 *
	 * @param array<string, mixed> $case   The stored case.
	 * @param array<string, mixed> $config The case type's intake (configOf()).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
	 */
	public function dossierOf(array $case, array $config): string {
		if ($config === [] || $config['dossierAnswer'] === '') {
			return '';
		}

		$value = ($this->answersOf(case: $case, config: $config)[$config['dossierAnswer']] ?? '');

		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end dossierOf()

	/**
	 * The answers as sent, completed: the origin when they name none, and each
	 * `copy` answer from the case field it landed on (`onderwerp` from `title`).
	 *
	 * @param array<string, mixed> $case    The case.
	 * @param array<string, mixed> $config  The case type's intake.
	 * @param array<string, mixed> $answers The answers at `answersAt`.
	 *
	 * @return array<string, mixed>
	 */
	private function keptAnswers(array $case, array $config, array $answers): array {
		if ($config['origin'] !== '' && trim((string)($answers['origin'] ?? '')) === '') {
			$answers['origin'] = $config['origin'];
		}

		foreach ($config['copy'] as $answer => $field) {
			if (array_key_exists($answer, $answers) === false && is_scalar($case[$field] ?? null) === true) {
				$answers[$answer] = $case[$field];
			}
		}

		return $answers;
	}//end keptAnswers()

	/**
	 * The `copy` map: answer name to case field, plain names only.
	 *
	 * @param mixed $copy The configured map.
	 *
	 * @return array<string, string>
	 */
	private function copyOf(mixed $copy): array {
		$map = [];
		if (is_array($copy) === false) {
			return [];
		}

		foreach ($copy as $answer => $field) {
			$answerName = $this->name(value: $answer);
			$fieldName  = $this->name(value: $field);
			if ($answerName !== '' && $fieldName !== '') {
				$map[$answerName] = $fieldName;
			}
		}

		return $map;
	}//end copyOf()

	/**
	 * The answers as sent: the object at `answersAt`, else the case itself.
	 *
	 * @param array<string, mixed> $case   The case.
	 * @param array<string, mixed> $config The case type's intake.
	 *
	 * @return array<string, mixed>
	 */
	private function answersOf(array $case, array $config): array {
		if ($config['answersAt'] === '') {
			return $case;
		}

		$answers = ($case[$config['answersAt']] ?? []);
		if (is_array($answers) === false) {
			return [];
		}

		return $answers;
	}//end answersOf()

	/**
	 * The case's property entries as they are.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, mixed>
	 */
	private function entriesOf(array $case): array {
		$entries = ($case['properties'] ?? []);
		if (is_array($entries) === false) {
			return [];
		}

		return array_values($entries);
	}//end entriesOf()

	/**
	 * The entries whose name the case does not carry yet.
	 *
	 * @param array<string, mixed>              $case    The case.
	 * @param array<int, array<string, string>> $entries The entries the answers give.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function newEntries(array $case, array $entries): array {
		$have = [];
		foreach ($this->entriesOf(case: $case) as $entry) {
			if (is_array($entry) === true) {
				$have[(string)($entry['name'] ?? '')] = true;
			}
		}

		return array_values(array_filter($entries, static fn (array $entry): bool => isset($have[$entry['name']]) === false));
	}//end newEntries()

	/**
	 * A plain field name, or ''.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return string
	 */
	private function name(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		$name = trim((string)$value);
		if (preg_match(self::NAME, $name) !== 1) {
			return '';
		}

		return $name;
	}//end name()
}//end class
