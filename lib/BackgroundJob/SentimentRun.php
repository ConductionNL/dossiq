<?php

/**
 * Dossiq sentiment run
 *
 * What one run of the sentiment job shares between its steps.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

/**
 * One run of the sentiment job: where it reads and writes, and the trigger words.
 *
 * Built by {@see SentimentAnalysisJob} only; it never leaves it.
 *
 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-realtime-sentiment-detectie-en-escalatie-aanbeveling
 */
final class SentimentRun {

	/**
	 * The OpenRegister object service.
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * The register.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The contactmoment schema.
	 *
	 * @var string
	 */
	public string $contactmomentSchema = '';

	/**
	 * The klant sentiment schema.
	 *
	 * @var string
	 */
	public string $sentimentSchema = '';

	/**
	 * The configured trigger words.
	 *
	 * @var array<int, string>
	 */
	public array $triggerWords = [];
}//end class
