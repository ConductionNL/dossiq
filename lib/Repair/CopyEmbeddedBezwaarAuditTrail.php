<?php

/**
 * Copy the embedded bezwaar audit arrays onto OpenRegister's audit trail.
 *
 * Before bezwaar-audit-onto-openregister-trail, each Awb and AVG entry of a
 * `hearingSession` or `bacAdviceRequest` was appended to an `auditTrail` array
 * on the record itself. Now each entry is a row on OpenRegister's audit trail
 * of the record, and that trail is what the History tab reads. So the entries
 * already in the arrays are copied across once, in order, or they would be
 * missing from the screen.
 *
 * The arrays are not changed and not deleted: they are the original record.
 * OpenRegister stamps a row with the time it is written and takes no time
 * argument, so a copied row carries the copy's time as its own, and the
 * original `at`, `actor`, `tag` and `payload` in its context under the keys a
 * live entry uses, plus `migratedFrom: auditTrail` and `migratedIndex`. Its
 * actor is `system`, because the system wrote it.
 *
 * Idempotent: an index already copied is skipped, so a second run writes
 * nothing; it reads what it already copied with OpenRegister's
 * `AuditTrailMapper::findAll()`. A record whose entries cannot be written is
 * reported by uuid and the run goes on with the next one.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\Contracts\BezwaarAuditTrailInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Copies every embedded bezwaar audit entry onto its record's OpenRegister trail, once.
 *
 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
 */
class CopyEmbeddedBezwaarAuditTrail implements IRepairStep {

	use SearchesObjects;

	/**
	 * Rows read per page.
	 *
	 * @var int
	 */
	private const PAGE = 100;

	/**
	 * The two schemas that carried an embedded `auditTrail`, by config key.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMA_KEYS = ['hearing_session_schema', 'bac_advice_request_schema'];

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface    $settingsService Register and schema configuration, and the ObjectService.
	 * @param BezwaarAuditTrailInterface  $auditTrail      Writes the copied rows.
	 * @param ContainerInterface $container       Resolves OpenRegister's AuditTrailMapper, to read what was already copied.
	 * @param IAppManager        $appManager      OpenRegister availability check.
	 * @param LoggerInterface    $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly BezwaarAuditTrailInterface $auditTrail,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function getName(): string {
		return 'Copy the embedded bezwaar audit entries onto OpenRegister\'s audit trail';
	}//end getName()

	/**
	 * Copy every hearingSession and bacAdviceRequest array.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		if ($objectService === null || $register === '') {
			$output->info('OpenRegister or the dossiq register is unavailable, so no bezwaar audit entries are copied.');
			return;
		}

		foreach (self::SCHEMA_KEYS as $key) {
			$schema = $this->settingsService->getConfigValue(key: $key);
			if ($schema === '') {
				continue;
			}

			$this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: function () use ($objectService, $register, $schema, $output): void {
					$this->copySchema(
						objectService: $objectService,
						copy: new BezwaarAuditCopy(register: $register, schema: $schema, output: $output),
					);
				}
			);
		}
	}//end run()

	/**
	 * Copy the arrays of every record of one schema, page by page.
	 *
	 * @param object           $objectService OpenRegister's ObjectService.
	 * @param BezwaarAuditCopy $copy          The register, the schema and the repair output.
	 *
	 * @return void
	 */
	private function copySchema(object $objectService, BezwaarAuditCopy $copy): void {
		$copied = 0;
		$failed = [];
		$offset = 0;
		do {
			$query = ['filters' => ['register' => $copy->register, 'schema' => $copy->schema], 'limit' => self::PAGE, 'offset' => $offset];
			$rows = (array)$objectService->findAll($query);
			[$pageCopied, $pageFailed] = $this->copyPage(rows: $rows, copy: $copy);
			$copied += $pageCopied;
			$failed = array_merge($failed, $pageFailed);

			$offset += self::PAGE;
			$more = (count($rows) === self::PAGE);
		} while ($more === true);

		$copy->output->info($copy->schema.': '.$copied.' embedded bezwaar audit entries copied, '.count($failed).' records not copied.');
	}//end copySchema()

	/**
	 * Copy the arrays of every record on one page.
	 *
	 * @param array<int, mixed> $rows The page of records.
	 * @param BezwaarAuditCopy  $copy The register, the schema and the repair output.
	 *
	 * @return array{0: int, 1: list<string>} How many entries were copied, and the records that could not be.
	 */
	private function copyPage(array $rows, BezwaarAuditCopy $copy): array {
		$copied = 0;
		$failed = [];
		foreach ($this->normaliseObjectRows(rows: $rows) as $row) {
			[$count, $failedUuid] = $this->copyRow(copy: $copy, row: $row);
			$copied += $count;
			if ($failedUuid !== null) {
				$failed[] = $failedUuid;
			}
		}

		return [$copied, $failed];
	}//end copyPage()

