<?php

/**
 * Dossiq Woo delivered set writer
 *
 * Every Woo delivery writes a set with its own identity and manifest
 * (woo-delivered-set-is-a-record REQ-WDS-001, REQ-WDS-002). The set is
 * written pending before the publication is created, frozen with the
 * publication id after, and deleted when publishing fails. A later delivery
 * on the same case names the frozen set it supersedes; a withdraw stamps
 * `withdrawnAt` and leaves the set frozen.
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
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Writes, freezes, discards and stamps a delivered set.
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
 */
class WooDeliveredSetWriter {

	use SearchesObjects;

	/**
	 * The app-config key naming the set schema.
	 */
	public const CONFIG_KEY = 'woo_delivered_set_schema';

	/**
	 * The set schema's slug, used when the config key is not set yet.
	 */
	public const SCHEMA_SLUG = 'wooDeliveredSet';

	/**
	 * A set that is being delivered.
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * A set whose publication exists.
	 */
	public const STATUS_FROZEN = 'frozen';

	/**
	 * The failure a delivery answers when its set cannot be written first.
	 */
	public const SET_NOT_WRITTEN = 'woo_delivered_set_not_written';

	/**
	 * Constructor.
	 *
	 * @param SettingsService      $settings    Bridge to OpenRegister.
	 * @param IUserSession|null    $userSession Who delivers.
	 * @param LoggerInterface|null $logger      Logger.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly ?IUserSession $userSession = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Write the pending set, send, then freeze it; delete it when the send fails.
	 *
	 * @param string                           $caseId     The Woo case.
	 * @param string                           $decisionId The Woo decision.
	 * @param array<int, array<string, mixed>> $delivered  What goes out (see open()).
	 * @param callable(): string               $send       Creates or updates the publication; answers its id.
	 *
	 * @return array{publicationId: string, setId: string}
	 *
	 * @throws RuntimeException SET_NOT_WRITTEN when the set cannot be written; nothing is sent then.
	 * @throws Throwable        The send's own failure, after the pending set is deleted.
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
	 */
	public function deliver(string $caseId, string $decisionId, array $delivered, callable $send): array {
		try {
			$setId = $this->idOf(row: $this->open(caseId: $caseId, decisionId: $decisionId, delivered: $delivered));
		} catch (Throwable $e) {
			$this->logger?->error('Dossiq: the delivered Woo set could not be written, so nothing was sent', ['case' => $caseId, 'error' => $e->getMessage()]);
			throw new RuntimeException(self::SET_NOT_WRITTEN, 0, $e);
		}

		try {
			$publicationId = (string)$send();
		} catch (Throwable $e) {
			$this->quietly(what: 'delete the pending set of a failed delivery', operation: fn () => $this->discard(setId: $setId));
			throw $e;
		}

		$this->quietly(what: 'freeze the delivered set; it stays pending', operation: fn () => $this->freeze(setId: $setId, publicationId: $publicationId));

		return ['publicationId' => $publicationId, 'setId' => $setId];
	}//end deliver()

	/**
	 * Run a follow-up write whose failure is logged, never thrown.
	 *
	 * @param string   $what      What failed, for the log.
	 * @param callable $operation The write.
	 *
	 * @return void
	 */
	private function quietly(string $what, callable $operation): void {
		try {
			$operation();
		} catch (Throwable $e) {
			$this->logger?->error('Dossiq: could not ' . $what, ['error' => $e->getMessage()]);
		}
	}//end quietly()

	/**
	 * The set hash: SHA-256 over `<sha256>  <deliveredRef>` lines, sorted by `deliveredRef`, joined by newlines.
	 *
	 * @param array<int, array<string, mixed>> $items The items, each with `sha256` and `deliveredRef`.
	 *
	 * @return string The hex hash.
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
	 */
	public function setHash(array $items): string {
		$lines = [];
		foreach ($items as $item) {
			$lines[(string)($item['deliveredRef'] ?? '')] = (string)($item['sha256'] ?? '') . '  ' . (string)($item['deliveredRef'] ?? '');
		}

		ksort($lines, SORT_STRING);

		return hash('sha256', implode("\n", array_values($lines)));
	}//end setHash()

