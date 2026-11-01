<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Product;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Issue (or change) the product a resident holds when a case ends with a positive outcome.
 *
 * Generic capability (decision 182: procedures are configuration, code is
 * generic). What a case type issues is declared on the case type
 * (`issuesPermit`, the product declaration):
 *
 * - `schema` the product schema (default `permit`), and static fields such
 *   as `kind` and `theme`;
 * - `issueOn` the outcome statuses that issue it (default `approved`);
 * - `titleTemplate` and `detailsFromCase` (product field => case field or
 *   case-type answer); `compactFields` are stored in capitals without spaces
 *   or dashes;
 * - on a change case type, `changesProduct` {answer, set}: the outcome sets
 *   fields on the product the case's `answer` names, from the case answers
 *   `set` maps, instead of issuing one.
 *
 * Parking permits are one configuration of this (decision 172: issued on the
 * decision app's outcome event). The decision outcome reaches it through
 * DecisionConcludedListener.
 *
 * @spec openspec/changes/archive/2026-10-30-portal-permits-as-held-products/tasks.md#2.1
 */
class CaseOutcomeProductIssuer {
	use SearchesObjects;

	/**
	 * The product schema when the declaration names none.
	 */
	public const DEFAULT_SCHEMA = 'permit';

	/**
	 * The outcome statuses that issue when the declaration names none.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_ISSUE_ON = ['approved'];

	/**
	 * Declaration keys that are copied onto the product as static fields.
	 *
	 * @var array<int, string>
	 */
	private const STATIC_FIELDS = ['kind', 'theme'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register and OpenRegister.
	 * @param LoggerInterface $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Act on a concluded decision about a case.
	 *
	 * Never throws: the decision stands whether or not a product could be
	 * written, and a failure is logged with the case and the decision.
	 *
	 * @param string      $caseId     The case the decision is about.
	 * @param string      $decisionId The concluded decision.
	 * @param string      $status     The outcome status the decision app reported.
	 * @param string|null $decidedAt  When it concluded (ISO 8601), or null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-30-portal-permits-as-held-products/tasks.md#2.1
	 */
	public function onConcludedDecision(string $caseId, string $decisionId, string $status, ?string $decidedAt): void {
		try {
			$this->issueFor(caseId: $caseId, decisionId: $decisionId, status: $status, decidedAt: $decidedAt);
		} catch (Throwable $e) {
			// The decision stands whether or not its product could be written.
			$this->logger->warning(
				'Dossiq: the decision concluded, but the product it issues could not be written',
				['case' => $caseId, 'decision' => $decisionId, 'error' => $e->getMessage()]
			);
		}
	}//end onConcludedDecision()

	/**
	 * Issue or change the product the case type declares for this outcome.
	 *
	 * A register failure propagates to the caller.
	 *
	 * @param string      $caseId     The case the decision is about.
	 * @param string      $decisionId The concluded decision.
	 * @param string      $status     The outcome status the decision app reported.
	 * @param string|null $decidedAt  When it concluded (ISO 8601), or null.
	 *
	 * @return array<string, mixed>|null The product written or changed, or null.
	 *
	 * @spec openspec/changes/archive/2026-10-30-portal-permits-as-held-products/tasks.md#2.1
	 */
	public function issueFor(string $caseId, string $decisionId, string $status, ?string $decidedAt): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '' || trim($caseId) === '') {
			return null;
		}

		$case = $this->read(objectService: $objectService, register: $register, schema: 'case', id: $caseId);
		$declared = $this->declarationFor(objectService: $objectService, register: $register, caseObject: $case);
		if ($case === null || $declared === null) {
			return null;
		}

		$issueOn = (array)($declared['issueOn'] ?? self::DEFAULT_ISSUE_ON);
		if (in_array(strtolower(trim($status)), array_map('strtolower', $issueOn), true) === false) {
			return null;
		}

		$outcome = new ProductOutcome(objectService: $objectService, register: $register, caseObject: $case, declared: $declared);
		if (is_array($declared['changesProduct'] ?? null) === true) {
			return $this->change(outcome: $outcome);
		}

		return $this->issue(outcome: $outcome, decision: new ProductDecision(id: $decisionId, decidedAt: $decidedAt));
	}//end onConcludedDecision()

	/**
	 * The product declaration of the case's type, or null when it declares none.
	 *
	 * @param object                    $objectService OpenRegister's object service.
	 * @param string                    $register      The register.
	 * @param array<string, mixed>|null $caseObject    The case.
	 *
	 * @return array<string, mixed>|null The declaration.
	 */
	private function declarationFor(object $objectService, string $register, ?array $caseObject): ?array {
		if ($caseObject === null) {
			return null;
		}

		$type = $this->read(objectService: $objectService, register: $register, schema: 'caseType', id: (string)($caseObject['caseType'] ?? ''));
		$declared = (($type ?? [])['issuesPermit'] ?? null);
		if (is_array($declared) === false || $declared === []) {
			return null;
		}

		return $declared;
	}//end declarationFor()

	/**
	 * Write the case's product, once per decision.
	 *
	 * @param ProductOutcome  $outcome  The case and its declaration.
	 * @param ProductDecision $decision The decision.
	 *
	 * @return array<string, mixed>|null The product.
	 */
	private function issue(ProductOutcome $outcome, ProductDecision $decision): ?array {
		if (trim((string)($outcome->caseObject['portalSubject'] ?? '')) === '') {
			$this->logger->info('Dossiq: a case without a portal subject issues no product', ['case' => ($outcome->caseObject['id'] ?? '')]);
			return null;
		}

		$existing = $this->issuedFor(outcome: $outcome, decisionId: $decision->id);
		if ($existing !== null) {
			return $existing;
		}

		$product = $this->productFor(outcome: $outcome, decision: $decision);

		return $this->runAsSystemIfAvailable(
			objectService: $outcome->objectService,
			operation: fn (): ?array => $this->saveObjectAsArray(
				objectService: $outcome->objectService,
				register: $outcome->register,
				schema: $this->schemaOf(declared: $outcome->declared),
				object: $product
			)
		);
	}//end issue()

	/**
	 * The product already issued for this decision, or null.
	 *
	 * @param ProductOutcome $outcome    The case and its declaration.
	 * @param string         $decisionId The decision.
	 *
	 * @return array<string, mixed>|null The product.
	 */
	private function issuedFor(ProductOutcome $outcome, string $decisionId): ?array {
		$existing = $this->runAsSystemIfAvailable(
			objectService: $outcome->objectService,
			operation: fn (): array => $this->searchObjectsAsArraysUnscoped(
				objectService: $outcome->objectService,
				register: $outcome->register,
				schema: $this->schemaOf(declared: $outcome->declared),
				filters: ['decision' => $decisionId, '_limit' => 1],
			)
		);
		if (is_array($existing) === false || $existing === []) {
			return null;
		}

		return $existing[0];
	}//end issuedFor()

	/**
	 * The product a decision issues, without its empty fields.
	 *
	 * @param ProductOutcome  $outcome  The case and its declaration.
	 * @param ProductDecision $decision The decision.
	 *
	 * @return array<string, mixed> The product.
	 */
	private function productFor(ProductOutcome $outcome, ProductDecision $decision): array {
		$declared = $outcome->declared;
		$product = [
			'title' => $this->title(outcome: $outcome),
			'portalSubject' => trim((string)$outcome->caseObject['portalSubject']),
			'case' => (string)($outcome->caseObject['id'] ?? ''),
			'decision' => $decision->id,
			'validFrom' => $this->day(instant: $decision->decidedAt),
			'status' => 'active',
		];
		foreach (self::STATIC_FIELDS as $field) {
			$product[$field] = trim((string)($declared[$field] ?? ''));
		}

		foreach ((array)($declared['detailsFromCase'] ?? []) as $field => $source) {
			$product[(string)$field] = $outcome->stored(field: (string)$field, name: (string)$source);
		}

		return array_filter($product, static fn ($value): bool => $value !== '');
	}//end productFor()

	/**
	 * Set the fields a change case declares on the product it names.
	 *
	 * @param ProductOutcome $outcome The change case and its declaration.
	 *
	 * @return array<string, mixed>|null The product, changed.
	 */
	private function change(ProductOutcome $outcome): ?array {
		$changes = (array)$outcome->declared['changesProduct'];
		$schema = $this->schemaOf(declared: $outcome->declared);
		$productId = $outcome->answer(name: (string)($changes['answer'] ?? ''));
		$product = $this->read(objectService: $outcome->objectService, register: $outcome->register, schema: $schema, id: $productId);
		if ($product === null) {
			return null;
		}

		if ($this->inForceFor(outcome: $outcome, product: $product) === false) {
			$this->logger->warning(
				'Dossiq: a change case names a product that is not its requester\'s or not in force',
				['case' => ($outcome->caseObject['id'] ?? ''), 'product' => $productId]
			);
			return null;
		}

		$set = $this->changeSet(outcome: $outcome, set: (array)($changes['set'] ?? []));
		if ($set === []) {
			return null;
		}

		return $this->runAsSystemIfAvailable(
			objectService: $outcome->objectService,
			operation: fn (): ?array => $this->patchObjectAsArray(
				objectService: $outcome->objectService,
				register: $outcome->register,
				schema: $schema,
				id: $productId,
				changes: $set
			)
		);
	}//end change()

	/**
	 * Whether a product is the change case requester's own and still in force.
	 *
	 * A change case can name any id, and the decision is about this case.
	 *
	 * @param ProductOutcome       $outcome The change case.
	 * @param array<string, mixed> $product The product it names.
	 *
	 * @return bool True when the change may be made.
	 */
	private function inForceFor(ProductOutcome $outcome, array $product): bool {
		if ((string)($product['portalSubject'] ?? '') !== (string)($outcome->caseObject['portalSubject'] ?? '')) {
			return false;
		}

		return (string)($product['status'] ?? 'active') === 'active';
	}//end inForceFor()

	/**
	 * The product fields a change case sets, without the empty ones.
	 *
	 * @param ProductOutcome        $outcome The change case.
	 * @param array<string|int, mixed> $set  Product field => case answer name.
	 *
	 * @return array<string, string> The fields to set.
	 */
	private function changeSet(ProductOutcome $outcome, array $set): array {
		$changes = [];
		foreach ($set as $field => $answer) {
			$value = $outcome->stored(field: (string)$field, name: (string)$answer);
			if ($value !== '') {
				$changes[(string)$field] = $value;
			}
		}

		return $changes;
	}//end changeSet()

	/**
	 * The product schema a declaration names.
	 *
	 * @param array<string, mixed> $declared The declaration.
	 *
	 * @return string The schema slug.
	 */
	private function schemaOf(array $declared): string {
		$schema = trim((string)($declared['schema'] ?? ''));
		if ($schema === '') {
			return self::DEFAULT_SCHEMA;
		}

		return $schema;
	}//end schemaOf()

	/**
	 * The product title: the template with case values in double braces.
	 *
	 * @param ProductOutcome $outcome The case and its declaration.
	 *
	 * @return string The title.
	 */
	private function title(ProductOutcome $outcome): string {
		$template = (string)($outcome->declared['titleTemplate'] ?? '');
		if (trim($template) === '') {
			return trim((string)($outcome->caseObject['title'] ?? ''));
		}

		return trim(
			(string)preg_replace_callback(
				'/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
				fn (array $match): string => $outcome->answer(name: $match[1]),
				$template
			)
		);
	}//end title()

	/**
	 * The calendar day of an instant, or today.
	 *
	 * @param string|null $instant The instant.
	 *
	 * @return string `Y-m-d`.
	 */
	private function day(?string $instant): string {
		$instant = trim((string)$instant);
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $instant) === 1) {
			return substr($instant, 0, 10);
		}

		return date('Y-m-d');
	}//end day()

	/**
	 * Read one object as the system, or null.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 * @param string $schema        The schema slug.
	 * @param string $id            The object id.
	 *
	 * @return array<string, mixed>|null The object.
	 */
	private function read(object $objectService, string $register, string $schema, string $id): ?array {
		if (trim($id) === '') {
			return null;
		}

		$found = $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): ?array => $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id)
		);

		if (is_array($found) === false) {
			return null;
		}

		return $found;
	}//end read()
}//end class
