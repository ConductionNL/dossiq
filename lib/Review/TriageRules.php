<?php

/**
 * Dossiq triage rules: visible rules that mark a case's unmarked documents in or out of scope.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance;
use OCA\Dossiq\Woo\WooCaseDocuments;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the `triageRule` objects of a case and applies them.
 *
 * A created rule reconciles the case's reviewer access in the same request,
 * so a case type that keeps its review records private grants them at once.
 * Applying evaluates the active rules in order on every unmarked document;
 * the first rule that matches marks the document with its effect and its
 * name, with `relevanceSource` `rule`. A document a reviewer marked is never
 * touched, also when the reviewer set it back to unmarked. Nothing is opened:
 * the facts are the document's provenance, its mail headers, its record and
 * the text OpenRegister already extracted.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 */
class TriageRules {

	use SearchesObjects;

	/**
	 * The app config key of the rule schema.
	 */
	public const SCHEMA_KEY = 'triage_rule_schema';

	/**
	 * The effects a rule can have.
	 */
	public const EFFECTS = [DocumentRelevance::IN_SCOPE, DocumentRelevance::OUT_OF_SCOPE];

	/**
	 * The sentence of a rule that cannot be stored or applied.
	 */
	private const UNSTORED = 'The triage rules cannot be stored or applied, so nothing was marked.';

	/**
	 * The status of a rule this case does not have.
	 */
	private const NOT_FOUND = 404;

