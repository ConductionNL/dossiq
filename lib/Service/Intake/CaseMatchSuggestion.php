<?php

/**
 * Which open case an incoming message probably belongs to
 *
 * A message in the intake queue that became no case often answers one: the
 * sender quotes the case number, or the reference the case carries from
 * elsewhere. This names that case, so the handler links it in one act instead
 * of searching for it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Suggests the open case a message refers to, read through the caller's own permissions.
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */
class CaseMatchSuggestion {
	use SearchesObjects;

	/**
	 * A reference as people quote it: a year, a dash and a running number ("2026-0079").
	 *
	 * @var string
	 */
	private const REFERENCE_PATTERN = '/\b(\d{4}-\d{3,})\b/';

	/**
	 * How many quoted references of one message are looked up at most.
	 *
	 * @var integer
	 */
	private const MAX_REFERENCES = 3;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The register, the case schema and the object service.
	 * @param LoggerInterface $logger          Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Add a `suggestion` to every queue item: the case it probably belongs to, or null.
	 *
	 * @param array<int, array<string, mixed>> $items The queue items.
	 *
	 * @return array<int, array<string, mixed>> The items, each with `suggestion`.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function annotate(array $items): array {
		$found = [];
		foreach ($items as $index => $item) {
			$items[$index]['suggestion'] = $this->suggest(subject: (string)($item['subject'] ?? ''), found: $found);
		}

		return $items;
	}//end annotate()

	/**
	 * Keep the items that look like a case (`IS NOT NULL`) or the ones that do not (`IS NULL`); anything else keeps all.
	 *
	 * @param array<int, array<string, mixed>> $items      Annotated queue items.
	 * @param string                           $suggestion The filter as a list sends it.
	 *
	 * @return array<int, array<string, mixed>> The items kept.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function narrow(array $items, string $suggestion): array {
		$wanted = strtoupper(trim($suggestion));
		if ($wanted !== 'IS NULL' && $wanted !== 'IS NOT NULL') {
			return $items;
		}

		return array_values(
			array_filter(
				$items,
				static fn (array $item): bool => (($item['suggestion'] ?? null) === null) === ($wanted === 'IS NULL')
			)
		);
	}//end narrow()

	/**
	 * The open case one subject line refers to.
	 *
	 * The first quoted reference that names an open case the caller may see
	 * wins. A reference is matched on the case number, then on the reference
	 * the case carries from elsewhere.
	 *
	 * @param string $subject The subject line.
	 * @param array<string, array|null> $found References already looked up, so a queue asks once per reference.
	 *
	 * @return array{case: string, identifier: string, title: string, matchedOn: string}|null The case, or null.
	 */
	private function suggest(string $subject, array &$found): ?array {
		if (preg_match_all(self::REFERENCE_PATTERN, $subject, $matches) < 1) {
			return null;
		}

		foreach (array_slice(array_unique($matches[1]), 0, self::MAX_REFERENCES) as $reference) {
			if (array_key_exists($reference, $found) === false) {
				$found[$reference] = $this->lookUp(reference: $reference);
			}

			if ($found[$reference] !== null) {
				return $found[$reference];
			}
		}

		return null;
	}//end suggest()

	/**
	 * The open case a reference names, read with the caller's permissions.
	 *
	 * @param string $reference The quoted reference.
	 *
	 * @return array{case: string, identifier: string, title: string, matchedOn: string}|null The case, or null.
	 */
	private function lookUp(string $reference): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		foreach (['identifier', 'externalIdentifier'] as $field) {
			try {
				$cases = $this->searchObjectsAsArrays(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					filters: [$field => $reference, 'isFinalStatus' => false, '_limit' => 1]
				);
			} catch (\Throwable $e) {
				// No suggestion is a fair answer: the handler links the message by hand, as before suggestions existed.
				$this->logger->warning('Dossiq: case match lookup failed', ['field' => $field, 'error' => $e->getMessage()]);
				$cases = [];
			}

			$case = ($cases[0] ?? null);
			if (is_array($case) === true) {
				return [
					'case' => (string)($case['id'] ?? ($case['@self']['id'] ?? '')),
					'identifier' => (string)($case['identifier'] ?? $reference),
					'title' => (string)($case['title'] ?? ''),
					'matchedOn' => $field,
				];
			}
		}

		return null;
	}//end lookUp()
}//end class
