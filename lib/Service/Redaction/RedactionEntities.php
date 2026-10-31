<?php

/**
 * Dossiq redaction entities: the one list of passages a redaction acts on.
 *
 * Filinq's detection, the rules floor and Hermiq's spans each describe a
 * passage differently. This class turns them into one shape, `{type, value,
 * source}`, and merges them without duplicates, so the officer reviews one
 * list and filinq's review mark and its anonymisation are given the same one.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Redaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Redaction;

/**
 * Normalises and merges the passages a redaction acts on.
 *
 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
 */
class RedactionEntities {

	/**
	 * Span categories that name the entity type filinq uses for the same thing.
	 *
	 * @var array<string, string>
	 */
	private const TYPE_ALIASES = [
		'NAME' => 'PERSON',
		'PERSON_NAME' => 'PERSON',
		'PHONE_NUMBER' => 'PHONE',
		'EMAIL_ADDRESS' => 'EMAIL',
	];

	/**
	 * Where an entity can come from.
	 *
	 * @var array<int, string>
	 */
	private const SOURCES = ['filinq', 'hermiq', 'rule', 'officer'];

	/**
	 * Filinq fields carried along with an entity it detected.
	 *
	 * @var array<int, string>
	 */
	private const FILINQ_FIELDS = ['confidence', 'relationId', 'bases'];

	/**
	 * Filinq's entities, each marked as filinq's.
	 *
	 * @param array<int, array<string, mixed>> $entities What filinq's detection returned.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
	 */
	public function fromFilinq(array $entities): array {
		$normalised = [];
		foreach ($entities as $entity) {
			if (($entity['skipAnonymization'] ?? false) === true) {
				continue;
			}

			$normalised[] = $this->entity(raw: $entity, source: 'filinq');
		}

		return $this->unique(entities: $normalised);
	}//end fromFilinq()

	/**
	 * Spans over a text, as entities.
	 *
	 * A span names its passage by `text` when it carries one, else by its
	 * offsets into the text it was found in.
	 *
	 * @param array<int, array<string, mixed>> $spans The spans: `{start, end, category, text?, source?}`.
	 * @param string                           $text  The text the spans point into.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
	 */
	public function fromSpans(array $spans, string $text): array {
		$entities = [];
		foreach ($spans as $span) {
			$value = (string)($span['text'] ?? '');
			if ($value === '' && is_int($span['start'] ?? null) === true && is_int($span['end'] ?? null) === true) {
				$value = substr($text, $span['start'], ($span['end'] - $span['start']));
			}

			$source = 'hermiq';
			if (($span['source'] ?? '') === 'rule') {
				$source = 'rule';
			}

			$entities[] = $this->entity(raw: ['type' => ($span['category'] ?? ''), 'value' => $value], source: $source);
		}

		return $this->unique(entities: $entities);
	}//end fromSpans()

	/**
	 * The officer's list, as the redaction will use it.
	 *
	 * Every entry needs a type and a value; an entry without a known source
	 * is the officer's own.
	 *
	 * @param array<mixed> $entities The posted list.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-a-redacted-copy-is-written-only-after-a-named-officer-checked-the-entities-req-drd-002
	 */
	public function fromOfficer(array $entities): array {
		$normalised = [];
		foreach ($entities as $entity) {
			if (is_array($entity) === false) {
				continue;
			}

			$source = (string)($entity['source'] ?? '');
			if (in_array($source, self::SOURCES, true) === false) {
				$source = 'officer';
			}

			$normalised[] = $this->entity(raw: $entity, source: $source);
		}

		return $this->unique(entities: $normalised);
	}//end fromOfficer()

	/**
	 * Two lists merged, the first one's entries winning a duplicate.
	 *
	 * @param array<int, array<string, mixed>> $first  The list that wins.
	 * @param array<int, array<string, mixed>> $second The list added to it.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
	 */
	public function merge(array $first, array $second): array {
		return $this->unique(entities: array_merge($first, $second));
	}//end merge()

	/**
	 * One entity in the shared shape.
	 *
	 * @param array<string, mixed> $raw    The entity as it came.
	 * @param string               $source Where it came from.
	 *
	 * @return array<string, mixed>
	 */
	private function entity(array $raw, string $source): array {
		$type = strtoupper(trim((string)($raw['type'] ?? ($raw['entityType'] ?? ''))));
		$type = (self::TYPE_ALIASES[$type] ?? $type);
		if ($type === '') {
			$type = 'UNKNOWN';
		}

		$entity = [
			'type' => $type,
			'value' => trim((string)($raw['value'] ?? ($raw['text'] ?? ''))),
			'source' => $source,
		];

		foreach (self::FILINQ_FIELDS as $field) {
			if (array_key_exists($field, $raw) === true && $raw[$field] !== null) {
				$entity[$field] = $raw[$field];
			}
		}

		return $entity;
	}//end entity()

	/**
	 * Entries with a value, each type and value once.
	 *
	 * @param array<int, array<string, mixed>> $entities The entries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function unique(array $entities): array {
		$seen = [];
		$unique = [];
		foreach ($entities as $entity) {
			$value = (string)($entity['value'] ?? '');
			if ($value === '') {
				continue;
			}

			$key = $entity['type'] . '|' . mb_strtolower($value);
			if (isset($seen[$key]) === true) {
				continue;
			}

			$seen[$key] = true;
			$unique[] = $entity;
		}

		return $unique;
	}//end unique()
}//end class
