<?php

/**
 * Dossiq Woo result link
 *
 * Where the requester reads what was made public
 * (woo-dossier-shared-with-the-requester REQ-WDS-003). The case carries
 * `resultLink` in the shape of portaliq's portalCase: a label and the case's
 * own `wooPublicationUrl`, written when the decision is published and cleared
 * when the publication is withdrawn. It never carries another url.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-sees-where-the-decision-became-public-req-wds-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

/**
 * Projects the publication state onto `resultLink`.
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-sees-where-the-decision-became-public-req-wds-003
 */
class WooResultLink {

	/**
	 * The words the requester reads.
	 */
	public const LABEL = 'Bekijk wat openbaar is gemaakt';

	/**
	 * The `resultLink` change a publication state implies, or [] for none.
	 *
	 * @param array<string, mixed> $state The case fields being written: `wooPublicationStatus`, `wooPublicationUrl`.
	 *
	 * @return array<string, mixed> `['resultLink' => {label, url}]`, `['resultLink' => null]` or [].
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-sees-where-the-decision-became-public-req-wds-003
	 */
	public function changesFor(array $state): array {
		$status = (string)($state['wooPublicationStatus'] ?? '');
		if ($status === 'withdrawn') {
			return ['resultLink' => null];
		}

		$url = (string)($state['wooPublicationUrl'] ?? '');
		if ($status !== 'published' || $url === '') {
			return [];
		}

		return ['resultLink' => ['label' => self::LABEL, 'url' => $url]];
	}//end changesFor()
}//end class