	/**
	 * Copy one record's array, reporting a record that could not be copied.
	 *
	 * @param BezwaarAuditCopy     $copy The register, the schema and the repair output.
	 * @param array<string, mixed> $row  The record.
	 *
	 * @return array{0: int, 1: string|null} How many entries were copied, and the record when it could not be.
	 */
	private function copyRow(BezwaarAuditCopy $copy, array $row): array {
		$uuid = (string)($row['@self']['id'] ?? ($row['id'] ?? ($row['uuid'] ?? '')));
		$entries = $row['auditTrail'] ?? [];
		if ($uuid === '' || is_array($entries) === false || $entries === []) {
			return [0, null];
		}

		try {
			return [$this->copyRecord(copy: $copy, uuid: $uuid, entries: array_values($entries)), null];
		} catch (Throwable $e) {
			$this->reportNotCopied(copy: $copy, uuid: $uuid, error: $e);
			return [0, $uuid];
		}
	}//end copyRow()

	/**
	 * Report a record whose entries could not be copied.
	 *
	 * @param BezwaarAuditCopy $copy  The schema and the repair output.
	 * @param string           $uuid  The record.
	 * @param Throwable        $error Why the copy failed.
	 *
	 * @return void
	 */
	private function reportNotCopied(BezwaarAuditCopy $copy, string $uuid, Throwable $error): void {
		$copy->output->warning('The bezwaar audit entries of '.$copy->schema.' '.$uuid.' could not be copied: '.$error->getMessage());
		$this->logger->error(
			'Dossiq: embedded bezwaar audit entries not copied',
			['schema' => $copy->schema, 'object' => $uuid, 'exception' => $error->getMessage()]
		);
	}//end reportNotCopied()

	/**
	 * Copy one record's entries that are not on its trail yet, in order.
	 *
	 * @param BezwaarAuditCopy                 $copy    The register and the schema.
	 * @param string                           $uuid    The record.
	 * @param array<int, array<string, mixed>> $entries The embedded entries, in array order.
	 *
	 * @return int How many entries were copied.
	 *
	 * @throws RuntimeException When an entry names no event or cannot be written.
	 */
	private function copyRecord(BezwaarAuditCopy $copy, string $uuid, array $entries): int {
		$done = $this->copiedIndexes(objectUuid: $uuid);
		$copied = 0;
		foreach ($entries as $index => $entry) {
			if (in_array($index, $done, true) === true) {
				continue;
			}

			$event = '';
			if (is_array($entry) === true) {
				$event = (string)($entry['event'] ?? '');
			}

			if ($event === '') {
				throw new RuntimeException('entry '.$index.' names no event');
			}

			$this->auditTrail->write(
				register: $copy->register,
				schema: $copy->schema,
				objectUuid: $uuid,
				action: BezwaarAuditTrailInterface::ACTION_PREFIX.$event,
				context: $entry + ['migratedFrom' => 'auditTrail', 'migratedIndex' => $index],
				actorId: 'system',
			);
			$copied++;
		}

		return $copied;
	}//end copyRecord()

	/**
	 * The `migratedIndex` of every entry already copied onto a record's trail.
	 *
	 * Reads the record's `dossiq.bezwaar.*` rows with OpenRegister's
	 * `AuditTrailMapper::findAll()`, so the copy can run twice and write
	 * nothing the second time. A read that fails throws: answering "nothing
	 * copied" would copy everything again.
	 *
	 * @param string $objectUuid The record.
	 *
	 * @return array<int, int> The copied indexes.
	 *
	 * @throws Throwable When the trail cannot be read.
	 */
	private function copiedIndexes(string $objectUuid): array {
		if ($this->appManager->isInstalled('openregister') === false) {
			throw new RuntimeException('OpenRegister is not available, so what was already copied cannot be read');
		}

		$rows = $this->container->get('OCA\\OpenRegister\\Db\\AuditTrailMapper')->findAll(
			filters: ['object_uuid' => $objectUuid, 'action' => BezwaarAuditTrailInterface::ACTION_PREFIX.'*']
		);

		$indexes = [];
		foreach ($rows as $row) {
			$context = (array)$row->getChanged();
			if (($context['migratedFrom'] ?? '') === 'auditTrail' && isset($context['migratedIndex']) === true) {
				$indexes[] = (int)$context['migratedIndex'];
			}
		}

		return $indexes;
	}//end copiedIndexes()
}//end class
