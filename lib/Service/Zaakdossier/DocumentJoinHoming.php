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

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\AppInfo\Application;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What the ZRC does with a document's file when a zaakinformatieobject lands.
 *
 * The ZGW API creates the informatieobject before any join names a case, so
 * its file starts in the record's own folder. The first join is where the
 * case becomes known: this class refuses the join when that case has no
 * folder (REQ-DPR-003, the 422) and otherwise moves the file into it. Kept
 * out of ZrcController so the rule is one test, not a controller drive.
 *
 * @spec openspec/specs/document-projection/spec.md
 */
class DocumentJoinHoming {

	/**
	 * @param DocumentProjectionService $projection The projection that moves the file.
	 * @param LoggerInterface $logger Where a failed move is reported.
	 */
	public function __construct(
		private readonly DocumentProjectionService $projection,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Why a join to this case must be refused, or null when it may proceed.
	 *
	 * @param string $caseUrl The zaak URL or uuid the join names.
	 *
	 * @return string|null The refusal, naming the case.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function refusal(string $caseUrl): ?string {
		$caseId = $this->uuidFromUrl(url: $caseUrl);
		if ($caseId === '') {
			// Nothing to refuse on: the ZGW rules validate the reference.
			return null;
		}

		if ($this->projection->caseHasFolder(caseId: $caseId) === true) {
			return null;
		}

		return 'Case ' . $caseId . ' has no folder to hold its documents';
	}//end refusal()

	/**
	 * Move the document's file into the case's folder, when it is not yet in any.
	 *
	 * A failure here is logged, not thrown: the join exists, the file is where
	 * it was, and the next join or the repair step moves it.
	 *
	 * @param string $caseUrl The zaak URL or uuid.
	 * @param string $informatieobjectUrl The informatieobject URL or uuid.
	 *
	 * @return bool True when the file moved.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function home(string $caseUrl, string $informatieobjectUrl): bool {
		$caseId = $this->uuidFromUrl(url: $caseUrl);
		$recordId = $this->uuidFromUrl(url: $informatieobjectUrl);
		if ($caseId === '' || $recordId === '') {
			return false;
		}

		try {
			return $this->projection->homeDocument(recordId: $recordId, caseId: $caseId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq documents: could not move document ' . $recordId . ' into case ' . $caseId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e],
			);

			return false;
		}
	}//end home()

	/**
	 * The uuid a ZGW resource URL ends in, or the value itself when it is one.
	 *
	 * @param string $url A ZGW URL or a bare uuid.
	 *
	 * @return string The uuid, or ''.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function uuidFromUrl(string $url): string {
		$path = (string)(parse_url(trim($url), PHP_URL_PATH) ?? '');
		if ($path === '') {
			$path = trim($url);
		}

		return trim(basename(rtrim($path, '/')));
	}//end uuidFromUrl()
}//end class