	/**
	 * Constructor.
	 *
	 * @param SettingsService        $settingsService The settings and OpenRegister access.
	 * @param DocumentRelevance      $reviews         The reviews a rule marks.
	 * @param WooCaseDocuments       $caseDocuments   The case's documents and their records.
	 * @param CaseDocumentProvenance $provenance      Custodian, source system and mail headers per document.
	 * @param DocumentText           $text            The extracted text per document.
	 * @param TriageConditions       $conditions      Checks and matches a rule's conditions.
	 * @param CaseReviewerAccess     $reviewerAccess  Who may read the case's private review records.
	 * @param LoggerInterface        $logger          The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly DocumentRelevance $reviews,
		private readonly WooCaseDocuments $caseDocuments,
		private readonly CaseDocumentProvenance $provenance,
		private readonly DocumentText $text,
		private readonly TriageConditions $conditions,
		private readonly CaseReviewerAccess $reviewerAccess,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The case's rules in order, each with how many documents it decided and how many reviewers overturned.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return list<array<string, mixed>> The rules with `decided` and `overturned`.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function forCase(string $caseId): array {
		$decided = [];
		$overturned = [];
		foreach ($this->reviews->forCase(caseId: $caseId) as $review) {
			$rule = (string)($review['rule'] ?? '');
			if ($rule !== '' && ($review['relevanceSource'] ?? '') === 'rule') {
				$decided[$rule] = (($decided[$rule] ?? 0) + 1);
			}

			$over = (string)($review['overturnedRule'] ?? '');
			if ($over !== '') {
				$overturned[$over] = (($overturned[$over] ?? 0) + 1);
			}
		}

		$listed = [];
		foreach ($this->rows(caseId: $caseId) as $rule) {
			$name = (string)($rule['name'] ?? '');
			$rule['decided'] = ($decided[$name] ?? 0);
			$rule['overturned'] = ($overturned[$name] ?? 0);
			$listed[] = $rule;
		}

		return $listed;
	}//end forCase()

	/**
	 * Create a rule, or change the case's rule `ruleId`.
	 *
	 * @param string               $caseId The case UUID.
	 * @param array<string, mixed> $input  `name`, `conditions`, `effect`, and optionally `order` and `active`.
	 * @param string|null          $ruleId The rule to change, null to create one.
	 *
	 * @return array<string, mixed> The saved rule.
	 *
	 * @throws RefusedException When the rule is incomplete, its name is taken, the rule is not the case's, or nothing can be stored.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function save(string $caseId, array $input, ?string $ruleId = null): array {
		$rows = $this->rows(caseId: $caseId);
		$current = [];
		if ($ruleId !== null) {
			$current = $this->ruleOf(rows: $rows, ruleId: $ruleId);
		}

		$rule = $this->validated(caseId: $caseId, input: ($input + $current), rows: $rows, ruleId: $ruleId);
		if ($ruleId !== null) {
			$rule['id'] = $ruleId;
			return $this->write(rule: $rule);
		}

		$saved = $this->write(rule: $rule);
		try {
			$this->reviewerAccess->reconcile(caseId: $caseId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: a triage rule was stored but the reviewer access of its case could not be reconciled',
				['app' => Application::APP_ID, 'case' => $caseId, 'exception' => $e->getMessage()]
			);
		}

		return $saved;
	}//end save()

	/**
	 * Delete the case's rule `ruleId`. The documents it marked keep their marking and its name.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $ruleId The rule.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the rule is not the case's, or cannot be deleted.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function delete(string $caseId, string $ruleId): void {
		$this->ruleOf(rows: $this->rows(caseId: $caseId), ruleId: $ruleId);
		try {
			$this->settingsService->getObjectService()->deleteObject(
				uuid: $ruleId,
				register: $this->settingsService->getConfigValue('register'),
				schema: $this->settingsService->getConfigValue(self::SCHEMA_KEY),
			);
		} catch (Throwable $e) {
			throw $this->unavailable(previous: $e);
		}
	}//end delete()

	/**
	 * Apply the active rules in order to every unmarked document of the case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{counts: array<string, int>, marked: int, considered: int} The documents each rule marked, the total, and how many unmarked documents were looked at.
	 *
	 * @throws RefusedException When the rules, the reviews or a document's facts cannot be read or written.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function apply(string $caseId): array {
		$rules = $this->activeRules(caseId: $caseId);
		$counts = array_fill_keys(array_map(static fn (array $rule): string => (string)$rule['name'], $rules), 0);
		$considered = 0;
		if ($rules === []) {
			return ['counts' => $counts, 'marked' => 0, 'considered' => 0];
		}

		$reviews = $this->reviews->forCase(caseId: $caseId);
		foreach ($this->caseDocuments->idsFor(caseId: $caseId) as $documentId) {
			$review = ($reviews[$documentId] ?? ['case' => $caseId, 'documentRef' => $documentId, 'relevance' => DocumentRelevance::UNMARKED]);
			if ($this->isOpenToRules(review: $review) === false) {
				continue;
			}

			$considered++;
			$rule = $this->firstMatch(caseId: $caseId, documentId: $documentId, rules: $rules);
			if ($rule === null) {
				continue;
			}

			$review['relevance'] = (string)$rule['effect'];
			$review['relevanceSource'] = 'rule';
			$review['rule'] = (string)$rule['name'];
			$review['markedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
			$this->reviews->save(review: $review);
			$counts[(string)$rule['name']]++;
		}

		return ['counts' => $counts, 'marked' => array_sum($counts), 'considered' => $considered];
	}//end apply()

	/**
	 * Whether a rule may mark this review: unmarked, and never marked by a reviewer.
	 *
	 * @param array<string, mixed> $review The review.
	 *
	 * @return bool
	 */
	private function isOpenToRules(array $review): bool {
		return ($review['relevance'] ?? DocumentRelevance::UNMARKED) === DocumentRelevance::UNMARKED
			&& ($review['relevanceSource'] ?? '') !== 'reviewer';
	}//end isOpenToRules()

	/**
	 * The first rule whose conditions the document meets, or null.
	 *
	 * @param string                           $caseId     The case UUID.
	 * @param string                           $documentId The document.
	 * @param list<array<string, mixed>>       $rules      The active rules, in order.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws RefusedException When the document's facts cannot be read.
	 */
	private function firstMatch(string $caseId, string $documentId, array $rules): ?array {
		$facts = $this->factsOf(caseId: $caseId, documentId: $documentId);
		$textRead = false;
		foreach ($rules as $rule) {
			$conditions = (array)$rule['conditions'];
			if ($textRead === false && $this->conditions->needsText(conditions: $conditions) === true) {
				$facts['text'] = $this->text->of(document: $facts['record']);
				$textRead = true;
			}

			if ($this->conditions->matches(conditions: $conditions, facts: $facts) === true) {
				return $rule;
			}
		}

		return null;
	}//end firstMatch()

