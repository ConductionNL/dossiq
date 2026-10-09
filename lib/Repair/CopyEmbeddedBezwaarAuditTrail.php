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
 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Container\ContainerInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Copies every embedded bezwaar audit entry onto its record's OpenRegister trail, once.
 *
 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
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
	 * @param SettingsService    $settingsService Register and schema configuration, and the ObjectService.
	 * @param BezwaarAuditTrail  $auditTrail      Writes the copied rows.
	 * @param ContainerInterface $container       Resolves OpenRegister's AuditTrailMapper, to read what was already copied.
	 * @param LoggerInterface    $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly BezwaarAuditTrail $auditTrail,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
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
	 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
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
					$this->copySchema(objectService: $objectService, register: $register, schema: $schema, output: $output);
				}
			);
		}
	}//end run()

	/**
	 * Copy the arrays of every record of one schema, page by page.
	 *
	 * @param object  $objectService OpenRegister's ObjectService.
	 * @param string  $register      The register.
	 * @param string  $schema        The schema.
	 * @param IOutput $output        Repair output.
	 *
	 * @return void
	 */
	private function copySchema(object $objectService, string $register, string $schema, IOutput $output): void {
		$copied = 0;
		$failed = [];
		$offset = 0;
		do {
			$rows = (array)$objectService->findAll(
				['filters' => ['register' => $register, 'schema' => $schema], 'limit' => self::PAGE, 'offset' => $offset]
			);
			foreach ($this->normaliseObjectRows(rows: $rows) as $row) {
				$uuid = (string)($row['@self']['id'] ?? ($row['id'] ?? ($row['uuid'] ?? '')));
				$entries = $row['auditTrail'] ?? [];
				if ($uuid === '' || is_array($entries) === false || $entries === []) {
					continue;
				}

				try {
					$copied += $this->copyRecord(register: $register, schema: $schema, uuid: $uuid, entries: array_values($entries));
				} catch (Throwable $e) {
					$failed[] = $uuid;
					$output->warning('The bezwaar audit entries of '.$schema.' '.$uuid.' could not be copied: '.$e->getMessage());
					$this->logger->error(
						'Dossiq: embedded bezwaar audit entries not copied',
						['schema' => $schema, 'object' => $uuid, 'exception' => $e->getMessage()]
					);
				}
			}

			$offset += self::PAGE;
			$more = (count($rows) === self::PAGE);
		} while ($more === true);

		$output->info($schema.': '.$copied.' embedded bezwaar audit entries copied, '.count($failed).' records not copied.');
	}//end copySchema()

	/**
	 * Copy one record's entries that are not on its trail yet, in order.
	 *
	 * @param string                           $register The register.
	 * @param string                           $schema   The schema.
	 * @param string                           $uuid     The record.
	 * @param array<int, array<string, mixed>> $entries  The embedded entries, in array order.
	 *
	 * @return int How many entries were copied.
	 *
	 * @throws RuntimeException When an entry names no event or cannot be written.
	 */
	private function copyRecord(string $register, string $schema, string $uuid, array $entries): int {
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
				register: $register,
				schema: $schema,
				objectUuid: $uuid,
				action: BezwaarAuditTrail::ACTION_PREFIX.$event,
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
		$rows = $this->container->get('OCA\\OpenRegister\\Db\\AuditTrailMapper')->findAll(
			filters: ['object_uuid' => $objectUuid, 'action' => BezwaarAuditTrail::ACTION_PREFIX.'*']
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
