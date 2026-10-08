<?php

/**
 * What the virus scanner recorded about one file, read and never inferred.
 *
 * A document on a case carried a hash and no verdict. The scan is the
 * platform's: `files_antivirus` checks the node and writes what it found, and
 * a handler deciding whether to open an attachment could not see that answer
 * anywhere near the row. This reader is the one place dossiq asks.
 *
 * 🔴 CLEAN IS NEVER ASSUMED (company ADR-102). Three states leave this class:
 * `clean`, with the time of the check; `infected`; and `not-scanned`. Every
 * uncertainty collapses into `not-scanned`, and none of them collapses into
 * `clean`: the app is not installed, the scanner has not reached the file,
 * the row is absent, the recorded status is one this reader does not know, or
 * the table is not there at all. A file nobody checked and a file the checker
 * cleared must not read the same, because the whole value of the column is
 * that difference.
 *
 * dossiq scans nothing and stores nothing. The verdict is read live from
 * what the scanner recorded, so a re-scan is visible on the next read and no
 * stale copy of a verdict can outlive the file it was about.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Documents
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Documents;

use OCP\App\IAppManager;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Reads the verdict `files_antivirus` recorded for a file id.
 */
class ScanVerdictReader {
	/**
	 * The app that does the scanning. dossiq only reads what it wrote.
	 *
	 * @var string
	 */
	public const SCANNER_APP = 'files_antivirus';

	/**
	 * The scanner checked this file and found nothing.
	 *
	 * @var string
	 */
	public const STATE_CLEAN = 'clean';

	/**
	 * The scanner checked this file and found something.
	 *
	 * @var string
	 */
	public const STATE_INFECTED = 'infected';

	/**
	 * Nobody has told us this file is either. Not a claim that it is safe.
	 *
	 * @var string
	 */
	public const STATE_NOT_SCANNED = 'not-scanned';

	/**
	 * The status `files_antivirus` writes for a file it cleared.
	 *
	 * @var int
	 */
	private const RECORDED_CLEAN = 0;

	/**
	 * The status `files_antivirus` writes for a file it found something in.
	 *
	 * @var int
	 */
	private const RECORDED_INFECTED = 1;

	/**
	 * Constructor.
	 *
	 * @param IAppManager     $apps   Whether the scanner is on this instance at all.
	 * @param IDBConnection   $db     Reads the row the scanner wrote.
	 * @param LoggerInterface $logger Records a read that could not be made.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $apps,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this instance has a scanner at all.
	 *
	 * @return bool True when `files_antivirus` is installed.
	 *
	 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
	 */
	public function scannerPresent(): bool {
		try {
			return $this->apps->isInstalled(self::SCANNER_APP);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'dossiq could not ask whether the virus scanner is installed',
				['exception' => $e],
			);
			return false;
		}
	}//end scannerPresent()

	/**
	 * The verdict recorded for one file.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return array{state: string, scannedAt: string|null, scannerPresent: bool}
	 *         The state, the moment of the check when there was one, and
	 *         whether a scanner is installed, so the reader of this answer can
	 *         tell "no scanner here" from "not reached yet".
	 *
	 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
	 */
	public function verdictFor(int $fileId): array {
		if ($this->scannerPresent() === false) {
			return $this->notScanned(present: false);
		}

		if ($fileId <= 0) {
			return $this->notScanned(present: true);
		}

		$row = $this->recordedRow(fileId: $fileId);
		if ($row === null) {
			return $this->notScanned(present: true);
		}

		$status = (int)($row['status'] ?? -1);
		$state = match ($status) {
			self::RECORDED_CLEAN => self::STATE_CLEAN,
			self::RECORDED_INFECTED => self::STATE_INFECTED,
			default => self::STATE_NOT_SCANNED,
		};

		if ($state === self::STATE_NOT_SCANNED) {
			return $this->notScanned(present: true);
		}

		return [
			'state' => $state,
			'scannedAt' => $this->checkedAt(raw: ($row['check_time'] ?? null)),
			'scannerPresent' => true,
		];
	}//end verdictFor()

	/**
	 * The row the scanner wrote for this file, or null when there is none.
	 *
	 * The scanner is an optional app and its table goes with it, so a read
	 * that throws is a read that answers nothing rather than a request that
	 * fails. The document row still has to render.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return array<string, mixed>|null The recorded row, or null.
	 *
	 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
	 */
	private function recordedRow(int $fileId): ?array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('status', 'check_time')
				->from('files_antivirus')
				->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId)))
				->setMaxResults(1);

			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();

			if (is_array($row) === false) {
				return null;
			}

			return $row;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'dossiq could not read the recorded virus scan for a file',
				['fileId' => $fileId, 'exception' => $e],
			);
			return null;
		}
	}//end recordedRow()

	/**
	 * The check time as an ISO 8601 moment, or null when there is not one.
	 *
	 * @param mixed $raw The stored check time, a unix timestamp.
	 *
	 * @return string|null The moment, or null.
	 *
	 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
	 */
	private function checkedAt(mixed $raw): ?string {
		if (is_numeric($raw) === false) {
			return null;
		}

		$stamp = (int)$raw;
		if ($stamp <= 0) {
			return null;
		}

		return gmdate('c', $stamp);
	}//end checkedAt()

	/**
	 * The one answer every uncertainty collapses into.
	 *
	 * @param bool $present Whether a scanner is installed.
	 *
	 * @return array{state: string, scannedAt: string|null, scannerPresent: bool}
	 *         The not-scanned verdict.
	 *
	 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
	 */
	private function notScanned(bool $present): array {
		return [
			'state' => self::STATE_NOT_SCANNED,
			'scannedAt' => null,
			'scannerPresent' => $present,
		];
	}//end notScanned()
}//end class
