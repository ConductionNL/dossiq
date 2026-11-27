<?php

/**
 * Dossiq portal answer read.
 *
 * What a resident's answer, forwarded by portaliq, carries: the request it
 * answers, the answer text and the files with it, read into bytes. The files
 * are checked again for their number and size, because the route trusts the
 * assertion and not the forwarder's checks. Split out of
 * {@see \OCA\Dossiq\Controller\PortalWooAnswerController}, which keeps the
 * assertion, the audience and the hand-off to the answer service.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\CitizenManifestInterface;
use OCA\Dossiq\Portal\WooAnswerSubmission;
use OCP\IRequest;

/**
 * Reads a forwarded answer, and its files, into a submission.
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class PortalAnswerRead {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The forwarded request.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * The submission the request carries, for the resident the assertion named.
	 *
	 * @param string $subjectRef The verified `sub` claim.
	 *
	 * @return WooAnswerSubmission|null The submission, or null when there are too many files or one is too large.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function read(string $subjectRef): ?WooAnswerSubmission {
		$attachments = $this->attachments();
		if ($attachments === null) {
			return null;
		}

		$submission = new WooAnswerSubmission();
		$submission->subjectRef = $subjectRef;
		$submission->requestId = $this->stringParam(key: 'requestId');
		$submission->answer = $this->stringParam(key: 'antwoord');
		$submission->attachments = $attachments;

		return $submission;
	}//end read()

	/**
	 * The files portaliq forwarded under `bijlagen` (row-action-carries-files), read into bytes.
	 *
	 * Portaliq already checked the number and size; they are checked again
	 * here because this route trusts the assertion, not the forwarder's checks.
	 *
	 * @return list<array{name: string, content: string}>|null The files, or null when there are too many or one is too large.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function attachments(): ?array {
		$entries = $this->uploadEntries(uploaded: $this->request->getUploadedFile(CitizenManifestInterface::ANSWER_FILES_FIELD));
		if (count($entries) > CitizenManifestInterface::ANSWER_FILES_MAX) {
			return null;
		}

		$files = [];
		foreach ($entries as $entry) {
			if ((int)($entry['size'] ?? 0) > CitizenManifestInterface::ANSWER_FILES_MAX_BYTES) {
				return null;
			}

			$content = $this->readUpload(path: (string)($entry['tmp_name'] ?? ''));
			if ($content !== null) {
				$files[] = ['name' => basename((string)($entry['name'] ?? 'bijlage')), 'content' => $content];
			}
		}

		return $files;
	}//end attachments()

	/**
	 * One upload or PHP's parallel-array shape, as a list of entries.
	 *
	 * @param mixed $uploaded What `IRequest::getUploadedFile()` answered.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function uploadEntries(mixed $uploaded): array {
		if (is_array($uploaded) === false || isset($uploaded['tmp_name']) === false) {
			return [];
		}

		if (is_array($uploaded['tmp_name']) === false) {
			return [$uploaded];
		}

		$entries = [];
		foreach (array_keys($uploaded['tmp_name']) as $key) {
			$entries[] = [
				'name' => ($uploaded['name'][$key] ?? ''),
				'tmp_name' => $uploaded['tmp_name'][$key],
				'size' => ($uploaded['size'][$key] ?? 0),
			];
		}

		return $entries;
	}//end uploadEntries()

	/**
	 * The bytes of an uploaded file, or null when it cannot be read.
	 *
	 * @param string $path The temporary path.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function readUpload(string $path): ?string {
		if ($path === '' || is_readable($path) === false) {
			return null;
		}

		$content = file_get_contents($path);
		if ($content === false) {
			return null;
		}

		return $content;
	}//end readUpload()

	/**
	 * A request param as a string, or '' when it is absent or not a string.
	 *
	 * @param string $key The param.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function stringParam(string $key): string {
		$value = $this->request->getParam($key, '');
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end stringParam()
}//end class