	/**
	 * Write the pending set of one delivery.
	 *
	 * @param string                           $caseId     The Woo case.
	 * @param string                           $decisionId The Woo decision.
	 * @param array<int, array<string, mixed>> $delivered  One entry per delivered document:
	 *                                                     `assessment`, `classification`, `deliveredRef`,
	 *                                                     `originalRef`, `fileName` and `content` (base64 bytes).
	 *
	 * @return array<string, mixed> The stored set, with its `id`.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or the set cannot be stored.
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
	 */
	public function open(string $caseId, string $decisionId, array $delivered): array {
		[$objectService, $register, $schema] = $this->store();

		$items = [];
		foreach ($delivered as $entry) {
			$bytes = (string)base64_decode((string)($entry['content'] ?? ''), true);
			$items[] = [
				'assessment' => (string)($entry['assessment'] ?? ''),
				'classification' => (string)($entry['classification'] ?? ''),
				'deliveredRef' => (string)($entry['deliveredRef'] ?? ''),
				'originalRef' => (string)($entry['originalRef'] ?? ''),
				'fileName' => (string)($entry['fileName'] ?? ''),
				'size' => strlen($bytes),
				'sha256' => hash('sha256', $bytes),
			];
		}

		$previous = $this->latestFrozen(caseId: $caseId);
		$set = [
			'case' => $caseId,
			'decision' => $decisionId,
			'status' => self::STATUS_PENDING,
			'deliveredAt' => (new DateTimeImmutable())->format('c'),
			'deliveredBy' => (string)($this->userSession?->getUser()?->getUID() ?? ''),
			'supersedes' => $this->idOf(row: ($previous ?? [])),
			'items' => $items,
			'setHash' => $this->setHash(items: $items),
		];

		$stored = $this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $set);
		if ($stored === null || $this->idOf(row: $stored) === '') {
			throw new RuntimeException(self::SET_NOT_WRITTEN);
		}

		return $stored;
	}//end open()

	/**
	 * Freeze the set with its publication.
	 *
	 * @param string $setId         The set.
	 * @param string $publicationId The publication it went into.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-frozen-set-and-its-assessments-refuse-change-req-wds-002
	 */
	public function freeze(string $setId, string $publicationId): void {
		[$objectService, $register, $schema] = $this->store();
		$this->patchObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $setId,
			changes: ['status' => self::STATUS_FROZEN, 'publication' => $publicationId]
		);
	}//end freeze()

	/**
	 * Delete a pending set whose publication failed.
	 *
	 * @param string $setId The set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
	 */
	public function discard(string $setId): void {
		[$objectService, $register, $schema] = $this->store();
		$objectService->deleteObject(register: $register, schema: $schema, uuid: $setId);
	}//end discard()

	/**
	 * Stamp `withdrawnAt` on the frozen set of a publication; the set stays frozen.
	 *
	 * @param string $caseId        The case.
	 * @param string $publicationId The withdrawn publication.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-frozen-set-and-its-assessments-refuse-change-req-wds-002
	 */
	public function markWithdrawn(string $caseId, string $publicationId): void {
		[$objectService, $register, $schema] = $this->store();
		foreach ($this->frozenSets(caseId: $caseId) as $set) {
			if ((string)($set['publication'] ?? '') !== $publicationId || empty($set['withdrawnAt']) === false) {
				continue;
			}

			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $this->idOf(row: $set),
				changes: ['withdrawnAt' => (new DateTimeImmutable())->format('c')]
			);
		}
	}//end markWithdrawn()

	/**
	 * The frozen sets of a case, oldest first.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-frozen-set-and-its-assessments-refuse-change-req-wds-002
	 */
	public function frozenSets(string $caseId): array {
		[$objectService, $register, $schema] = $this->store();
		$rows = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['case' => $caseId, 'status' => self::STATUS_FROZEN, '_limit' => 200]
		);
		$rows = array_values(array_filter(
			$rows,
			static fn (array $row): bool => (string)($row['case'] ?? '') === $caseId && (string)($row['status'] ?? '') === self::STATUS_FROZEN
		));
		usort($rows, static fn (array $a, array $b): int => strcmp((string)($a['deliveredAt'] ?? ''), (string)($b['deliveredAt'] ?? '')));

		return $rows;
	}//end frozenSets()

	/**
	 * The latest frozen set of a case, or null.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<string, mixed>|null
	 */
	private function latestFrozen(string $caseId): ?array {
		$sets = $this->frozenSets(caseId: $caseId);
		if ($sets === []) {
			return null;
		}

		return $sets[(count($sets) - 1)];
	}//end latestFrozen()

	/**
	 * The object service, register and set schema.
	 *
	 * @return array{0: object, 1: string, 2: string}
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 */
	private function store(): array {
		$objectService = $this->settings->getObjectService();
		$register = (string)$this->settings->getConfigValue('register');
		if ($objectService === null || $register === '') {
			throw new RuntimeException('woo_delivered_set_store_unavailable');
		}

		$schema = (string)$this->settings->getConfigValue(self::CONFIG_KEY);
		if ($schema === '') {
			$schema = self::SCHEMA_SLUG;
		}

		return [$objectService, $register, $schema];
	}//end store()

	/**
	 * A row's id, wherever OpenRegister put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-every-delivery-writes-a-set-with-its-own-identity-and-manifest-req-wds-001
	 */
	public function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? (($row['@self'] ?? [])['id'] ?? '')));
	}//end idOf()
}//end class
