<?php

/**
 * Standing a whole domain up from another one.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Starter
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
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

use OCA\Dossiq\Service\CaseTypeCopyService;
use Psr\Log\LoggerInterface;

/**
 * Copies a domain: its case types, and with each of them the role types,
 * templates and code lists that belong to it.
 *
 * 🔑 A DECLARED LIST, NEVER A DEEP CLONE. OpenProject puts seventeen dependent
 * copy services behind one Copy project act, and the lesson taken from it is
 * not the seventeen: it is that every one of them is named. A copy that follows
 * whatever is reachable will one day follow a reference out of the domain, and
 * nobody will know until it has.
 *
 * 🔑 WHAT THE LIST IS, AND WHY IT IS SHORT. A domain here is a
 * `caseTypeGroup`, and the register models its contents through its case types:
 * the role types, the document and decision types and the property definitions
 * that hold the code lists all hang off a case type, and
 * {@see CaseTypeCopyService} already carries every one of them and repoints the
 * initial status. ADR-011 says search before implementing a utility, so this
 * class calls that one rather than growing a second copy of it that forgets one
 * child schema. What is left for this class is the group row itself and the
 * content templates scoped to the group's case types.
 *
 * 🔑 A COPY THAT DROPPED SOMETHING SAYS SO AND DOES NOT REPORT SUCCESS. The
 * failure this class exists to prevent is the silent partial: an administrator
 * stands up a new domain, sees a green tick, and finds three weeks later that
 * the templates never came.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
class DomainCopyService {

	/**
	 * The app config key naming the domain (case type group) schema.
	 */
	public const DOMAINS = 'case_type_group_schema';

	/**
	 * The app config key naming the case type schema.
	 */
	public const CASE_TYPES = 'case_type_schema';

	/**
	 * The app config key naming the content template schema.
	 */
	public const TEMPLATES = 'content_template_schema';

	/**
	 * Constructor.
	 *
	 * @param StarterStore        $store  The OpenRegister seam.
	 * @param CaseTypeCopyService $copier The copy that carries a case type's children.
	 * @param LoggerInterface     $logger Logger.
	 */
	public function __construct(
		private readonly StarterStore $store,
		private readonly CaseTypeCopyService $copier,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Copy one domain into a new one.
	 *
	 * @param string $domainId The domain to copy.
	 * @param string $name     What the new domain is called.
	 *
	 * @return array{complete: bool, domain: string, carried: array<string, int>, notCarried: array<int, string>, reason: string}
	 */
	public function copy(string $domainId, string $name): array {
		$result = [
			'complete' => false,
			'domain' => '',
			'carried' => [],
			'notCarried' => [],
			'reason' => '',
		];

		$source = $this->store->row(configKey: self::DOMAINS, id: $domainId);
		if ($source === null) {
			$result['reason'] = 'not_found';
			return $result;
		}

		$created = $this->store->save(
			configKey: self::DOMAINS,
			payload: [
				'groupName' => $name,
				'description' => (string)($source['description'] ?? ''),
				'partner' => (string)($source['partner'] ?? ''),
				'caseTypes' => [],
			],
		);

		$newDomainId = $this->store->idOf(row: $created);
		if ($newDomainId === '') {
			$result['reason'] = 'write_failed';
			return $result;
		}

		$notCarried = [];
		$map = $this->copyCaseTypes(
			sourceDomainId: $domainId,
			newDomainId: $newDomainId,
			notCarried: $notCarried,
		);

		$templates = $this->copyTemplates(map: $map, notCarried: $notCarried);

		$this->store->save(
			configKey: self::DOMAINS,
			payload: [
				'groupName' => $name,
				'description' => (string)($source['description'] ?? ''),
				'partner' => (string)($source['partner'] ?? ''),
				'caseTypes' => array_values($map),
			],
			id: $newDomainId,
		);

		$this->logger->info(
			'Dossiq starter: a domain was copied',
			['source' => $domainId, 'created' => $newDomainId, 'notCarried' => $notCarried]
		);

		$result['complete'] = ($notCarried === []);
		$result['domain'] = $newDomainId;
		$result['carried'] = ['caseType' => count($map), 'contentTemplate' => $templates];
		$result['notCarried'] = $notCarried;

		return $result;
	}//end copy()

	/**
	 * Copy every case type of the source domain.
	 *
	 * @param string             $sourceDomainId The domain being copied.
	 * @param string             $newDomainId    The new domain.
	 * @param array<int, string> $notCarried     Collected reasons, by reference.
	 *
	 * @return array<string, string> Old case type id to new case type id.
	 */
	private function copyCaseTypes(string $sourceDomainId, string $newDomainId, array &$notCarried): array {
		$sources = $this->store->rows(
			configKey: self::CASE_TYPES,
			filters: ['caseTypeGroup' => $sourceDomainId],
		);

		if ($sources === null) {
			$notCarried[] = 'caseType';
			return [];
		}

		$map = [];
		foreach ($sources as $source) {
			$sourceId = $this->store->idOf(row: $source);
			$copy = $this->copier->copy(caseTypeId: $sourceId);
			if ($copy === null) {
				$notCarried[] = ('caseType:' . (string)($source['title'] ?? $sourceId));
				continue;
			}

			$copyId = (string)($copy['id'] ?? '');
			$copy['caseTypeGroup'] = $newDomainId;
			$this->store->save(configKey: self::CASE_TYPES, payload: $copy, id: $copyId);
			$map[$sourceId] = $copyId;
		}

		return $map;
	}//end copyCaseTypes()

	/**
	 * Copy every content template scoped to one of the copied case types, with
	 * its scope repointed at the copies.
	 *
	 * @param array<string, string> $map        Old case type id to new case type id.
	 * @param array<int, string>    $notCarried Collected reasons, by reference.
	 *
	 * @return integer How many templates came along.
	 */
	private function copyTemplates(array $map, array &$notCarried): int {
		if ($map === []) {
			return 0;
		}

		if ($this->store->available(configKey: self::TEMPLATES) === false) {
			// Unconfigured is not empty, and reporting "carried 0" here would
			// be exactly the silent partial this class exists to prevent.
			$notCarried[] = 'contentTemplate';
			return 0;
		}

		$templates = $this->store->rows(configKey: self::TEMPLATES);
		if ($templates === null) {
			$notCarried[] = 'contentTemplate';
			return 0;
		}

		$copied = 0;
		foreach ($templates as $template) {
			$scope = ($template['caseTypes'] ?? []);
			if (is_array($scope) === false) {
				continue;
			}

			$repointed = [];
			foreach ($scope as $caseTypeId) {
				$key = (string)$caseTypeId;
				if (isset($map[$key]) === true) {
					$repointed[] = $map[$key];
				}
			}

			if ($repointed === []) {
				continue;
			}

			unset($template['id'], $template['@self']);
			$template['caseTypes'] = $repointed;

			if ($this->store->save(configKey: self::TEMPLATES, payload: $template) !== null) {
				$copied++;
				continue;
			}

			$notCarried[] = ('contentTemplate:' . (string)($template['name'] ?? ''));
		}//end foreach

		return $copied;
	}//end copyTemplates()
}//end class
