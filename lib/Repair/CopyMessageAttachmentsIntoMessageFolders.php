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

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copy the file of every attachment a portal message names into that message's folder.
 *
 * Portaliq serves a message's files from the message object's own folder
 * (`filesDownload`) and uploads a reply's files there (design D-2). Messages
 * written before that keep their attachments as a list of ids in
 * `attachments`: a document record uuid, or a bare Nextcloud file id. This
 * step resolves each id to its file and copies it into the message's folder,
 * so the resident sees the same attachments in the portal as before.
 *
 * It copies, never moves: the file stays where the case's dossier holds it.
 * It runs once per version behind a persisted key (ADR-106), skips a file
 * already in the message folder, and names an attachment whose file cannot
 * be found rather than inventing one. Re-running it copies nothing.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class CopyMessageAttachmentsIntoMessageFolders implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * The app-config key that records the step has run.
	 */
	public const DONE_KEY = 'portal_message_attachments_copied';

	/**
	 * The value the key holds once the step ran; bump it to run the step again.
	 */
	public const DONE_VERSION = '1';

	/**
	 * How many messages one page holds.
	 */
	private const PAGE_SIZE = 200;

	/**
	 * @param SettingsService $settingsService OpenRegister access and the configured register.
	 * @param DocumentRecordStore $store Document records, to resolve a record id to its file.
	 * @param DocumentProjectionService $projection Object folders.
	 * @param IRootFolder $rootFolder Files by id.
	 * @param IAppConfig $appConfig The version gate.
	 * @param LoggerInterface $logger Where each attachment without a file is named.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly DocumentRecordStore $store,
		private readonly DocumentProjectionService $projection,
		private readonly IRootFolder $rootFolder,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name the upgrade log prints.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function getName(): string {
		return 'Copy the attachments of every Dossiq portal message into the message folder';
	}//end getName()

	/**
	 * Copy the files, once.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(Application::APP_ID, self::DONE_KEY, '') === self::DONE_VERSION) {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('Dossiq portal messages: OpenRegister unavailable; attachments stay where they are.');
			return;
		}

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->copyAll(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * Walk every message and copy its attachments.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 */
	private function copyAll(object $objectService, IOutput $output): void {
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('portaal_bericht_schema');
		if ($register === '' || $schema === '') {
			$output->info('Dossiq portal messages: message schema not configured; attachments stay where they are.');
			return;
		}

		$tally = ['copied' => 0, 'skipped' => 0, 'missing' => 0];
		$page = 1;
		$full = true;
		while ($full === true) {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::PAGE_SIZE, '_page' => $page],
			);
			$full = (count($rows) === self::PAGE_SIZE);
			foreach ($rows as $row) {
				foreach ($this->copyMessage(row: $row) as $outcome) {
					$tally[$outcome]++;
				}
			}

			$page++;
		}

		$this->appConfig->setValueString(Application::APP_ID, self::DONE_KEY, self::DONE_VERSION);
		$output->info(
			'Dossiq portal messages: copied ' . $tally['copied'] . ' file(s) into their message, left '
			. $tally['skipped'] . ' already there, ' . $tally['missing'] . ' named in the log.'
		);
	}//end copyAll()

	/**
	 * Copy one message's attachments into its folder.
	 *
	 * @param array<string, mixed> $row The message row.
	 *
	 * @return array<int, string> One outcome per attachment: copied, skipped or missing.
	 */
	private function copyMessage(array $row): array {
		$messageId = trim((string)($row['id'] ?? (($row['@self'] ?? [])['id'] ?? '')));
		$ids = array_values(
			array_filter(
				array_map(static fn ($id): string => trim((string)$id), (array)($row['attachments'] ?? [])),
				static fn (string $id): bool => $id !== ''
			)
		);
		if ($messageId === '' || $ids === []) {
			return [];
		}

		$folder = $this->projection->folderOf(objectId: $messageId);
		if ($folder === null) {
			$this->logger->warning(
				'Dossiq portal messages: ' . $messageId . ' has no folder; its ' . count($ids) . ' attachment(s) were not copied',
				['app' => Application::APP_ID],
			);

			return array_fill(0, count($ids), 'missing');
		}

		$outcomes = [];
		foreach ($ids as $id) {
			$outcomes[] = $this->copyOne(messageId: $messageId, folder: $folder, attachmentId: $id);
		}

		return $outcomes;
	}//end copyMessage()

	/**
	 * Copy one attachment's file into the message folder, unless it is there.
	 *
	 * @param string $messageId The message uuid.
	 * @param Folder $folder The message's folder.
	 * @param string $attachmentId A document record uuid or a Nextcloud file id.
	 *
	 * @return string copied, skipped or missing.
	 */
	private function copyOne(string $messageId, Folder $folder, string $attachmentId): string {
		$file = $this->fileOf(attachmentId: $attachmentId);
		if ($file === null) {
			$this->logger->warning(
				'Dossiq portal messages: attachment ' . $attachmentId . ' of ' . $messageId . ' has no file; it was not copied',
				['app' => Application::APP_ID],
			);

			return 'missing';
		}

		$name = $file->getName();
		if ($folder->nodeExists($name) === true) {
			return 'skipped';
		}

		try {
			$file->copy($folder->getPath() . '/' . $name);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq portal messages: ' . $name . ' could not be copied into ' . $messageId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e],
			);

			return 'missing';
		}

		return 'copied';
	}//end copyOne()

	/**
	 * The file an attachment id names: a bare file id, or a record's file.
	 *
	 * @param string $attachmentId The id as the message stores it.
	 *
	 * @return File|null The file, null when nothing answers.
	 */
	private function fileOf(string $attachmentId): ?File {
		$fileId = 0;
		if (ctype_digit($attachmentId) === true) {
			$fileId = (int)$attachmentId;
		}

		if ($fileId === 0) {
			try {
				$record = $this->store->findRecord(recordId: $attachmentId);
			} catch (Throwable) {
				$record = null;
			}

			$fileId = (int)(($record ?? [])['fileId'] ?? 0);
		}

		if ($fileId <= 0) {
			return null;
		}

		foreach ($this->rootFolder->getById($fileId) as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}

		return null;
	}//end fileOf()
}//end class
