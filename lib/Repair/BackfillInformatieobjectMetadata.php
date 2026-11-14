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
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Settings service (config + ObjectService).
	 * @param IRootFolder $rootFolder Nextcloud root folder.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
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

			$result = $this->backfillFolderNode(target: $target, node: $node, existing: $existing);
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
	 * @param InformatieobjectTarget $target   The object service, register and informatieobject schema.
	 * @param Folder                 $node     The case folder to walk.
	 * @param array<int, string>     $existing Filenames already registered.
	 *
	 * @return array{created: int, skipped: int, existing: array<int, string>} Counts plus the grown filename list.
	 */
	private function backfillFolderNode(InformatieobjectTarget $target, Folder $node, array $existing): array {
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
				$this->backfillFile(target: $target, folderUuid: $node->getName(), file: $file);
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
	 * Create an informatieobject (+ join when possible) for one existing file.
	 *
	 * @param InformatieobjectTarget $target     The object service, register and informatieobject schema.
	 * @param string                 $folderUuid The storing folder UUID (used as the link key).
	 * @param File                   $file       The Nextcloud file node.
	 *
	 * @return void
	 */
	private function backfillFile(InformatieobjectTarget $target, string $folderUuid, File $file): void {
		$informatieobject = $this->informatieobjectFor(file: $file);

		$saved = $target->objectService->saveObject(object: $informatieobject, register: $target->register, schema: $target->schema);
		$infoId = $this->savedUuid(saved: $saved);

		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');
		if ($joinSchema !== '' && $infoId !== '') {
			$target->objectService->saveObject(
				object: $this->joinRecord(folderUuid: $folderUuid, infoId: $infoId),
				register: $target->register,
				schema: $joinSchema,
			);
		}
	}//end backfillFile()

	/**
	 * The informatieobject to register for one existing file, integrity hash included.
	 *
	 * @param File $file The Nextcloud file node.
	 *
	 * @return array<string, mixed> The informatieobject.
	 */
	private function informatieobjectFor(File $file): array {
		$content = (string)$file->getContent();
		$author = $this->authorOf(file: $file);

		$informatieobject = $this->describedFile(file: $file, author: $author);
		$informatieobject['integrity'] = [
			'algorithm' => 'sha256',
			'value' => hash('sha256', $content),
			'date' => date('Y-m-d\TH:i:s'),
		];

		return $informatieobject;
	}//end informatieobjectFor()

	/**
	 * The display name of the file's owner, or an empty string without one.
	 *
	 * @param File $file The Nextcloud file node.
	 *
	 * @return string The author.
	 */
	private function authorOf(File $file): string {
		$owner = $file->getOwner();
		$author = '';
		if ($owner !== null) {
			$author = $owner->getDisplayName();
		}

		return $author;
	}//end authorOf()

	/**
	 * The descriptive informatieobject fields read off the file.
	 *
	 * @param File $file The Nextcloud file node.
	 * @param string $author The owner's display name.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function describedFile(File $file, string $author): array {
		return [
			'title' => $file->getName(),
			'fileName' => $file->getName(),
			'bestandsomvang' => $file->getSize(),
			'format' => $file->getMimeType(),
			'vertrouwelijkheidaanduiding' => 'intern',
			'auteur' => $author,
			'status' => 'draft',
			'informatieobjecttype' => '',
			'creatiedatum' => date('Y-m-d', $file->getMTime()),
			'taal' => 'nld',
			'fileId' => $file->getId(),
		];
	}//end describedFile()

	/**
	 * The UUID of a saved object, or an empty string when the save returned none.
	 *
	 * @param mixed $saved What the object service returned.
	 *
	 * @return string The UUID.
	 */
	private function savedUuid(mixed $saved): string {
		$infoId = '';
		if (is_object($saved) === true) {
			$infoId = $saved->getUuid();
		}

		return $infoId;
	}//end savedUuid()

	/**
	 * The join record that links a registered informatieobject to its case.
	 *
	 * @param string $folderUuid The storing folder UUID (used as the link key).
	 * @param string $infoId The informatieobject UUID.
	 *
	 * @return array<string, string> The join record.
	 */
	private function joinRecord(string $folderUuid, string $infoId): array {
		return [
			'case' => $folderUuid,
			'informatieobject' => $infoId,
			'natureRelationshipDisplay' => 'Hoort at omgekeerd',
			'registrationDate' => date('Y-m-d\TH:i:s\Z'),
		];
	}//end joinRecord()

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
