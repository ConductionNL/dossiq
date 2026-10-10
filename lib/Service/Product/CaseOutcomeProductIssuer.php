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
 * @spec openspec/changes/portal-permits-as-held-products/tasks.md#2.1
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
	 * @spec openspec/changes/portal-permits-as-held-products/tasks.md#2.1
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
	 * @spec openspec/changes/portal-permits-as-held-products/tasks.md#2.1
	 */
	public function issueFor(string $caseId, string $decisionId, string $status, ?string $decidedAt): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '' || trim($caseId) === '') {
			return null;
		}

		$case = $this->read(objectService: $objectService, register: $register, schema: 'case', id: $caseId);
		$declared = $this->declarationFor(objectService: $objectService, register: $register, case: $case);
		if ($case === null || $declared === null) {
			return null;
		}

		$issueOn = (array)($declared['issueOn'] ?? self::DEFAULT_ISSUE_ON);
		if (in_array(strtolower(trim($status)), array_map('strtolower', $issueOn), true) === false) {
			return null;
		}

		if (is_array($declared['changesProduct'] ?? null) === true) {
			return $this->change(objectService: $objectService, register: $register, case: $case, declared: $declared);
		}

		return $this->issue(
			objectService: $objectService,
			register: $register,
			case: $case,
			declared: $declared,
			decisionId: $decisionId,
			decidedAt: $decidedAt
		);
	}//end onConcludedDecision()

	/**
	 * The product declaration of the case's type, or null when it declares none.
	 *
	 * @param object                    $objectService OpenRegister's object service.
	 * @param string                    $register      The register.
	 * @param array<string, mixed>|null $case          The case.
	 *
	 * @return array<string, mixed>|null The declaration.
	 */
	private function declarationFor(object $objectService, string $register, ?array $case): ?array {
		if ($case === null) {
			return null;
		}

		$type = $this->read(objectService: $objectService, register: $register, schema: 'caseType', id: (string)($case['caseType'] ?? ''));
		$declared = (($type ?? [])['issuesPermit'] ?? null);
		if (is_array($declared) === false || $declared === []) {
			return null;
		}

		return $declared;
	}//end declarationFor()

	/**
	 * Write the case's product, once per decision.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param array<string, mixed> $case          The case.
	 * @param array<string, mixed> $declared      The case type's product declaration.
	 * @param string               $decisionId    The decision.
	 * @param string|null          $decidedAt     When it concluded.
	 *
	 * @return array<string, mixed>|null The product.
	 */
	private function issue(object $objectService, string $register, array $case, array $declared, string $decisionId, ?string $decidedAt): ?array {
		$holder = trim((string)($case['portalSubject'] ?? ''));
		if ($holder === '') {
			$this->logger->info('Dossiq: a case without a portal subject issues no product', ['case' => ($case['id'] ?? '')]);
			return null;
		}

		$schema = $this->schemaOf(declared: $declared);
		$existing = $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): array => $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['decision' => $decisionId, '_limit' => 1],
			)
		);
		if (is_array($existing) === true && $existing !== []) {
			return $existing[0];
		}

		$product = [
			'title' => $this->title(template: (string)($declared['titleTemplate'] ?? ''), case: $case),
			'portalSubject' => $holder,
			'case' => (string)($case['id'] ?? ''),
			'decision' => $decisionId,
			'validFrom' => $this->day(instant: $decidedAt),
			'status' => 'active',
		];
		foreach (self::STATIC_FIELDS as $field) {
			$product[$field] = trim((string)($declared[$field] ?? ''));
		}

		foreach ((array)($declared['detailsFromCase'] ?? []) as $field => $source) {
			$product[(string)$field] = $this->shaped(declared: $declared, field: (string)$field, value: $this->caseValue(case: $case, name: (string)$source));
		}

		$product = array_filter($product, static fn ($value): bool => $value !== '');

		return $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): ?array => $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $product
			)
		);
	}//end issue()

	/**
	 * Set the fields a change case declares on the product it names.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param array<string, mixed> $case          The change case.
	 * @param array<string, mixed> $declared      The change case type's product declaration.
	 *
	 * @return array<string, mixed>|null The product, changed.
	 */
	private function change(object $objectService, string $register, array $case, array $declared): ?array {
		$changes = (array)$declared['changesProduct'];
		$schema = $this->schemaOf(declared: $declared);
		$productId = $this->caseValue(case: $case, name: (string)($changes['answer'] ?? ''));
		$product = $this->read(objectService: $objectService, register: $register, schema: $schema, id: $productId);
		if ($product === null) {
			return null;
		}

		// Only the requester's own product, and only while it is in force: a
		// change case can name any id, and the decision is about this case.
		if ((string)($product['portalSubject'] ?? '') !== (string)($case['portalSubject'] ?? '')
			|| (string)($product['status'] ?? 'active') !== 'active'
		) {
			$this->logger->warning(
				'Dossiq: a change case names a product that is not its requester\'s or not in force',
				['case' => ($case['id'] ?? ''), 'product' => $productId]
			);
			return null;
		}

		$set = [];
		foreach ((array)($changes['set'] ?? []) as $field => $answer) {
			$value = $this->shaped(declared: $declared, field: (string)$field, value: $this->caseValue(case: $case, name: (string)$answer));
			if ($value !== '') {
				$set[(string)$field] = $value;
			}
		}

		if ($set === []) {
			return null;
		}

		return $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): ?array => $this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $productId,
				changes: $set
			)
		);
	}//end change()

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
	 * A value as the declaration wants it stored.
	 *
	 * @param array<string, mixed> $declared The declaration.
	 * @param string               $field    The product field.
	 * @param string               $value    The raw value.
	 *
	 * @return string The value.
	 */
	private function shaped(array $declared, string $field, string $value): string {
		if (in_array($field, (array)($declared['compactFields'] ?? []), true) === true) {
			return strtoupper((string)preg_replace('/[\s-]+/', '', $value));
		}

		return $value;
	}//end shaped()

	/**
	 * A case value by name: a top-level field, else a case-type property answer.
	 *
	 * @param array<string, mixed> $case The case.
	 * @param string               $name The name.
	 *
	 * @return string The value, or ''.
	 */
	private function caseValue(array $case, string $name): string {
		if ($name === '') {
			return '';
		}

		if (is_scalar($case[$name] ?? null) === true) {
			return trim((string)$case[$name]);
		}

		foreach ((array)($case['properties'] ?? []) as $answer) {
			if (is_array($answer) === true && ($answer['name'] ?? null) === $name && is_scalar($answer['value'] ?? null) === true) {
				return trim((string)$answer['value']);
			}
		}

		return '';
	}//end caseValue()

	/**
	 * The product title: the template with case values in double braces.
	 *
	 * @param string               $template The title template.
	 * @param array<string, mixed> $case     The case.
	 *
	 * @return string The title.
	 */
	private function title(string $template, array $case): string {
		if (trim($template) === '') {
			return trim((string)($case['title'] ?? ''));
		}

		return trim(
			(string)preg_replace_callback(
				'/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
				fn (array $match): string => $this->caseValue(case: $case, name: $match[1]),
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
