<?php

/**
 * Dossiq SendsHalHeaders trait
 *
 * The request headers a HAL register API is called with: the HAL accept
 * header, plus the integration's API key when one is configured.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared private step; each using class carries its own spec
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * HAL request headers through the using adapter's `$this->mode`.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait SendsHalHeaders {
	/**
	 * Build the shared request headers of one integration.
	 *
	 * @param string $integration The integration whose API key is sent.
	 *
	 * @return array<string,string>
	 */
	private function halHeaders(string $integration): array {
		$apiKey = $this->mode->setting(integration: $integration, key: 'apiKey');
		$headers = ['Accept' => 'application/hal+json'];
		if ($apiKey !== '') {
			$headers['X-Api-Key'] = $apiKey;
		}

		return $headers;
	}//end halHeaders()
}//end trait
