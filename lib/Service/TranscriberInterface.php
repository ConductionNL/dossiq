<?php

/**
 * Dossiq TranscriberInterface
 *
 * Pluggable transcriber abstraction. Production binds an OpenConnector-backed
 * implementation that POSTs the audio blob to the configured LLM endpoint
 * (qwen-3.5 or other); the test suite binds a deterministic stub.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-9
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * Contract for voice-memo transcribers.
 */
interface TranscriberInterface {
	/**
	 * Transcribe a voice memo identified by its blob ref.
	 *
	 * @param string $blobRef The opaque storage reference for the audio blob.
	 * @param string $language The expected language (BCP-47, e.g. "nl", "en").
	 *
	 * @return string The plain-text transcription.
	 *
	 * @throws \RuntimeException On transcription failure.
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-9
	 */
	public function transcribe(string $blobRef, string $language): string;
}//end interface
