<?php

/**
 * Dossiq Backfill Informatieobject Metadata Repair Step
 *
 * Idempotent migration that converts existing dossier files into ZGW DRC
 * `informatieobject` register objects. It iterates the document storage
 * folders, and for any file that does not yet have an informatieobject it
 * creates one with sensible defaults (`status` = concept,
 * `vertrouwelijkheidaanduiding` = intern, `auteur` = file owner display name,
 * `integriteit.waarde` = SHA-256 of the file content) plus a
 * `zaakinformatieobject` join when a linking case can be determined. Files
 * that already have an informatieobject (matched by `bestandsnaam`) are
 * skipped, so a re-run is a no-op.
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
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T09
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Repair\Support\InformatieobjectFileWrite;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that back-fills informatieobject metadata for existing files.
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T09
 */
class BackfillInformatieobjectMetadata implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * Document storage base path (mirrors ZgwDocumentService::STORAGE_BASE).
	 */
	private const STORAGE_BASE = 'dossiq/documenten';

	/**
	 * Writes the informatieobject, and its link to the case, for one file.
	 *
	 * @var InformatieobjectFileWrite
	 */
	private readonly InformatieobjectFileWrite $fileWrite;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Settings service (config + ObjectService).
	 * @param IRootFolder $rootFolder Nextcloud root folder.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
		$this->fileWrite = new InformatieobjectFileWrite(settingsService: $settingsService);
	}//end __construct()

	/**
	 * Get the repair-step display name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T09
	 */
	public function getName(): string {
		return 'Back-fill ZGW informatieobject metadata for existing Dossiq dossier files';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T09
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('Dossiq backfill: OpenRegister unavailable; skipping.');
			return;
		}

		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses `create` for 'Anonymous'. Without it this backfill writes
		// nothing and says so only in a warning, which does not fail an upgrade.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->runInner(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * The backfill itself.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T09
	 */
	private function runInner(object $objectService, IOutput $output): void {

		$target = $this->configuredTarget(output: $output);
		if ($target === null) {
			return;
		}

		$folder = $this->resolveStorageFolder();
		if ($folder === null) {
			$output->info('Dossiq backfill: storage folder absent; nothing to back-fill.');
			return;
		}

		[$register, $infoSchema] = $target;
		[$created, $skipped] = $this->backfillEveryFolder(
			target: new InformatieobjectTarget(objectService: $objectService, register: $register, schema: $infoSchema),
			folder: $folder
		);

		$output->info('Dossiq backfill: created ' . $created . ' informatieobject(en), skipped ' . $skipped . ' existing.');
	}//end runInner()

	/**
	 * The register and informatieobject schema, when both are configured.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return array{0: string, 1: string}|null The register and schema, or null when not configured.
	 */
	private function configuredTarget(IOutput $output): ?array {
		$register = $this->settingsService->getConfigValue('register');
		$infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
		if ($register === '' || $infoSchema === '') {
			$output->info('Dossiq backfill: dossier schemas not configured; skipping.');
			return null;
		}

		return [$register, $infoSchema];
	}//end configuredTarget()

	/**
	 * Back-fill every case folder under the storage folder.
	 *
	 * @param InformatieobjectTarget $target The object service, register and informatieobject schema.
	 * @param Folder                 $folder The storage folder.
	 *
	 * @return array{0: int, 1: int} The number created and the number skipped.
	 */
	private function backfillEveryFolder(InformatieobjectTarget $target, Folder $folder): array {
		$existing = $this->existingFilenames(target: $target);

		$created = 0;
		$skipped = 0;
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder === false) {
				continue;
			}

			$result = $this->backfillFolderNode(target: $target, folder: ['node' => $node, 'existing' => $existing]);
			$created += $result['created'];
			$skipped += $result['skipped'];
			$existing = $result['existing'];
		}//end foreach

		return [$created, $skipped];
	}//end backfillEveryFolder()

	/**
	 * Back-fill every not-yet-registered file inside one case folder.
	 *
	 * Files whose name is already registered are counted as skipped; `_part_` upload fragments are
	 * ignored entirely. A per-file failure is logged and does not abort the folder.
	 *
	 * @param InformatieobjectTarget                          $target The object service, register and informatieobject schema.
	 * @param array{node: Folder, existing: array<int, string>} $folder The case folder to walk, and the filenames already registered.
	 *
	 * @return array{created: int, skipped: int, existing: array<int, string>} Counts plus the grown filename list.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function backfillFolderNode(InformatieobjectTarget $target, array $folder): array {
		['node' => $node, 'existing' => $existing] = $folder;
		$created = 0;
		$skipped = 0;

		foreach ($node->getDirectoryListing() as $fileNode) {
			$file = $this->backfillableFile(node: $fileNode);
			if ($file === null) {
				continue;
			}

			$fileName = $file->getName();

			if (in_array($fileName, $existing, true) === true) {
				$skipped++;
				continue;
			}

			try {
				$this->fileWrite->write(target: $target, stored: [$node->getName(), $file]);
				$existing[] = $fileName;
				$created++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Dossiq backfill: failed for ' . $fileName . ': ' . $e->getMessage(),
					['app' => AppIdentity::APP_ID],
				);
			}
		}//end foreach

		return ['created' => $created, 'skipped' => $skipped, 'existing' => $existing];
	}//end backfillFolderNode()

	/**
	 * The node as a file to back-fill, or null for a folder or an upload fragment.
	 *
	 * @param mixed $node An entry of a case folder's directory listing.
	 *
	 * @return File|null The file, or null when it is not back-filled.
	 */
	private function backfillableFile(mixed $node): ?File {
		if ($node instanceof File === false) {
			return null;
		}

		if (str_starts_with($node->getName(), '_part_') === true) {
			return null;
		}

		return $node;
	}//end backfillableFile()

	/**
	 * Collect the bestandsnaam of every existing informatieobject for idempotency.
	 *
	 * @param InformatieobjectTarget $target The object service, register and informatieobject schema.
	 *
	 * @return string[] Filenames already represented by an informatieobject.
	 */
	private function existingFilenames(InformatieobjectTarget $target): array {
		$rows = $this->searchObjectsAsArrays(
			objectService: $target->objectService,
			register: $target->register,
			schema: $target->schema,
			filters: ['_limit' => 10000],
		);

		$names = [];
		foreach ($rows as $row) {
			$name = (string)($row['fileName'] ?? '');
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end existingFilenames()

	/**
	 * Resolve the document storage folder, or null when it does not exist.
	 *
	 * @return Folder|null
	 */
	private function resolveStorageFolder(): ?Folder {
		try {
			$userFolder = $this->rootFolder->getUserFolder(userId: 'admin');
			if ($userFolder->nodeExists(path: self::STORAGE_BASE) === false) {
				return null;
			}

			$node = $userFolder->get(path: self::STORAGE_BASE);
			if ($node instanceof Folder === true) {
				return $node;
			}
		} catch (NotFoundException $e) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('Dossiq backfill: cannot resolve storage folder: ' . $e->getMessage());
			return null;
		}

		return null;
	}//end resolveStorageFolder()
}//end class
