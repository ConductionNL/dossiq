<?php

/**
 * Dossiq Decision Document Definition.
 *
 * What a case type configures about one kind of decision document: its
 * template, the fields it needs, the rules it must meet, its remedy clause,
 * whether it is signed, which delegated authority decides it, which number
 * series it takes and from which status it is frozen.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\DecisionDocument
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
 * @spec openspec/specs/decision-document/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DecisionDocument;

use InvalidArgumentException;
use OCA\Dossiq\Service\FrozenRecord\FreezeDefinition;

/**
 * One `decisionDocuments.<kind>` block on a case type, validated.
 *
 * WHY NOT `decisionTypes`. The case type's `decisionTypes` already holds the
 * ZGW besluittype references as strings; giving its entries a second shape
 * would break the ZGW surface. The decision document is configured beside it.
 *
 * @spec openspec/specs/decision-document/spec.md
 */
final class DecisionDocumentDefinition {

	/**
	 * The case-type key the definitions live under.
	 *
	 * @var string
	 */
	public const CASE_TYPE_KEY = 'decisionDocuments';

	/**
	 * The statuses of a decision document, in order.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = ['draft', 'approved', 'signed', 'sent', 'archived'];

	/**
	 * The statuses from which a decision document is frozen, unless the block names others.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_FROZEN_STATUSES = ['signed', 'sent', 'archived'];

	/**
	 * The fields a decision document always freezes once signed.
	 *
	 * @var array<int, string>
	 */
	public const CONTENT_FIELDS = ['kind', 'caseId', 'template', 'decision', 'rationale', 'addressee', 'remedyClause', 'decisionKind'];

	/**
	 * The kind's slug.
	 *
	 * @var string
	 */
	public readonly string $kind;

	/**
	 * The template id the document renders from; '' for none.
	 *
	 * @var string
	 */
	public readonly string $template;

	/**
	 * Fields that must be filled before approval.
	 *
	 * @var array<int, string>
	 */
	public readonly array $requiredFields;

	/**
	 * Validity rules: each must hold.
	 *
	 * @var array<int, array{id: string, when?: mixed, message: string}>
	 */
	public readonly array $rules;

	/**
	 * Named conditions the rules may reference.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $conditions;

	/**
	 * The remedy clause text; '' for none.
	 *
	 * @var string
	 */
	public readonly string $remedyClause;

	/**
	 * Whether a remedy clause is required.
	 *
	 * @var bool
	 */
	public readonly bool $remedyRequired;

	/**
	 * Whether the document must be signed.
	 *
	 * @var bool
	 */
	public readonly bool $signed;

	/**
	 * The delegated-authority act that decides it; '' for none.
	 *
	 * @var string
	 */
	public readonly string $authorityAct;

	/**
	 * The decision kind recorded with decidiq; '' for none.
	 *
	 * @var string
	 */
	public readonly string $decisionKind;

	/**
	 * The number series; '' for unnumbered.
	 *
	 * @var string
	 */
	public readonly string $series;

	/**
	 * The series' default prefix.
	 *
	 * @var string
	 */
	public readonly string $defaultPrefix;

	/**
	 * The statuses from which it is frozen.
	 *
	 * @var array<int, string>
	 */
	public readonly array $frozenStatuses;

