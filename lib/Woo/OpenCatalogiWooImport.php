<?php

/**
 * Dossiq import of opencatalogi's stored Woo requests
 *
 * Decision D1: dossiq takes the Woo request over from opencatalogi. Every
 * `wooRequest` opencatalogi already holds becomes one dossiq Woo case, with
 * its term carried at the date the requester was told, exactly once. The
 * source is stamped `migratedTo`/`migratedAt` only after the case and its
 * timer both exist, so a run that stopped half way is finished by the next
 * one without a second case. opencatalogi's own term timer is never touched:
 * each migrated request is answered with it, so the caller can stop it.
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
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-every-stored-opencatalogi-request-is-imported-exactly-once-req-wto-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves every stored opencatalogi Woo request onto a dossiq Woo case.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-every-stored-opencatalogi-request-is-imported-exactly-once-req-wto-004
 */
class OpenCatalogiWooImport {

	use SearchesObjects;

	/**
	 * The app whose requests are imported. Its id has not moved (fleet rename map).
	 */
	public const SOURCE_APP = 'opencatalogi';

	/**
	 * Where opencatalogi keeps its Woo requests, by slug.
	 */
	public const SOURCE_REGISTER = 'publication';

	public const SOURCE_SCHEMA = 'wooRequest';

	/**
	 * One page of a listing.
	 */
	private const PAGE = 500;

	/**
	 * Term outcomes that leave an open case watched.
	 */
	private const ARMED = [OpenCatalogiWooTerm::KEPT, OpenCatalogiWooTerm::CARRIED];

	/**
	 * Constructor.
	 *
	 * @param SettingsService      $settingsService Register, schemas and OpenRegister.
	 * @param IAppManager          $appManager      Whether opencatalogi is installed.
	 * @param OpenCatalogiWooCase  $mapper          One source row onto a case payload.
	 * @param OpenCatalogiWooTerm  $term            The term carried onto the written case.
	 * @param ITimeFactory         $time            The moment of the stamp.
	 * @param LoggerInterface      $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppManager $appManager,
		private readonly OpenCatalogiWooCase $mapper,
		private readonly OpenCatalogiWooTerm $term,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Import every stored request that has not moved yet.
	 *
	 * @param bool $dryRun True to count only: nothing is written.
	 *
	 * @return array{installed: bool, imported: int, alreadyImported: int, failed: list<array{requestId: string, reference: string, reason: string}>, unmigrated: int, migrated: list<array{requestId: string, reference: string, caseId: string, termTimer: string}>}
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-every-stored-opencatalogi-request-is-imported-exactly-once-req-wto-004
	 */
	public function run(bool $dryRun = false): array {
		$answer = [
			'installed' => false,
			'imported' => 0,
			'alreadyImported' => 0,
			'failed' => [],
			'unmigrated' => 0,
			'migrated' => [],
		];

		if ($this->appManager->isInstalled(self::SOURCE_APP) === false) {
			return $answer;
		}

		$answer['installed'] = true;
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$answer['failed'][] = ['requestId' => '', 'reference' => '', 'reason' => 'OpenRegister is not available.'];
			return $answer;
		}

