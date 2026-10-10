<?php

/**
 * Dossiq Woo search plans
 *
 * The search plan of a Woo request and the configuration a next request starts
 * from (woo-request-corpus-collection, REQ-WRC-001 and REQ-WRC-005).
 *
 * A plan says, before anyone searches, whose files and which systems are
 * searched over which period and with which terms, so a decision can show
 * that the search was planned. A plan without `recordedAt` is a draft and
 * counts as no plan: the gather endpoints refuse to search or add until one is
 * recorded. Every change is a save of the same OpenRegister object, so the
 * object's audit trail is the plan's history.
 *
 * Recording a plan also writes the case's `wooRequestConfiguration`: the
 * custodians, systems and terms, never the period. A new Woo case that starts
 * from an earlier one copies every key of that configuration, so a later
 * change that adds a key is copied too.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Reads, records and copies Woo search plans and request configurations.
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
 */
class WooSearchPlans {

	use SearchesObjects;

	/**
	 * The plan's config key.
	 */
	public const PLAN_SCHEMA = 'woo_search_plan_schema';

	/**
	 * The configuration's config key.
	 */
	public const CONFIGURATION_SCHEMA = 'woo_request_configuration_schema';

	/**
	 * The keys a configuration never copies: identity and the case it belongs to.
	 */
	private const NOT_COPIED = ['id', 'uuid', '@self', 'case', 'copiedFrom'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The register, the schemas and OpenRegister.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The case's plan, draft or recorded, or null.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The plan row.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
	 */
	public function find(string $caseId): ?array {
		return $this->rowOfCase(configKey: self::PLAN_SCHEMA, caseId: $caseId);
	}//end find()

	/**
	 * The case's recorded plan, or null while it has none or only a draft.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The plan row.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
	 */
	public function recorded(string $caseId): ?array {
		$plan = $this->find(caseId: $caseId);
		if ($plan === null || trim((string)($plan['recordedAt'] ?? '')) === '') {
			return null;
		}

		return $plan;
	}//end recorded()

	/**
	 * The names of a plan's custodians.
	 *
	 * @param array<string, mixed> $plan The plan row.
	 *
	 * @return array<int, string> The names.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-collection-is-reported-per-custodian-and-system-req-wrc-002
	 */
	public function custodianNames(array $plan): array {
		$names = [];
		foreach ((array)($plan['custodians'] ?? []) as $custodian) {
			$name = trim((string)(((array)$custodian)['name'] ?? ''));
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return array_values(array_unique($names));
	}//end custodianNames()

	/**
	 * Record the case's plan, and keep its configuration in step.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $input  `{custodians, systems, periodFrom, periodTo, terms}`.
	 * @param string               $userId Who records it.
	 *
	 * @return array<string, mixed> The plan as stored.
	 *
	 * @throws WooCorpusRefused When the plan is incomplete.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
	 */
	public function record(string $caseId, array $input, string $userId): array {
		$plan = $this->validated(input: $input);
		$plan['case'] = $caseId;
		$plan['recordedBy'] = $userId;
		$plan['recordedAt'] = gmdate('Y-m-d\TH:i:s\Z');

		$existing = $this->find(caseId: $caseId);
		$saved = $this->save(configKey: self::PLAN_SCHEMA, row: $plan, uuid: $this->idOf(row: (array)$existing));

		$configuration = ($this->rowOfCase(configKey: self::CONFIGURATION_SCHEMA, caseId: $caseId) ?? []);
		$configuration['case'] = $caseId;
		$configuration['custodians'] = $plan['custodians'];
		$configuration['systems'] = $plan['systems'];
		$configuration['terms'] = $plan['terms'];
		$this->save(configKey: self::CONFIGURATION_SCHEMA, row: $configuration, uuid: $this->idOf(row: $configuration));

		return $saved;
	}//end record()

	/**
	 * Start a case from an earlier Woo case's configuration: copy every key, and a draft plan without the period.
	 *
	 * @param string $caseId The new case uuid.
	 * @param string $from   The earlier Woo case uuid.
	 *
	 * @return array<string, mixed>|null The new configuration, null when the earlier case has none.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-new-request-starts-from-the-configuration-of-an-earlier-one-req-wrc-005
	 */
	public function startFrom(string $caseId, string $from): ?array {
		$source = $this->rowOfCase(configKey: self::CONFIGURATION_SCHEMA, caseId: $from);
		if ($source === null || $from === $caseId) {
			return null;
		}

		$configuration = array_diff_key($source, array_flip(self::NOT_COPIED));
		$configuration['case'] = $caseId;
		$configuration['copiedFrom'] = $from;
		$saved = $this->save(configKey: self::CONFIGURATION_SCHEMA, row: $configuration, uuid: '');

		$this->save(
			configKey: self::PLAN_SCHEMA,
			row: [
				'case' => $caseId,
				'custodians' => (array)($configuration['custodians'] ?? []),
				'systems' => (array)($configuration['systems'] ?? []),
				'terms' => (string)($configuration['terms'] ?? ''),
			],
			uuid: ''
		);

		return $saved;
	}//end startFrom()

	/**
	 * The plan the input describes, or a refusal naming what is missing.
	 *
	 * @param array<string, mixed> $input The input.
	 *
	 * @return array<string, mixed> `{custodians, systems, periodFrom, periodTo, terms}`.
	 *
	 * @throws WooCorpusRefused When a part is missing or wrong.
	 */
	private function validated(array $input): array {
		$custodians = [];
		foreach ((array)($input['custodians'] ?? []) as $custodian) {
			if (is_array($custodian) === false) {
				$custodian = ['name' => $custodian];
			}

			$name = trim((string)($custodian['name'] ?? ''));
			if ($name === '') {
				continue;
			}

			$row = ['name' => $name, 'function' => trim((string)($custodian['function'] ?? ''))];
			$userId = trim((string)($custodian['userId'] ?? ''));
			if ($userId !== '') {
				$row['userId'] = $userId;
			}

			$custodians[] = $row;
		}

		$systems = array_values(array_unique(array_filter(array_map('strval', (array)($input['systems'] ?? [])), [WooSources::class, 'isKnown'])));
		$from = trim((string)($input['periodFrom'] ?? ''));
		$until = trim((string)($input['periodTo'] ?? ''));
		$terms = trim((string)($input['terms'] ?? ''));

		$missing = match (true) {
			$custodians === [] => 'custodians',
			$systems === [] => 'systems',
			$this->isDate(value: $from) === false || $this->isDate(value: $until) === false || $from > $until => 'period',
			$terms === '' => 'terms',
			default => '',
		};
		if ($missing !== '') {
			throw new WooCorpusRefused(reason: 'plan_incomplete_' . $missing, status: 400);
		}

		return ['custodians' => $custodians, 'systems' => $systems, 'periodFrom' => $from, 'periodTo' => $until, 'terms' => $terms];
	}//end validated()

	/**
	 * Whether a value is a Y-m-d date.
	 *
	 * @param string $value The value.
	 *
	 * @return bool True when it is.
	 */
	private function isDate(string $value): bool {
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
	}//end isDate()

	/**
	 * The one row of a schema that names the case, or null.
	 *
	 * @param string $configKey The schema's config key.
	 * @param string $caseId    The case uuid.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	private function rowOfCase(string $configKey, string $caseId): ?array {
		[$objectService, $register, $schema] = $this->target(configKey: $configKey);
		if ($caseId === '') {
			return null;
		}

		$filters = ['case' => $caseId, '_limit' => 5];
		$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: $filters);
		foreach ($rows as $row) {
			if ((string)($row['case'] ?? '') === $caseId) {
				return $row;
			}
		}

		return null;
	}//end rowOfCase()

	/**
	 * Save a row, new or existing.
	 *
	 * @param string               $configKey The schema's config key.
	 * @param array<string, mixed> $row       The row.
	 * @param string               $uuid      The existing uuid, '' for a new row.
	 *
	 * @return array<string, mixed> The row as stored.
	 */
	private function save(string $configKey, array $row, string $uuid): array {
		[$objectService, $register, $schema] = $this->target(configKey: $configKey);
		unset($row['id'], $row['uuid'], $row['@self']);

		if ($uuid === '') {
			$saved = $objectService->saveObject(object: $row, register: $register, schema: $schema);
		} else {
			$saved = $objectService->saveObject(object: $row, register: $register, schema: $schema, uuid: $uuid);
		}

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			$saved = $saved->jsonSerialize();
		}

		return (array)$saved;
	}//end save()

	/**
	 * OpenRegister, the register and one schema, or a refusal.
	 *
	 * @param string $configKey The schema's config key.
	 *
	 * @return array{0: object, 1: string, 2: string}
	 *
	 * @throws RuntimeException When OpenRegister or the schema is not there.
	 */
	private function target(string $configKey): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($configKey);
		if ($objectService === null || $register === '' || $schema === '') {
			throw new RuntimeException('The Woo corpus schemas are not configured');
		}

		return [$objectService, $register, $schema];
	}//end target()

	/**
	 * A row's uuid.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or ''.
	 */
	private function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);
		return trim((string)($row['id'] ?? ($row['uuid'] ?? ($self['id'] ?? ''))));
	}//end idOf()
}//end class