	/**
	 * Read and validate one `decisionDocuments.<kind>` block.
	 *
	 * @param string               $kind  The kind's slug.
	 * @param array<string, mixed> $block The block: one key per field above, each optional.
	 *
	 * @throws InvalidArgumentException When the block is incomplete.
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	public function __construct(string $kind, array $block) {
		$series = (array)($block['series'] ?? []);

		$this->kind           = $kind;
		$this->template       = trim((string)($block['template'] ?? ''));
		$this->requiredFields = array_values(array_filter((array)($block['requiredFields'] ?? []), 'is_string'));
		$this->rules          = self::rulesOf(block: $block);
		$this->conditions     = (array)($block['conditions'] ?? []);
		$this->remedyClause   = trim((string)($block['remedyClause'] ?? ''));
		$this->remedyRequired = (bool)($block['remedyRequired'] ?? true);
		$this->signed         = (bool)($block['signed'] ?? true);
		$this->authorityAct   = trim((string)($block['authorityAct'] ?? ''));
		$this->decisionKind   = trim((string)($block['decisionKind'] ?? ''));
		$this->series         = trim((string)($series['name'] ?? ''));
		$this->defaultPrefix  = trim((string)($series['defaultPrefix'] ?? ''));
		$this->frozenStatuses = array_values(array_filter((array)($block['frozenStatuses'] ?? self::DEFAULT_FROZEN_STATUSES), 'is_string'));

		$this->assertComplete();
	}//end __construct()

	/**
	 * Read a kind from a case type.
	 *
	 * @param array<string, mixed> $caseType The effective case type.
	 * @param string $kind The kind's slug.
	 *
	 * @return self|null The definition, or null when the case type does not configure the kind.
	 *
	 * @throws InvalidArgumentException When the block is incomplete.
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	public static function fromCaseType(array $caseType, string $kind): ?self {
		$block = ($caseType[self::CASE_TYPE_KEY][$kind] ?? null);
		if (is_array($block) === false) {
			return null;
		}

		return new self(kind: $kind, block: $block);
	}//end fromCaseType()

	/**
	 * The frozen-record entry (C04) for documents of this kind stored in a schema.
	 *
	 * @param string $schema The schema id, slug or `config:<key>` the documents live in.
	 *
	 * @return FreezeDefinition The freeze entry.
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	public function freezeDefinition(string $schema): FreezeDefinition {
		$successor = null;
		if ($this->series !== '') {
			$successor = [
				'series' => $this->series,
				'defaultPrefix' => $this->defaultPrefix,
				'numberField' => 'reference',
				'supersedesField' => 'supersedes',
				'supersededByField' => 'supersededBy',
			];
		}

		return new FreezeDefinition(
			entry: [
				'schema' => $schema,
				'frozenStatuses' => $this->frozenStatuses,
				'fields' => array_values(array_unique(array_merge(self::CONTENT_FIELDS, $this->requiredFields))),
				'writeOnce' => ['signature', 'sentAt'],
				'sealFields' => array_values(array_unique(array_merge(self::CONTENT_FIELDS, $this->requiredFields, ['reference']))),
				'successor' => $successor,
				'rule' => 'decision-document-frozen',
				'message' => 'This decision has been signed and cannot be changed. Issue a correcting or withdrawing decision instead.',
			]
		);
	}//end freezeDefinition()

	/**
	 * The validity rules of a block, each with an id, a message and, when given, a condition.
	 *
	 * @param array<string, mixed> $block The block.
	 *
	 * @return array<int, array{id: string, when?: mixed, message: string}> The rules.
	 */
	private static function rulesOf(array $block): array {
		$rules = [];
		foreach ((array)($block['rules'] ?? []) as $rule) {
			$rule  = (array)$rule;
			$entry = [
				'id' => trim((string)($rule['id'] ?? '')),
				'when' => ($rule['when'] ?? null),
				'message' => trim((string)($rule['message'] ?? '')),
			];
			if (array_key_exists('when', $rule) === false) {
				unset($entry['when']);
			}

			$rules[] = $entry;
		}

		return $rules;
	}//end rulesOf()

	/**
	 * Refuse a block that cannot describe a decision document.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the block is incomplete.
	 */
	private function assertComplete(): void {
		if (trim($this->kind) === '') {
			throw new InvalidArgumentException('A decision document definition must name its kind.');
		}

		if ($this->remedyRequired === true && $this->remedyClause === '') {
			throw new InvalidArgumentException(sprintf('Decision document %s requires a remedy clause but configures none.', $this->kind));
		}

		foreach ($this->rules as $rule) {
			if ($rule['id'] === '' || array_key_exists('when', $rule) === false) {
				throw new InvalidArgumentException(sprintf('Decision document %s declares a rule without an id or a condition.', $this->kind));
			}
		}

		if (array_diff($this->frozenStatuses, self::STATUSES) !== [] || $this->frozenStatuses === []) {
			throw new InvalidArgumentException(sprintf('Decision document %s freezes at a status that is not a decision document status.', $this->kind));
		}
	}//end assertComplete()
}//end class
