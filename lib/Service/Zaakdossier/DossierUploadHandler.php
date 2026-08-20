<?php

/**
 * Procest DossierUploadHandler.
 *
 * Upload collaborator for the ZGW DRC case dossier. Split out of
 * ZaakdossierController so that controller keeps only endpoint shape: decoding
 * the shared metadata body, normalising PHP's two uploaded-file shapes into one
 * flat list, rejecting executable uploads, and storing a single file all live
 * here (ADR-022).
 *
 * The executable check is a security control, not a convenience: it screens
 * both the extension and the leading magic bytes, so renaming an .exe does not
 * get it past the gate.
 *
 * @category Service
 * @package  OCA\Procest\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Zaakdossier;

use OCA\Procest\Service\CaseAccessGuard;
use OCA\Procest\Service\ZaakdossierService;
use OCP\IUser;
use RuntimeException;

/**
 * Decodes, screens and stores dossier document uploads.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 */
class DossierUploadHandler {
	/**
	 * File extensions rejected outright as executable content.
	 *
	 * @var array<int, string>
	 */
	private const BLOCKED_EXTENSIONS = ['exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'sh', 'php', 'phar', 'dll'];

	/**
	 * Constructor.
	 *
	 * @param ZaakdossierService $fileService The dossier orchestrator.
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ZaakdossierService $fileService,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
	}//end __construct()

	/**
	 * Whether the given user may add documents to the given case.
	 *
	 * The case-side authorization for uploads lives here rather than in
	 * `ZaakdossierController` because this class is the collaborator that
	 * performs the write, and because the controller's document-side guard
	 * (`InformatieobjectReader::guardReadable()`) has no document to check on
	 * an upload — the missing half was always the CASE.
	 *
	 * Called once per request, not once per file: the answer cannot differ
	 * between files of the same upload.
	 *
	 * @param IUser $user The authenticated user.
	 * @param string $caseId The case (zaak) UUID.
	 *
	 * @return bool True when the user handles the case.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function hasCaseUploadAccess(IUser $user, string $caseId): bool {
		return $this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user);
	}//end hasCaseUploadAccess()

	/**
	 * Upload a single multipart file into a case dossier.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 * @param array<string, mixed> $file One normalised uploaded-file entry.
	 * @param array<string, mixed> $metadata The shared document metadata.
	 *
	 * @return array<string, mixed> The per-file result entry.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function uploadOne(string $caseId, array $file, array $metadata): array {
		$name = (string)($file['name'] ?? '');
		$tmpName = (string)($file['tmp_name'] ?? '');
		try {
			if ($this->isExecutable(name: $name, tmpName: $tmpName) === true) {
				throw new RuntimeException('Executable files are not permitted: ' . $name);
			}

			$content = '';
			if ($tmpName !== '') {
				$content = (string)file_get_contents($tmpName);
			}

			$meta = $metadata;
			if (isset($file['type']) === true && $file['type'] !== '') {
				$meta['format'] = $file['type'];
			}

			$created = $this->fileService->uploadDocument(
				caseId: $caseId,
				fileName: $name,
				content: $content,
				metadata: $meta,
			);

			return ['name' => $name, 'success' => true, 'informatieobject' => $created];
		} catch (\Throwable $e) {
			return ['name' => $name, 'success' => false, 'error' => $e->getMessage()];
		}//end try
	}//end uploadOne()

	/**
	 * Decode the shared metadata JSON body into an array.
	 *
	 * @param mixed $raw The raw metadata param (JSON string or array).
	 *
	 * @return array<string, mixed> The decoded metadata.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function decodeMetadata(mixed $raw): array {
		if (is_array($raw) === true) {
			return $raw;
		}

		if (is_string($raw) === true && $raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}
		}

		return [];
	}//end decodeMetadata()

	/**
	 * Normalise the PHP uploaded-file structure into a flat list of files.
	 *
	 * @param mixed $uploaded The value returned by IRequest::getUploadedFile().
	 *
	 * @return array<int, array<string, mixed>> A list of single-file arrays.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function normaliseUploadedFiles(mixed $uploaded): array {
		if (is_array($uploaded) === false || isset($uploaded['name']) === false) {
			return [];
		}

		// Single-file shape: ['name' => 'x', 'tmp_name' => '/tmp/...'].
		if (is_array($uploaded['name']) === false) {
			return [$uploaded];
		}

		// Multi-file shape: ['name' => [...], 'tmp_name' => [...], ...].
		$files = [];
		foreach (array_keys($uploaded['name']) as $index) {
			$files[] = [
				'name' => ($uploaded['name'][$index] ?? ''),
				'type' => ($uploaded['type'][$index] ?? ''),
				'tmp_name' => ($uploaded['tmp_name'][$index] ?? ''),
				'size' => ($uploaded['size'][$index] ?? 0),
			];
		}

		return $files;
	}//end normaliseUploadedFiles()

	/**
	 * Detect executable uploads via extension and magic bytes.
	 *
	 * @param string $name The original filename.
	 * @param string $tmpName The temp path of the uploaded content.
	 *
	 * @return bool True when the file appears to be an executable.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	private function isExecutable(string $name, string $tmpName): bool {
		$extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
		if (in_array($extension, self::BLOCKED_EXTENSIONS, true) === true) {
			return true;
		}

		if ($tmpName === '' || is_readable($tmpName) === false) {
			return false;
		}

		$handle = fopen($tmpName, 'rb');
		if ($handle === false) {
			return false;
		}

		$magic = (string)fread($handle, 4);
		fclose($handle);

		// MZ (PE/DOS), ELF (\x7fELF) and shell shebang.
		return $this->hasExecutableMagic(magic: $magic);
	}//end isExecutable()

	/**
	 * Whether the leading bytes identify an executable format.
	 *
	 * @param string $magic The first four bytes of the uploaded file.
	 *
	 * @return bool True for a PE/DOS, ELF or shebang header.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	private function hasExecutableMagic(string $magic): bool {
		if (str_starts_with($magic, 'MZ') === true) {
			return true;
		}

		if (str_starts_with($magic, "\x7f" . 'ELF') === true) {
			return true;
		}

		return str_starts_with($magic, '#!');
	}//end hasExecutableMagic()
}//end class
