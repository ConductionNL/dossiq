<?php

/**
 * What a case type derived from another one looks like.
 *
 * Two gestures make a case type out of a case type, and they mean different
 * things. A DUPLICATE is a second case type: new title, new identifier, its own
 * chain, none of the first one's siblings. A VERSION is the same case type
 * later on: same title, same identifier (ZGW's `identificatie` is what makes
 * two rows versions of one zaaktype), one version number on, linked back to the
 * version it succeeds.
 *
 * The difference is a list of fields, and that list is the only thing separating
 * the two paths, so it lives here on its own rather than inside the service that
 * does the writing. Keeping the writing in one place is what stopped the two
 * paths drifting: the initial-status repointing was missing from the copy path
 * for as long as each gesture carried its own copy of the mechanics.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

/**
 * Builds the payload for a duplicated or a versioned case type.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
class DerivedCaseTypePayload {

	/**
	 * The payload for a duplicate: a second case type, not a version.
	 *
	 * @param array<string, mixed> $source The case type being copied.
	 *
	 * @return array<string, mixed> The payload to save as a new object.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function duplicate(array $source): array {
		$payload = $this->stripIdentity(data: $source);

		$payload['title'] = 'Copy of ' . (string)($source['title'] ?? '');
		$payload['isDraft'] = true;
		$payload['identifier'] = $this->generateIdentifier(
			sourceIdentifier: (string)($source['identifier'] ?? '')
		);
		$payload['publicationRequired'] = false;
		if (array_key_exists('publicationText', $payload) === true) {
			$payload['publicationText'] = '';
		}

		// A copy does not inherit the source's pinned workflow definition.
		$payload['workflowDefinition'] = null;

		// A duplicate is a new definition, not a sibling of the source's
		// related and sub case types.
		$payload['relatedCaseTypes'] = [];
		$payload['subCaseTypes'] = [];

		// It starts its own chain. Carrying the source's version number or its
		// links would make two unrelated case types read as versions of one.
		$payload['version'] = 1;
		$payload['previousVersion'] = null;
		$payload['supersededBy'] = null;

		return $payload;
	}//end duplicate()

	/**
	 * The payload for the next version of a case type.
	 *
	 * Keeps what makes it the same case type (title, identifier, its links to
	 * related and sub case types) and resets what makes it a new version: the
	 * draft flag, the version number, the link back, and the forward link,
	 * which stays empty until something replaces THIS version in turn.
	 *
	 * 🔴 `workflowDefinition` IS CARRIED, AND THAT IS A CHANGE. It used to be
	 * cleared here for the reason a duplicate clears it: it named a workflow
	 * template belonging to the previous version, and a type claiming a default
	 * route its own Workflow tab cannot show is worse than a type claiming
	 * none. That reasoning was sound and the conclusion was wrong, because the
	 * premise was a gap rather than a law. A new version did not copy the
	 * previous version's workflow templates AT ALL, so clearing the pin was the
	 * only honest thing to do, and the result was that every new version of a
	 * case type lost its process and an author rebuilt it by hand.
	 * {@see \OCA\Dossiq\Service\CaseTypeCopyService} now copies the templates
	 * along with the statuses and repoints this pin at the new version's OWN
	 * copy, exactly as it repoints `initialStatus`. The value left here is the
	 * PREVIOUS version's template id, which is what the repoint maps FROM; when
	 * there is nothing to map it to, the copy clears it, so the case the old
	 * docblock feared still cannot happen.
	 *
	 * @param array<string, mixed> $source   The version being succeeded.
	 * @param string               $sourceId Its id.
	 *
	 * @return array<string, mixed> The payload to save as a new object.
	 *
	 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
	 */
	public function nextVersion(array $source, string $sourceId): array {
		$payload = $this->stripIdentity(data: $source);

		$payload['isDraft'] = true;
		$payload['version'] = ($this->versionOf(caseType: $source) + 1);
		$payload['previousVersion'] = $sourceId;
		$payload['supersededBy'] = null;

		// A draft version is not valid for anything yet. Carrying the previous
		// version's dates would make a brand-new draft read as the version in
		// force since a date that belongs to its predecessor, and would make
		// the closing write at publish time a no-op.
		$payload['validFrom'] = null;
		$payload['validUntil'] = null;
		$payload['versionDate'] = null;

		return $payload;
	}//end nextVersion()

	/**
	 * The version number a case type carries.
	 *
	 * A row saved before the property existed is version one: it is the first
	 * version of itself, and answering zero would make the NEXT version one as
	 * well, which is two rows both claiming to be the first.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return integer The version, at least one.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function versionOf(array $caseType): int {
		$version = (int)($caseType['version'] ?? 0);
		if ($version < 1) {
			return 1;
		}

		return $version;
	}//end versionOf()

	/**
	 * Strip identity metadata so saving creates rather than updates.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return array<string, mixed> The data without its identity.
	 */
	private function stripIdentity(array $data): array {
		unset($data['id'], $data['@self']);
		return $data;
	}//end stripIdentity()

	/**
	 * A fresh, human-traceable identifier for a duplicate.
	 *
	 * @param string $sourceIdentifier The source case type's identifier.
	 *
	 * @return string The new identifier.
	 */
	private function generateIdentifier(string $sourceIdentifier): string {
		$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
		if ($sourceIdentifier === '') {
			return 'CT-' . $suffix;
		}

		return $sourceIdentifier . '-copy-' . $suffix;
	}//end generateIdentifier()
}//end class
