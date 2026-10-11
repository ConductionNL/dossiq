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
	 * Constructor.
	 *
	 * @param string $kind The kind's slug.
	 * @param string $template The template id the document renders from; '' for none.
	 * @param array<int, string> $requiredFields Fields that must be filled before approval.
	 * @param array<int, array{id: string, when: mixed, message: string}> $rules Validity rules: each must hold.
	 * @param array<string, mixed> $conditions Named conditions the rules may reference.
	 * @param string $remedyClause The remedy clause text; '' for none.
	 * @param bool $remedyRequired Whether a remedy clause is required.
	 * @param bool $signed Whether the document must be signed.
	 * @param string $authorityAct The delegated-authority act that decides it; '' for none.
	 * @param string $decisionKind The decision kind recorded with decidiq; '' for none.
	 * @param string $series The number series; '' for unnumbered.
	 * @param string $defaultPrefix The series' default prefix.
	 * @param array<int, string> $frozenStatuses The statuses from which it is frozen.
	 *
	 * @throws InvalidArgumentException When the block is incomplete.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A value object for one configuration block: each
	 *                                                 parameter is one key of that block, named.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    The two booleans are configuration values, not behaviour switches.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $template = '',
		public readonly array $requiredFields = [],
		public readonly array $rules = [],
		public readonly array $conditions = [],
		public readonly string $remedyClause = '',
		public readonly bool $remedyRequired = true,
		public readonly bool $signed = true,
		public readonly string $authorityAct = '',
		public readonly string $decisionKind = '',
		public readonly string $series = '',
		public readonly string $defaultPrefix = '',
		public readonly array $frozenStatuses = self::DEFAULT_FROZEN_STATUSES,
	) {
		if (trim($kind) === '') {
			throw new InvalidArgumentException('A decision document definition must name its kind.');
		}

		if ($remedyRequired === true && trim($remedyClause) === '') {
			throw new InvalidArgumentException(sprintf('Decision document %s requires a remedy clause but configures none.', $kind));
		}

		foreach ($rules as $rule) {
			if (trim((string)($rule['id'] ?? '')) === '' || array_key_exists('when', $rule) === false) {
				throw new InvalidArgumentException(sprintf('Decision document %s declares a rule without an id or a condition.', $kind));
			}
		}

		if (array_diff($frozenStatuses, self::STATUSES) !== [] || $frozenStatuses === []) {
			throw new InvalidArgumentException(sprintf('Decision document %s freezes at a status that is not a decision document status.', $kind));
		}
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

		return self::fromArray(kind: $kind, block: $block);
	}//end fromCaseType()

	/**
	 * Read a kind from its configuration block.
	 *
	 * @param string $kind The kind's slug.
	 * @param array<string, mixed> $block The block.
	 *
	 * @return self The definition.
	 *
	 * @throws InvalidArgumentException When the block is incomplete.
	 *
	 * @spec openspec/specs/decision-document/spec.md
	 */
	public static function fromArray(string $kind, array $block): self {
		$rules = [];
		foreach ((array)($block['rules'] ?? []) as $rule) {
			$rule = (array)$rule;
			$rules[] = [
				'id' => trim((string)($rule['id'] ?? '')),
				'when' => ($rule['when'] ?? null),
				'message' => trim((string)($rule['message'] ?? '')),
			];
			if (array_key_exists('when', $rule) === false) {
				unset($rules[array_key_last($rules)]['when']);
			}
		}

		$series = (array)($block['series'] ?? []);
		$frozen = array_values(array_filter((array)($block['frozenStatuses'] ?? self::DEFAULT_FROZEN_STATUSES), 'is_string'));

		return new self(
			kind: $kind,
			template: trim((string)($block['template'] ?? '')),
			requiredFields: array_values(array_filter((array)($block['requiredFields'] ?? []), 'is_string')),
			rules: $rules,
			conditions: (array)($block['conditions'] ?? []),
			remedyClause: trim((string)($block['remedyClause'] ?? '')),
			remedyRequired: (bool)($block['remedyRequired'] ?? true),
			signed: (bool)($block['signed'] ?? true),
			authorityAct: trim((string)($block['authorityAct'] ?? '')),
			decisionKind: trim((string)($block['decisionKind'] ?? '')),
			series: trim((string)($series['name'] ?? '')),
			defaultPrefix: trim((string)($series['defaultPrefix'] ?? '')),
			frozenStatuses: $frozen,
		);
	}//end fromArray()

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
			schema: $schema,
			frozenStatuses: $this->frozenStatuses,
			fields: array_values(array_unique(array_merge(self::CONTENT_FIELDS, $this->requiredFields))),
			writeOnce: ['signature', 'sentAt'],
			sealFields: array_values(array_unique(array_merge(self::CONTENT_FIELDS, $this->requiredFields, ['reference']))),
			successor: $successor,
			rule: 'decision-document-frozen',
			message: 'This decision has been signed and cannot be changed. Issue a correcting or withdrawing decision instead.',
		);
	}//end freezeDefinition()
}//end class
