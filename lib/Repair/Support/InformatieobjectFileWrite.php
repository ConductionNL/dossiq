<?php

/**
 * Dossiq informatieobject file write.
 *
 * What the informatieobject back-fill writes for one existing dossier file:
 * the informatieobject itself (defaults, the owner as author, a SHA-256 of
 * the content), and its link to the case when a join schema is configured.
 * Split out of {@see \OCA\Dossiq\Repair\BackfillInformatieobjectMetadata},
 * which keeps walking the storage folders and deciding which files to write.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T09
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Support;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Repair\InformatieobjectTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\Files\File;

/**
 * Writes the informatieobject, and its case link, for one existing file.
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T09
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class InformatieobjectFileWrite {

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Reads the join schema.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
	) {
	}//end __construct()

	/**
	 * Create an informatieobject (+ join when possible) for one existing file.
	 *
	 * @param InformatieobjectTarget  $target The object service, register and informatieobject schema.
	 * @param array{0: string, 1: File} $stored The storing folder UUID (used as the link key), and the Nextcloud file node.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function write(InformatieobjectTarget $target, array $stored): void {
		[$folderUuid, $file] = $stored;
		$informatieobject = $this->informatieobjectFor(file: $file);

		$saved = $target->objectService->saveObject(
			object: $informatieobject,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $target->register, schema: $target->schema))
		);
		$infoId = $this->savedUuid(saved: $saved);

		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');
		if ($joinSchema !== '' && $infoId !== '') {
			$target->objectService->saveObject(
				object: $this->joinRecord(folderUuid: $folderUuid, infoId: $infoId),
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $target->register, schema: $joinSchema))
			);
		}
	}//end write()

	/**
	 * The informatieobject to register for one existing file, integrity hash included.
	 *
	 * @param File $file The Nextcloud file node.
	 *
	 * @return array<string, mixed> The informatieobject.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function joinRecord(string $folderUuid, string $infoId): array {
		return [
			'case' => $folderUuid,
			'informatieobject' => $infoId,
			'natureRelationshipDisplay' => 'Hoort at omgekeerd',
			'registrationDate' => date('Y-m-d\TH:i:s\Z'),
		];
	}//end joinRecord()
}//end class
