<?php

/**
 * Dossiq HermiqPost.
 *
 * One POST to a Hermiq endpoint: the absolute URL and the HTTP client options.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Assistant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Assistant;

/**
 * The URL and options of one POST to Hermiq.
 *
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */
final class HermiqPost {
	/**
	 * Build one POST.
	 *
	 * @param string               $url     The absolute endpoint URL.
	 * @param array<string, mixed> $options The HTTP client options.
	 */
	public function __construct(
		public readonly string $url,
		public readonly array $options,
	) {
	}//end __construct()
}//end class