	/**
	 * The facts a rule reads about one document; the text is read only when a rule asks for it.
	 *
	 * @param string $caseId     The case UUID.
	 * @param string $documentId The document.
	 *
	 * @return array{custodian: string, sourceSystem: string, mimeType: string, date: string, senderDomain: string, text: ?string, record: array<string, mixed>}
	 *
	 * @throws RefusedException When the provenance cannot be read.
	 */
	private function factsOf(string $caseId, string $documentId): array {
		$record = ($this->caseDocuments->meta(documentId: $documentId) ?? []);
		try {
			$provenance = $this->provenance->forDocument(caseId: $caseId, documentId: $documentId, record: $record);
			$mail = $this->provenance->mailOf(caseId: $caseId, documentId: $documentId, record: $record);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the provenance of a document could not be read, so the triage rules were not applied',
				['app' => Application::APP_ID, 'case' => $caseId, 'document' => $documentId, 'exception' => $e->getMessage()]
			);
			throw $this->unavailable(previous: $e);
		}

		return [
			'custodian' => $provenance['custodian'],
			'sourceSystem' => $provenance['sourceSystem'],
			'mimeType' => trim((string)($record['mimeType'] ?? ($record['format'] ?? ''))),
			'date' => self::day(value: ($mail['date'] !== '' ? $mail['date'] : (string)($record['creatiedatum'] ?? ''))),
			'senderDomain' => $mail['senderDomain'],
			'text' => null,
			'record' => $record,
		];
	}//end factsOf()

	/**
	 * A date or date-time as YYYY-MM-DD, '' when it is no date.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private static function day(string $value): string {
		if (trim($value) === '') {
			return '';
		}

		try {
			return (new DateTimeImmutable($value))->format('Y-m-d');
		} catch (Throwable) {
			return '';
		}
	}//end day()

	/**
	 * The active rules of the case in order: by `order`, then by name.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function activeRules(string $caseId): array {
		$rules = array_values(
			array_filter(
				$this->rows(caseId: $caseId),
				static fn (array $rule): bool => ($rule['active'] ?? true) !== false
					&& in_array($rule['effect'] ?? '', self::EFFECTS, true) === true
					&& is_array($rule['conditions'] ?? null) === true
					&& $rule['conditions'] !== []
			)
		);
		usort(
			$rules,
			static fn (array $one, array $two): int => [(int)($one['order'] ?? 0), (string)($one['name'] ?? '')] <=> [(int)($two['order'] ?? 0), (string)($two['name'] ?? '')]
		);

		return $rules;
	}//end activeRules()

	/**
	 * The rule as it will be stored, or the refusal.
	 *
	 * @param string                     $caseId The case UUID.
	 * @param array<string, mixed>       $input  The rule as given, over the current one.
	 * @param list<array<string, mixed>> $rows   The case's rules.
	 * @param string|null                $ruleId The rule being changed, or null.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RefusedException When something is missing, unknown or taken.
	 */
	private function validated(string $caseId, array $input, array $rows, ?string $ruleId): array {
		if ($this->isAvailable() === false) {
			throw $this->unavailable(previous: null);
		}

		$name = trim((string)($input['name'] ?? ''));
		if ($name === '') {
			throw $this->refusal(rule: 'triage-rule-name-required', sentence: 'Give the rule a name.');
		}

		foreach ($rows as $row) {
			if ((string)($row['name'] ?? '') === $name && $this->idOf(row: $row) !== $ruleId) {
				throw $this->refusal(rule: 'triage-rule-name-taken', sentence: 'Another rule of this case has that name.', status: RefusedException::STATUS_REFUSED);
			}
		}

		$effect = (string)($input['effect'] ?? '');
		if (in_array($effect, self::EFFECTS, true) === false) {
			throw $this->refusal(rule: 'triage-rule-effect-required', sentence: 'Choose whether the rule marks documents in scope or out of scope.');
		}

		$order = (count($rows) + 1);
		if (is_numeric($input['order'] ?? null) === true) {
			$order = (int)$input['order'];
		}

		return [
			'case' => $caseId,
			'name' => $name,
			'conditions' => $this->conditions->normalise(raw: (array)($input['conditions'] ?? [])),
			'effect' => $effect,
			'order' => $order,
			'active' => (($input['active'] ?? true) !== false),
		];
	}//end validated()

	/**
	 * The case's rule with this id.
	 *
	 * @param list<array<string, mixed>> $rows   The case's rules.
	 * @param string                     $ruleId The rule.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RefusedException When the case has no rule with this id.
	 */
	private function ruleOf(array $rows, string $ruleId): array {
		foreach ($rows as $row) {
			if ($this->idOf(row: $row) === $ruleId) {
				return $row;
			}
		}

		throw $this->refusal(rule: 'triage-rule-unknown', sentence: 'This case has no such rule.', status: self::NOT_FOUND);
	}//end ruleOf()

	/**
	 * The uuid of a stored row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? '')));
	}//end idOf()

	/**
	 * Whether the rule schema can be read and written.
	 *
	 * @return bool
	 */
	private function isAvailable(): bool {
		return $this->reviews->isAvailable() === true
			&& $this->settingsService->getConfigValue(self::SCHEMA_KEY) !== '';
	}//end isAvailable()

	/**
	 * Every rule of a case, as stored.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $caseId): array {
		if ($this->isAvailable() === false || $caseId === '') {
			return [];
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $this->settingsService->getObjectService(),
			register: $this->settingsService->getConfigValue('register'),
			schema: $this->settingsService->getConfigValue(self::SCHEMA_KEY),
			filters: ['case' => $caseId, '_limit' => 200],
		);

		return array_values(array_filter($rows, static fn (array $row): bool => (string)($row['case'] ?? '') === $caseId));
	}//end rows()

	/**
	 * Store a rule, creating or replacing it.
	 *
	 * @param array<string, mixed> $rule The rule; `id` names an existing one.
	 *
	 * @return array<string, mixed> The saved rule.
	 *
	 * @throws RefusedException When the rule cannot be written.
	 */
	private function write(array $rule): array {
		$uuid = null;
		if (isset($rule['id']) === true) {
			$uuid = (string)$rule['id'];
		}

		unset($rule['id']);
		try {
			$saved = $this->settingsService->getObjectService()->saveObject(
				object: $rule,
				register: $this->settingsService->getConfigValue('register'),
				schema: $this->settingsService->getConfigValue(self::SCHEMA_KEY),
				uuid: $uuid,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a triage rule could not be written',
				['app' => Application::APP_ID, 'case' => ($rule['case'] ?? ''), 'exception' => $e->getMessage()]
			);
			throw $this->unavailable(previous: $e);
		}

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			return (array)$saved->jsonSerialize();
		}

		return (array)$saved;
	}//end write()

	/**
	 * The refusal of rules that cannot be stored or applied (503).
	 *
	 * @param Throwable|null $previous The cause.
	 *
	 * @return RefusedException
	 */
	private function unavailable(?Throwable $previous): RefusedException {
		return $this->refusal(rule: 'triage-rule-unavailable', sentence: self::UNSTORED, status: RefusedException::STATUS_INDETERMINATE, previous: $previous);
	}//end unavailable()

	/**
	 * A refusal, 422 unless said otherwise.
	 *
	 * @param string         $rule     The rule.
	 * @param string         $sentence The static sentence.
	 * @param int            $status   The status.
	 * @param Throwable|null $previous The cause.
	 *
	 * @return RefusedException
	 */
	private function refusal(
		string $rule,
		string $sentence,
		int $status = RefusedException::STATUS_UNPROCESSABLE,
		?Throwable $previous = null,
	): RefusedException {
		return new RefusedException(rule: $rule, sentence: $sentence, status: $status, previous: $previous);
	}//end refusal()
}//end class
