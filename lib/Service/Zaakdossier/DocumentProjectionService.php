<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\AppInfo\Application;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Keeps a case's ZGW document records in step with the files in its folder.
 *
 * A document is a normal file in the case's folder first and an
 * `informatieobject` record second: the record is created from the file with
 * derived defaults, refreshed when the file is written again, renamed with
 * it, retired when it is deleted, and moved along when the file moves to
 * another case. The one invariant is "one record per file id": every path in
 * here looks the record up by `fileId` before it writes, which is what makes
 * chunked uploads, a NodeWritten after a NodeCreated and a version restore
 * refresh the record instead of duplicating it.
 *
 * The ZGW API creates records before any case is known; `homeDocument()`
 * moves such a record's file into the case's folder on its first join.
 *
 * @spec openspec/specs/document-projection/spec.md
 */
class DocumentProjectionService {

	/**
	 * The folder OpenRegister keeps every register under, in every user's files.
	 */
	public const REGISTER_ROOT = 'Open Registers';

	/**
	 * @param DocumentRecordStore $store Cases, records, joins and types in OpenRegister.
	 * @param DocumentDefaults $defaults What a new file's record says, and what a write refreshes.
	 * @param LoggerInterface $logger Where a move or a skipped step is reported.
	 */
	public function __construct(
		private readonly DocumentRecordStore $store,
		private readonly DocumentDefaults $defaults,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a node path lies under the register tree at all.
	 *
	 * The cheap gate every listener call passes first, so the vast majority
	 * of Nextcloud writes cost one string search and no database read.
	 *
	 * @param string $path The node's path as Nextcloud reports it.
	 *
	 * @return bool True under `Open Registers/`.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function isUnderRegisterTree(string $path): bool {
		return str_contains($path, '/' . self::REGISTER_ROOT . '/');
	}//end isUnderRegisterTree()

	/**
	 * Make the record for a file in a case folder, or refresh the one it has.
	 *
	 * @param File $node The file that was created or written.
	 *
	 * @return array<string, mixed>|null The record as stored, or null when the file is not a case's document.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function projectNode(File $node): ?array {
		$case = $this->resolveCaseForNode(node: $node);
		if ($case === null) {
			return null;
		}

		$record = $this->store->findRecord(fileId: $node->getId());
		if ($record === null) {
			$record = $this->defaults->forNewFile(node: $node, case: $case);
		}

		if (isset($record['id']) === true) {
			$record = $this->refreshed(record: $record, node: $node);
		}

		$record['id'] = $this->store->saveRecord(record: $record);
		$this->store->ensureJoin(caseId: $this->idOf(row: $case), recordId: (string)$record['id']);

		return $record;
	}//end projectNode()

	/**
	 * The file is gone: retire its record and every join to it.
	 *
	 * A draft record is deleted with its file; a final one keeps answering on
	 * the DRC API as archived. An archived one is left alone.
	 *
	 * @param File $node The deleted file.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function retireNode(File $node): void {
		$record = $this->store->findRecord(fileId: $node->getId());
		if ($record === null) {
			return;
		}

		$this->retireRecord(record: $record);
	}//end retireNode()

	/**
	 * A renamed or moved file: follow it.
	 *
	 * Same folder: the record's file name follows, and its title too when the
	 * title was still the one derived from the old name. Another case's
	 * folder: the join moves with the file. Out of every case folder: the
	 * source case loses the document, and a record with no case left is
	 * retired as a delete would retire it.
	 *
	 * @param File $node The file at its new path.
	 * @param string $sourcePath The path it had before.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function rehomeNode(File $node, string $sourcePath): void {
		$record = $this->store->findRecord(fileId: $node->getId());
		if ($record === null) {
			$this->projectNode(node: $node);
			return;
		}

		if (dirname($sourcePath) === dirname($node->getPath())) {
			$this->renameRecord(record: $record, node: $node, previousName: basename($sourcePath));
			return;
		}

		$recordId = (string)$record['id'];
		$sourceCaseId = $this->caseIdFromPath(path: $sourcePath);
		$targetCase = $this->resolveCaseForNode(node: $node);
		$targetCaseId = '';
		if ($targetCase !== null) {
			$targetCaseId = $this->idOf(row: $targetCase);
		}

		if ($sourceCaseId !== '' && $sourceCaseId !== $targetCaseId) {
			$this->store->deleteJoins(recordId: $recordId, caseId: $sourceCaseId);
		}

		if ($targetCase !== null) {
			$this->projectNode(node: $node);
			return;
		}

		if ($this->store->joinsFor(recordId: $recordId) === []) {
			$this->retireRecord(record: $record);
		}
	}//end rehomeNode()

	/**
	 * Move an API-first document's file into the case's folder on its first join.
	 *
	 * A record whose file still sits in its own folder is moved; one whose
	 * file is already under a case folder is left where it is, so a second
	 * join never moves anything. The file id is re-read after the move and
	 * written back only when the storage changed it.
	 *
	 * @param string $recordId The informatieobject uuid.
	 * @param string $caseId The case uuid the join names.
	 *
	 * @return bool True when the file moved.
	 *
	 * @throws RuntimeException When the case has no folder.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function homeDocument(string $recordId, string $caseId): bool {
		$record = $this->store->findRecord(recordId: $recordId) ?? [];
		$fileName = (string)($record['fileName'] ?? '');
		if ($fileName === '') {
			return false;
		}

		$ownFolder = $this->store->folderOf(objectId: $recordId);
		if ($ownFolder === null || $ownFolder->nodeExists($fileName) === false) {
			// Already under a case folder, or never stored: nothing to move.
			return false;
		}

		$caseFolder = $this->store->folderOf(objectId: $caseId);
		if ($caseFolder === null) {
			throw new RuntimeException('Case ' . $caseId . ' has no folder to hold its documents');
		}

		$node = $ownFolder->get($fileName);
		$previousId = $node->getId();
		$moved = $node->move($caseFolder->getPath() . '/' . $fileName);
		$this->logger->info(
			'Dossiq documents: moved ' . $fileName . ' of ' . $recordId . ' into case ' . $caseId,
			['app' => Application::APP_ID],
		);

		if ($moved->getId() !== $previousId || (int)($record['fileId'] ?? 0) !== $moved->getId()) {
			$record['fileId'] = $moved->getId();
			$this->store->saveRecord(record: $record);
		}

		return true;
	}//end homeDocument()

	/**
	 * The case whose folder holds this node, or null.
	 *
	 * The path under `Open Registers/<register folder>/` starts with the
	 * object's uuid, which is how OpenRegister names object folders; that
	 * uuid is looked up as a case, so a document's own folder, another
	 * schema's object or a stray folder resolves to nothing.
	 *
	 * @param File $node The node.
	 *
	 * @return array<string, mixed>|null The case row.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function resolveCaseForNode(File $node): ?array {
		$caseId = $this->caseIdFromPath(path: $node->getPath());
		if ($caseId === '') {
			return null;
		}

		return $this->store->findCase(caseId: $caseId);
	}//end resolveCaseForNode()

	/**
	 * The uuid segment a register-tree path names, verified as a case.
	 *
	 * @param string $path A node path.
	 *
	 * @return string The case uuid, or '' when the path is not under a case folder.
	 */
	private function caseIdFromPath(string $path): string {
		$marker = '/' . self::REGISTER_ROOT . '/';
		$offset = strpos($path, $marker);
		if ($offset === false) {
			return '';
		}

		$segments = array_values(array_filter(explode('/', substr($path, ($offset + strlen($marker))))));
		// [register folder, object uuid, ...file or sub-folders]
		if (count($segments) < 3) {
			return '';
		}

		$candidate = $segments[1];
		if ($this->store->findCase(caseId: $candidate) === null) {
			return '';
		}

		return $candidate;
	}//end caseIdFromPath()

	/**
	 * The record after a second write: size, format and integrity follow the file.
	 *
	 * @param array<string, mixed> $record The stored record.
	 * @param File $node The file as written.
	 *
	 * @return array<string, mixed> The record to store.
	 */
	private function refreshed(array $record, File $node): array {
		$record['bestandsomvang'] = $node->getSize();
		$record['format'] = $node->getMimeType();
		$record['integrity'] = $this->defaults->integrityOf(node: $node);
		$record['fileId'] = $node->getId();

		return $record;
	}//end refreshed()

	/**
	 * Follow a rename: the file name, and the title when it was still derived.
	 *
	 * @param array<string, mixed> $record The stored record.
	 * @param File $node The file under its new name.
	 * @param string $previousName The file name before.
	 *
	 * @return void
	 */
	private function renameRecord(array $record, File $node, string $previousName): void {
		$record['fileName'] = $node->getName();
		if ((string)($record['title'] ?? '') === pathinfo($previousName, PATHINFO_FILENAME)) {
			$record['title'] = pathinfo($node->getName(), PATHINFO_FILENAME);
		}

		$this->store->saveRecord(record: $record);
	}//end renameRecord()

	/**
	 * Retire a record whose file is gone or left every case.
	 *
	 * @param array<string, mixed> $record The record row.
	 *
	 * @return void
	 */
	private function retireRecord(array $record): void {
		$recordId = (string)$record['id'];
		$this->store->deleteJoins(recordId: $recordId);

		$status = (string)($record['status'] ?? 'draft');
		if ($status === 'final') {
			$record['status'] = 'archived';
			$this->store->saveRecord(record: $record);
			return;
		}

		if ($status === 'draft') {
			$this->store->deleteRecord(recordId: $recordId);
		}
	}//end retireRecord()

	/**
	 * A row's uuid, from `id`, `uuid` or `@self.id`.
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
