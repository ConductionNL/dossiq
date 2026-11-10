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
use OCA\Dossiq\Service\Support\LanguageMapText;
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
	 * The answer of a copy that did not happen; its reason is filled in.
	 *
	 * @var array{complete: false, domain: string, carried: array, notCarried: array, reason: string}
	 */
	private const NOT_COPIED = [
		'complete' => false,
		'domain' => '',
		'carried' => [],
		'notCarried' => [],
		'reason' => '',
	];


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
	 * @param LanguageMapText $text A translatable title or name as one string.
	 */
	public function __construct(
		private readonly StarterStore $store,
		private readonly CaseTypeCopyService $copier,
		private readonly LoggerInterface $logger,
		private readonly LanguageMapText $text = new LanguageMapText(),
	) {
	}//end __construct()

	/**
	 * Copy one domain into a new one.
	 *
	 * @param string $domainId The domain to copy.
	 * @param string $name     What the new domain is called.
	 *
	 * @return array{complete: bool, domain: string, carried: array<string, int>, notCarried: array<int, string>, reason: string}
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function copy(string $domainId, string $name): array {
		$source = $this->store->row(configKey: self::DOMAINS, id: $domainId);
		if ($source === null) {
			return array_merge(self::NOT_COPIED, ['reason' => 'not_found']);
		}

		$created = $this->store->save(
			configKey: self::DOMAINS,
			payload: $this->domainPayload(source: $source, name: $name),
		);

		$copy = new DomainCopy();
		$copy->sourceId = $domainId;
		$copy->source = $source;
		$copy->name = $name;
		$copy->newId = $this->store->idOf(row: $created);
		if ($copy->newId === '') {
			return array_merge(self::NOT_COPIED, ['reason' => 'write_failed']);
		}

		return $this->copyContent(copy: $copy);
	}//end copy()

	/**
	 * Copy the case types and content templates into the new domain, link them, and answer the copy.
	 *
	 * @param DomainCopy $copy The copy, with its new domain.
	 *
	 * @return array{complete: bool, domain: string, carried: array<string, int>, notCarried: array<int, string>, reason: string}
	 */
	private function copyContent(DomainCopy $copy): array {
		$notCarried = [];
		$map = $this->copyCaseTypes(
			sourceDomainId: $copy->sourceId,
			newDomainId: $copy->newId,
			notCarried: $notCarried,
		);

		$templates = $this->copyTemplates(map: $map, notCarried: $notCarried);

		$this->store->save(
			configKey: self::DOMAINS,
			payload: array_merge(
				$this->domainPayload(source: $copy->source, name: $copy->name),
				['caseTypes' => array_values($map)]
			),
			id: $copy->newId,
		);

		$this->logger->info(
			'Dossiq starter: a domain was copied',
			['source' => $copy->sourceId, 'created' => $copy->newId, 'notCarried' => $notCarried]
		);

		return [
			'complete' => ($notCarried === []),
			'domain' => $copy->newId,
			'carried' => ['caseType' => count($map), 'contentTemplate' => $templates],
			'notCarried' => $notCarried,
			'reason' => '',
		];
	}//end copyContent()

	/**
	 * The new domain as stored, before its case types are linked.
	 *
	 * @param array<string, mixed> $source The domain copied from.
	 * @param string               $name   The new domain's name.
	 *
	 * @return array<string, mixed>
	 */
	private function domainPayload(array $source, string $name): array {
		return [
			'groupName' => $name,
			'description' => (string)($source['description'] ?? ''),
			'partner' => (string)($source['partner'] ?? ''),
			'caseTypes' => [],
		];
	}//end domainPayload()

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
				$notCarried[] = ('caseType:' . $this->text->textOr(value: ($source['title'] ?? null), fallback: $sourceId));
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
			$repointed = $this->repointedScope(template: $template, map: $map);
			if ($repointed === []) {
				continue;
			}

			unset($template['id'], $template['@self']);
			$template['caseTypes'] = $repointed;

			if ($this->store->save(configKey: self::TEMPLATES, payload: $template) !== null) {
				$copied++;
				continue;
			}

			$notCarried[] = ('contentTemplate:' . $this->text->textOf(value: ($template['name'] ?? '')));
		}//end foreach

		return $copied;
	}//end copyTemplates()

	/**
	 * One template's scope, rewritten onto the copied case types.
	 *
	 * An empty answer means the template named none of the case types this
	 * copy carried, so it belongs to another domain and is left alone.
	 *
	 * @param array<string, mixed>  $template The template row.
	 * @param array<string, string> $map      Old case type id to new case type id.
	 *
	 * @return array<int, string> The new case type ids the template should name.
	 */
	private function repointedScope(array $template, array $map): array {
		$scope = ($template['caseTypes'] ?? []);
		if (is_array($scope) === false) {
			return [];
		}

		$repointed = [];
		foreach ($scope as $caseTypeId) {
			$key = (string)$caseTypeId;
			if (isset($map[$key]) === true) {
				$repointed[] = $map[$key];
			}
		}

		return $repointed;
	}//end repointedScope()
}//end class