		// The import runs from occ, with no user session: every read and write
		// below is the system's, and its inputs are opencatalogi's stored rows.
		return (array)$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): array => $this->importAll(objectService: $objectService, answer: $answer, dryRun: $dryRun)
		);
	}//end run()

	/**
	 * The run itself, as the system.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $answer        The answer so far.
	 * @param bool                 $dryRun        Count only.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function importAll(object $objectService, array $answer, bool $dryRun): array {
		try {
			$sources = $this->listAll(objectService: $objectService, register: self::SOURCE_REGISTER, schema: self::SOURCE_SCHEMA, filters: []);
		} catch (Throwable $e) {
			$this->logger->error('OpenCatalogiWooImport: the Woo requests could not be read', ['error' => $e->getMessage()]);
			$answer['failed'][] = ['requestId' => '', 'reference' => '', 'reason' => 'opencatalogi\'s Woo requests could not be read.'];
			return $answer;
		}

		$cases = null;
		foreach ($sources as $source) {
			$sourceId = $this->idOf(row: $source);
			if (trim((string)($source['migratedTo'] ?? '')) !== '') {
				$answer['alreadyImported']++;
				continue;
			}

			if ($dryRun === true || $sourceId === '') {
				$answer['unmigrated']++;
				continue;
			}

			// Read once, and only when there is something to import: a second
			// run over a fully migrated register reads no case at all.
			$cases = ($cases ?? $this->casesBySource(objectService: $objectService));
			$outcome = $this->importOne(objectService: $objectService, source: $source, sourceId: $sourceId, existing: ($cases[$sourceId] ?? ''));
			if (isset($outcome['reason']) === true) {
				$answer['failed'][] = $outcome;
				$answer['unmigrated']++;
				continue;
			}

			$answer['imported']++;
			$answer['migrated'][] = $outcome;
		}//end foreach

		return $answer;
	}//end importAll()

	/**
	 * Move one request: find or write its case, carry its term, then stamp it.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $source        The source row.
	 * @param string               $sourceId      Its uuid.
	 * @param string               $existing      The case a previous run wrote for it, or ''.
	 *
	 * @return array<string, string> The migrated row, or a failed row carrying `reason`.
	 */
	private function importOne(object $objectService, array $source, string $sourceId, string $existing): array {
		$reference = (string)($source['reference'] ?? '');
		$failed = static fn (string $reason): array => ['requestId' => $sourceId, 'reference' => $reference, 'reason' => $reason];

		try {
			$mapped = $this->mapper->fromSource(source: $source, sourceUuid: $sourceId);
		} catch (WooRequestRefused $e) {
			return $failed($e->getMessage());
		}

		$caseId = $existing;
		if ($caseId === '') {
			$caseId = $this->writeCase(objectService: $objectService, mapped: $mapped);
			if ($caseId === '') {
				return $failed('The case could not be written.');
			}
		}

		$carried = $this->term->carry(caseId: $caseId, mapped: $mapped);
		if ($mapped['open'] === true && in_array($carried['outcome'], self::ARMED, true) === false) {
			return $failed('The case ' . $caseId . ' was written, but its term could not be armed (' . $carried['outcome'] . ').');
		}

		if ($this->stamp(objectService: $objectService, sourceId: $sourceId, caseId: $caseId) === false) {
			return $failed('opencatalogi did not keep the stamp, so the request is not counted as moved.');
		}

		return [
			'requestId' => $sourceId,
			'reference' => $reference,
			'caseId' => $caseId,
			'termTimer' => (string)($source['termTimer'] ?? ''),
		];
	}//end importOne()

	/**
	 * Write the case, and the Ingetrokken result of a withdrawn request.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $mapped        What the mapper answered.
	 *
	 * @return string The case uuid, or '' when it was not written.
	 */
	private function writeCase(object $objectService, array $mapped): string {
		$register = (string)$this->settingsService->getConfigValue('register');
		try {
			$saved = $objectService->saveObject(
				object: $mapped['case'],
				register: $register,
				schema: $this->settingsService->getConfigValue('case_schema'),
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->error('OpenCatalogiWooImport: the case could not be written', ['error' => $e->getMessage()]);
			return '';
		}

		$caseId = (new WooWrittenCase())->read(saved: $saved)['id'];
		if ($caseId !== '' && (string)$mapped['result'] !== '') {
			$this->writeResult(objectService: $objectService, register: $register, caseId: $caseId, resultType: (string)$mapped['result']);
		}

		return $caseId;
	}//end writeCase()

	/**
	 * The result a closed request ended with. A refusal is logged; the case stands.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 * @param string $caseId        The case.
	 * @param string $resultType    The result type uuid.
	 *
	 * @return void
	 */
	private function writeResult(object $objectService, string $register, string $caseId, string $resultType): void {
		try {
			$objectService->saveObject(
				object: ['case' => $caseId, 'resultType' => $resultType],
				register: $register,
				schema: $this->settingsService->getConfigValue('result_schema'),
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning('OpenCatalogiWooImport: the result of a withdrawn request could not be written', ['case' => $caseId, 'error' => $e->getMessage()]);
		}
	}//end writeResult()

	/**
	 * Stamp the source, and read the stamp back: a dropped key is a refusal.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $sourceId      The source uuid.
	 * @param string $caseId        The case it moved to.
	 *
	 * @return bool True when opencatalogi kept the stamp.
	 */
	private function stamp(object $objectService, string $sourceId, string $caseId): bool {
		try {
			$stored = $this->patchObjectAsArray(
				objectService: $objectService,
				register: self::SOURCE_REGISTER,
				schema: self::SOURCE_SCHEMA,
				id: $sourceId,
				changes: [
					'migratedTo' => $caseId,
					'migratedAt' => $this->time->getDateTime()->format(DATE_ATOM),
				]
			);
		} catch (Throwable $e) {
			$this->logger->error('OpenCatalogiWooImport: the stamp was refused', ['request' => $sourceId, 'error' => $e->getMessage()]);
			return false;
		}

		return (string)($stored['migratedTo'] ?? '') === $caseId;
	}//end stamp()

	/**
	 * The Woo cases an earlier run wrote, keyed by the source uuid they came from.
	 *
	 * Filtered in PHP: OpenRegister has no filter on a nested JSON key of a
	 * magic table, so `wooRequest.originReference` cannot be asked for.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 *
	 * @return array<string, string> Source uuid to case uuid.
	 */
	private function casesBySource(object $objectService): array {
		try {
			$cases = $this->listAll(
				objectService: $objectService,
				register: (string)$this->settingsService->getConfigValue('register'),
				schema: (string)$this->settingsService->getConfigValue('case_schema'),
				filters: ['caseType' => WooRequestIntake::CASE_TYPE_ID]
			);
		} catch (Throwable $e) {
			$this->logger->warning('OpenCatalogiWooImport: the existing Woo cases could not be read', ['error' => $e->getMessage()]);
			return [];
		}

		$bySource = [];
		foreach ($cases as $case) {
			$request = (array)($case['wooRequest'] ?? []);
			$reference = (string)($request['originReference'] ?? '');
			if (($request['origin'] ?? '') === OpenCatalogiWooCase::APPLICATION && $reference !== '') {
				$bySource[$reference] = $this->idOf(row: $case);
			}
		}

		return $bySource;
	}//end casesBySource()

	/**
	 * Every row of one schema, page by page.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param string               $register      Register id or slug.
	 * @param string               $schema        Schema id or slug.
	 * @param array<string, mixed> $filters       Equality filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function listAll(object $objectService, string $register, string $schema, array $filters): array {
		$rows = [];
		$seen = [];
		for ($offset = 0;; $offset += self::PAGE) {
			$page = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: $filters + ['_limit' => self::PAGE, '_offset' => $offset]
			);
			foreach ($page as $row) {
				$id = $this->idOf(row: $row);
				if ($id !== '' && isset($seen[$id]) === true) {
					// A store that ignores the offset answers the same page again.
					return $rows;
				}

				$seen[$id] = true;
				$rows[] = $row;
			}

			if (count($page) < self::PAGE) {
				return $rows;
			}
		}//end for
	}//end listAll()

	/**
	 * A row's uuid, wherever the store put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or ''.
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['@self']['id'] ?? ($row['uuid'] ?? '')));
	}//end idOf()
}//end class
