<?php

/**
 * Dossiq access link projection.
 *
 * What dossiq will render from a body OpenRegister served through an access
 * link, reduced a second time on this side of the boundary.
 *
 * OpenRegister's AccessLinkReader already applies this allow-list. Dossiq
 * applies it again on purpose: the two lists have to drift apart before an
 * internal reaches somebody with no account, rather than one change being
 * enough.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sharing;

/**
 * Strips a link body down to what its holder may read.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
 */
class AccessLinkProjection {
	/**
	 * The `@self` keys a holder may be shown.
	 *
	 * Mirrors `OCA\OpenRegister\Service\Sharing\AccessLinkReader`'s own
	 * allow-list. Dossiq applies it a second time on everything it renders
	 * from a link body, so an internal that OpenRegister starts publishing
	 * tomorrow does not reach a holder through dossiq today.
	 *
	 * @var array<int, string>
	 */
	public const PUBLISHABLE_SELF_KEYS = [
		'id',
		'uuid',
		'name',
		'description',
		'summary',
		'register',
		'schema',
		'published',
		'depublished',
		'created',
		'updated',
	];

	/**
	 * Reduce a link body to what a holder may be shown.
	 *
	 * Every `@self` is cut down to the keys OpenRegister publishes, and every
	 * other top-level key starting with `@` is dropped. This runs on dossiq's
	 * side of a boundary OpenRegister already guards, on purpose: the two
	 * allow-lists have to drift apart before an internal reaches a holder.
	 *
	 * @param array<string, mixed> $body The body OpenRegister returned.
	 *
	 * @return array<string, mixed> The body, stripped.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function strip(array $body): array {
		if (isset($body['subject']) === true && is_array($body['subject']) === true) {
			$body['subject'] = $this->strippedRow(row: $body['subject']);
		}

		if (isset($body['results']) === true && is_array($body['results']) === true) {
			$rows = [];
			foreach ($body['results'] as $row) {
				if (is_array($row) === false) {
					continue;
				}

				$rows[] = $this->strippedRow(row: $row);
			}

			$body['results'] = $rows;
		}

		return $body;
	}//end strip()

	/**
	 * One row of a link body, reduced.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed> The row, stripped.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	private function strippedRow(array $row): array {
		$stripped = [];
		foreach ($row as $key => $value) {
			if (str_starts_with((string)$key, '@') === true && (string)$key !== '@self') {
				continue;
			}

			$stripped[$key] = $value;
		}

		if (isset($stripped['@self']) === true) {
			$self = [];
			if (is_array($stripped['@self']) === true) {
				foreach (self::PUBLISHABLE_SELF_KEYS as $allowed) {
					if (array_key_exists($allowed, $stripped['@self']) === true) {
						$self[$allowed] = $stripped['@self'][$allowed];
					}
				}
			}

			$stripped['@self'] = $self;
		}

		return $stripped;
	}//end strippedRow()
}//end class
