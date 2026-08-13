<?php

/**
 * Procest TermijnService.
 *
 * Server-authoritative service for the AWB termijnbewaking engine. Owns
 * the TermijnInstance lifecycle: create, get, update, complete. Resolves
 * the active TermijnDefinitie for a zaaktype (version-aware) and binds it
 * to a zaak on creation. Writes immutable TermijnGebeurtenis events for
 * every state change.
 *
 * Money / day amounts: all *daily impact* values are integers (days);
 * money values live on DwangsomBerekening / DwangsomUitbetaling and use
 * integer EUR cents (ADR-031).
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Server-authoritative TermijnInstance lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TermijnService {
	use SearchesObjects;

	/**
	 * Per-request TermijnDefinitie cache keyed by zaaktype.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $definitieCache = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings + ObjectService access.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a new TermijnInstance for a zaak.
	 *
	 * Resolves the active TermijnDefinitie for the zaaktype, computes
	 * einddatumBerekend = startDatum + standaardDuurDagen, persists the
	 * instance, and writes a `start` TermijnGebeurtenis. Throws if no
	 * matching definition exists (REQ-TERM-001-A).
	 *
	 * @param string $caseId The case id.
	 * @param string $caseType The zaaktype slug.
	 * @param DateTimeImmutable|null $startDate Optional start (defaults to now).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When no TermijnDefinitie matches the zaaktype.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function createTermijnInstance(string $caseId, string $caseType, ?DateTimeImmutable $startDate = null): array {
		$startDate = ($startDate ?? new DateTimeImmutable());
		$definitie = $this->getTermijnDefinitie(caseType: $caseType);
		if ($definitie === null) {
			throw new RuntimeException(
				'No active TermijnDefinitie configured for zaaktype "' . $caseType . '" (REQ-TERM-001-A)'
			);
		}

		$durationDays = (int)($definitie['standardDurationDays'] ?? 0);
		$endDate = $startDate->modify('+' . $durationDays . ' days')->format('Y-m-d');

		$instance = [
			'zaak' => $caseId,
			'termijnDefinitie' => (string)($definitie['id'] ?? ''),
			'startDate' => $startDate->format('Y-m-d\TH:i:sP'),
			'endDateCalculated' => $endDate,
			'endDateActueel' => $endDate,
			'status' => 'lopend',
			'countVerlengingen' => 0,
			'notificatiesVerstuurd' => [],
		];

		$saved = $this->save(schemaConfigKey: 'termijn_instance_schema', object: $instance);
		if ($saved === null) {
			throw new RuntimeException(
				'Failed to persist TermijnInstance for zaak "' . $caseId . '" (persistence unavailable)'
			);
		}

		$this->recordEvent(
			termInstanceId: (string)($saved['id'] ?? ''),
			type: 'start',
			basis: (string)($definitie['legalBasis'] ?? 'AWB 4:13'),
			rationale: 'Termijn gestart bij zaak-aanmaak',
			daysImpact: $durationDays,
			moment: $startDate,
		);

		return $saved;
	}//end createTermijnInstance()

	/**
	 * Get TermijnInstance by id.
	 *
	 * @param string $termInstanceId Instance id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function getTermijnInstance(string $termInstanceId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$row = $objectService->find($termInstanceId, register: $register, schema: $schema);
			if (is_array($row) === true) {
				return $row;
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'TermijnService.getTermijnInstance failed',
				['id' => $termInstanceId, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end getTermijnInstance()

	/**
	 * Fetch the active TermijnInstance bound to a zaak (latest by start).
	 *
	 * @param string $caseId Case id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function getTermijnInstanceForZaak(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: ['zaak' => $caseId]);
		} catch (\Throwable $e) {
			return null;
		}

		if (count($rows) === 0) {
			return null;
		}

		usort(
			$rows,
			static fn (array $a, array $b): int
				=> strcmp((string)($b['startDate'] ?? ''), (string)($a['startDate'] ?? ''))
		);

		return $rows[0];
	}//end getTermijnInstanceForZaak()

	/**
	 * Update a TermijnInstance (partial; merged on top of existing).
	 *
	 * @param string $termInstanceId Instance id.
	 * @param array<string, mixed> $patch Partial patch.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function updateTermijnInstance(string $termInstanceId, array $patch): ?array {
		$current = $this->getTermijnInstance(termInstanceId: $termInstanceId);
		if ($current === null) {
			return null;
		}

		$merged = array_merge($current, $patch);
		$merged['id'] = $termInstanceId;
		return $this->save(schemaConfigKey: 'termijn_instance_schema', object: $merged);
	}//end updateTermijnInstance()

	/**
	 * Resolve the active TermijnDefinitie for a zaaktype.
	 *
	 * Version-aware: returns the definition with the latest validFrom that
	 * is <= today, where validUntil is null or > today.
	 *
	 * @param string $caseType Zaaktype slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function getTermijnDefinitie(string $caseType): ?array {
		if (isset($this->definitieCache[$caseType]) === true) {
			return $this->definitieCache[$caseType];
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_definitie_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['caseType' => $caseType]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'TermijnService.getTermijnDefinitie lookup failed',
				['caseType' => $caseType, 'error' => $e->getMessage()]
			);
			return null;
		}

		$today = (new DateTimeImmutable())->format('Y-m-d');
		$active = $this->filterActiveDefinities(rows: $rows, today: $today);

		if (count($active) === 0) {
			return null;
		}

		usort(
			$active,
			static fn (array $a, array $b): int
				=> strcmp((string)($b['validFrom'] ?? ''), (string)($a['validFrom'] ?? ''))
		);

		$this->definitieCache[$caseType] = $active[0];
		return $active[0];
	}//end getTermijnDefinitie()

	/**
	 * Keep the TermijnDefinitie rows whose validity window covers today.
	 *
	 * @param array<int, array<string, mixed>> $rows Candidate definitions.
	 * @param string $today Today's date as `Y-m-d`.
	 *
	 * @return array<int, array<string, mixed>> The definitions valid today.
	 */
	private function filterActiveDefinities(array $rows, string $today): array {
		$active = [];
		foreach ($rows as $row) {
			$validFrom = (string)($row['validFrom'] ?? '1970-01-01');
			$validUntil = (string)($row['validUntil'] ?? '');
			if ($validFrom <= $today && ($validUntil === '' || $validUntil >= $today)) {
				$active[] = $row;
			}
		}//end foreach

		return $active;
	}//end filterActiveDefinities()

	/**
	 * Mark a TermijnInstance as completed.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param DateTimeImmutable|null $voltooiDatum When completed (default now).
	 * @param string $documentLink Optional document ref.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-06-dwangsom-calculation/tasks.md
	 */
	public function markTermijnCompleted(
		string $termInstanceId,
		?DateTimeImmutable $voltooiDatum = null,
		string $documentLink = '',
	): ?array {
		$voltooiDatum = ($voltooiDatum ?? new DateTimeImmutable());

		$updated = $this->updateTermijnInstance(
			termInstanceId: $termInstanceId,
			patch: ['status' => 'voltooid', 'voltooiDatum' => $voltooiDatum->format('Y-m-d')]
		);

		if ($updated !== null) {
			$this->recordEvent(
				termInstanceId: $termInstanceId,
				type: 'voltooi',
				basis: 'AWB 4:13',
				rationale: 'Termijn voltooid door beschikking',
				daysImpact: 0,
				moment: $voltooiDatum,
				documentLink: $documentLink,
			);
		}

		return $updated;
	}//end markTermijnCompleted()

	/**
	 * Append an immutable TermijnGebeurtenis row.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param string $type Event type.
	 * @param string $basis Legal basis.
	 * @param string $rationale Reason.
	 * @param int $daysImpact Days impact.
	 * @param DateTimeImmutable|null $moment When (default now).
	 * @param string $documentLink Optional document ref.
	 * @param string $actor Optional actor (default 'system').
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function recordEvent(
		string $termInstanceId,
		string $type,
		string $basis,
		string $rationale,
		int $daysImpact,
		?DateTimeImmutable $moment = null,
		string $documentLink = '',
		string $actor = 'system',
	): ?array {
		$moment = ($moment ?? new DateTimeImmutable());
		$event = [
			'termijnInstance' => $termInstanceId,
			'type' => $type,
			'moment' => $moment->format('Y-m-d\TH:i:sP'),
			'actor' => $actor,
			'basis' => $basis,
			'rationale' => $rationale,
			'daysImpact' => $daysImpact,
		];
		if ($documentLink !== '') {
			$event['documentLink'] = $documentLink;
		}

		return $this->save(schemaConfigKey: 'termijn_gebeurtenis_schema', object: $event);
	}//end recordEvent()

	/**
	 * Persist an object to a configured schema.
	 *
	 * @param string $schemaConfigKey The schema config key (e.g. 'termijn_instance_schema').
	 * @param array<string, mixed> $object The payload.
	 *
	 * @return array<string, mixed>|null
	 */
	private function save(string $schemaConfigKey, array $object): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue($schemaConfigKey);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$saved = $objectService->saveObject($register, $schema, $object);
			if (is_array($saved) === true) {
				return $saved;
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->error(
				'TermijnService persist failed',
				['schemaConfigKey' => $schemaConfigKey, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end save()
}//end class
