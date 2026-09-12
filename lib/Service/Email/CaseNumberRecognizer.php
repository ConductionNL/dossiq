<?php

/**
 * Finds case numbers in mail text and resolves them to cases the mailbox owner may see.
 *
 * This is the one genuinely dossiq-specific part of email-to-case matching,
 * kept in its own class on purpose: design decision D6 wants the generic
 * matcher core (message iteration, cursor, idempotent leaf linking) lifted into
 * OpenRegister's email leaf later, with each app contributing only a
 * recognizer. pipelinq's recognizer is correspondent addresses; this one is
 * case numbers.
 *
 * 🔴 A CASE NUMBER IN A SUBJECT IS WRITTEN BY WHOEVER SENT THE MAIL. Anyone can
 * put `2026-0042` in a subject line, so recognising it must never be enough to
 * attach the mail to a case its recipient has no business with, or to a case in
 * another organisation. {@see self::resolveCases()} therefore holds every
 * candidate to four conditions, and the caller runs it inside
 * `ObjectService::runAs($owner)`:
 *
 *  - the search runs as the MAILBOX OWNER, with OpenRegister's RBAC and its
 *    organisation multitenancy both on, and multitenancy requested explicitly
 *    (see the flag's comment below for why "on" alone is not enough);
 *  - a row must equal the candidate exactly on `identifier`;
 *  - exactly one case may match; two visible cases with one number link to
 *    neither, because picking one would be a guess;
 *  - the case must pass `CaseAccessGuard::hasCaseReadAccess()`, the per-case
 *    check every dossiq case endpoint uses: the owner handles the case, is
 *    among its assignees, or is an administrator.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
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
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Support\SuppressesWarnings;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The case-number recognizer and its identifier resolution.
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */
class CaseNumberRecognizer {

	use SearchesObjects;
	use SuppressesWarnings;

	/**
	 * The recognizer every instance starts with (design D2).
	 *
	 * Capture group 1 is the bare identifier the `case` schema materialises
	 * (`2026-0042`). The optional uppercase prefix and brackets accept the
	 * legacy `[ZAAK-2026-000142]` tag as decoration, and the boundary guards
	 * keep `12026-00421` and phone-number fragments out.
	 *
	 * @var string
	 */
	public const DEFAULT_PATTERN = '/(?<![\w-])(?:\[)?(?:[A-Z]{2,10}-)?((?:19|20)\d{2}-\d{4,6})(?:\])?(?![\w-])/u';

	/**
	 * App-config key for a configured recognizer. Empty means the default.
	 *
	 * @var string
	 */
	public const PATTERN_KEY = 'email_case_matching_pattern';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The configured pattern.
	 * @param CaseAccessGuard $caseAccess      The per-case read check.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseAccessGuard $caseAccess,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Why a recognizer cannot be used, or null when it can.
	 *
	 * It must compile and contain at least one capture group, because group 1
	 * is the identifier. A recognizer that silently matches nothing is the
	 * classic silent failure, so the caller refuses the run instead.
	 *
	 * The group count is taken from PCRE itself rather than by reading the
	 * pattern: the body is wrapped as `(?:body)|` so it always matches the empty
	 * string, and with PREG_UNMATCHED_AS_NULL every group is then reported.
	 *
	 * @param string $pattern A full PCRE pattern, delimiters included.
	 *
	 * @return string|null The reason it is unusable, or null.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function validatePattern(string $pattern): ?string {
		if (trim($pattern) === '') {
			return 'The pattern is empty.';
		}

		$compiled = $this->withoutWarnings(operation: static fn (): int|false => preg_match($pattern, ''));
		if ($compiled === false) {
			return 'The pattern does not compile.';
		}

		$delimiter = $pattern[0];
		$closing = (['(' => ')', '{' => '}', '[' => ']', '<' => '>'][$delimiter] ?? $delimiter);
		$end = strrpos($pattern, $closing);
		if ($end === false || $end === 0) {
			return 'The pattern has no closing delimiter.';
		}

		$probe = $delimiter . '(?:' . substr($pattern, 1, ($end - 1)) . "\n)|" . $closing . substr($pattern, ($end + 1));
		$groups = [];
		$probed = $this->withoutWarnings(
			operation: static function () use ($probe, &$groups): int|false {
				return preg_match($probe, '', $groups, PREG_UNMATCHED_AS_NULL);
			}
		);
		if ($probed === false || (count($groups) - 1) < 1) {
			return 'The pattern has no capture group for the case number.';
		}

		return null;
	}//end validatePattern()

	/**
	 * The recognizer this instance uses, validated, or null when it must not run.
	 *
	 * @return string|null The pattern, or null after logging why it was refused.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function loadPattern(): ?string {
		$pattern = trim($this->settingsService->getConfigValue(self::PATTERN_KEY));
		if ($pattern === '') {
			$pattern = self::DEFAULT_PATTERN;
		}

		$reason = $this->validatePattern(pattern: $pattern);
		if ($reason !== null) {
			$this->logger->error(
				'Dossiq: email case matching refused to run, the configured ' . self::PATTERN_KEY . ' is unusable: ' . $reason,
				['app' => Application::APP_ID]
			);
			return null;
		}

		return $pattern;
	}//end loadPattern()

	/**
	 * The distinct case-number candidates a text contains, in order of appearance.
	 *
	 * @param string $text    The text to scan.
	 * @param string $pattern A validated recognizer.
	 *
	 * @return array<int, string> The identifiers captured by group 1.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function extractCaseNumberCandidates(string $text, string $pattern): array {
		if (trim($text) === '') {
			return [];
		}

		$matches = [];
		$count = $this->withoutWarnings(
			operation: static function () use ($pattern, $text, &$matches): int|false {
				return preg_match_all($pattern, $text, $matches);
			}
		);
		if ($count === false || $count === 0) {
			return [];
		}

		$candidates = [];
		foreach (($matches[1] ?? []) as $candidate) {
			$candidate = trim((string)$candidate);
			if ($candidate !== '' && in_array($candidate, $candidates, true) === false) {
				$candidates[] = $candidate;
			}
		}

		return $candidates;
	}//end extractCaseNumberCandidates()

	/**
	 * Resolve candidates to the distinct cases the mailbox owner may see.
	 *
	 * MUST be called inside `ObjectService::runAs($owner)`: the search takes its
	 * subject from the session, and runAs() is what puts the owner there.
	 *
	 * @param array<int, string> $candidates    The identifiers found in the text.
	 * @param object             $objectService OpenRegister's object service.
	 * @param string             $register      The configured register.
	 * @param string             $schema        The configured case schema.
	 * @param IUser              $owner         The mailbox owner.
	 *
	 * @return array<int, array{uuid: string, identifier: string, registerId: int, schemaId: int}> The cases.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function resolveCases(array $candidates, object $objectService, string $register, string $schema, IUser $owner): array {
		$cases = [];
		foreach ($candidates as $identifier) {
			$case = $this->resolveCase(
				identifier: $identifier,
				objectService: $objectService,
				register: $register,
				schema: $schema,
				owner: $owner
			);
			if ($case !== null) {
				$cases[$case['uuid']] = $case;
			}
		}

		return array_values($cases);
	}//end resolveCases()

	/**
	 * Resolve one identifier to exactly one case the owner may read, or nothing.
	 *
	 * @param string $identifier    The candidate identifier.
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The configured register.
	 * @param string $schema        The configured case schema.
	 * @param IUser  $owner         The mailbox owner.
	 *
	 * @return array{uuid: string, identifier: string, registerId: int, schemaId: int}|null The case, or null.
	 */
	private function resolveCase(string $identifier, object $objectService, string $register, string $schema, IUser $owner): ?array {
		$hits = $this->exactMatches(identifier: $identifier, objectService: $objectService, register: $register, schema: $schema);
		if (count($hits) !== 1) {
			if (count($hits) > 1) {
				$this->logger->warning(
					'Dossiq: a case number resolves to more than one case; the mail is linked to none of them',
					['app' => Application::APP_ID, 'case' => $identifier, 'matches' => count($hits)]
				);
			}

			return null;
		}

		$uuid = $this->idOf(row: $hits[0]);
		if ($uuid === '' || $this->caseAccess->hasCaseReadAccess(caseId: $uuid, user: $owner) === false) {
			return null;
		}

		$self = $this->selfOf(row: $hits[0]);
		$registerId = $this->numericId(fromRow: $self['register'] ?? null, fromConfig: $register);
		$schemaId = $this->numericId(fromRow: $self['schema'] ?? null, fromConfig: $schema);
		if ($registerId <= 0 || $schemaId <= 0) {
			$this->logger->warning(
				'Dossiq: a matched case carries no numeric register or schema id; it is not linked rather than linked to id 0',
				['app' => Application::APP_ID, 'case' => $identifier]
			);
			return null;
		}

		return ['uuid' => $uuid, 'identifier' => $identifier, 'registerId' => $registerId, 'schemaId' => $schemaId];
	}//end resolveCase()

	/**
	 * The cases the owner can see whose identifier is exactly this one.
	 *
	 * The rows are held to exact equality on `identifier` after the search: a
	 * filter that matched loosely must not become a link.
	 *
	 * @param string $identifier    The candidate identifier.
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The configured register.
	 * @param string $schema        The configured case schema.
	 *
	 * @return array<int, array<string, mixed>> The exact matches.
	 */
	private function exactMatches(string $identifier, object $objectService, string $register, string $schema): array {
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: [
					'identifier' => $identifier,
					'_limit' => 10,
					'_rbac' => true,
					'_multitenancy' => true,
					// 🔴 REQUIRED, NOT DECORATION. OpenRegister skips the
					// organisation filter for a caller whose RBAC already grants
					// the schema ("let RBAC handle access control"), and for a
					// schema with public read. A case handler holds that grant,
					// so without this flag the lookup spans every organisation.
					// Asking explicitly keeps the owner's active organisation
					// (and its parents) as the boundary in every case.
					'_multitenancy_explicit' => true,
				],
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: resolving a case number failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return [];
		}

		return array_values(
			array_filter($rows, static fn (array $row): bool => (string)($row['identifier'] ?? '') === $identifier)
		);
	}//end exactMatches()

	/**
	 * The object's uuid from an OpenRegister row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or '' when it carries none.
	 */
	private function idOf(array $row): string {
		$self = $this->selfOf(row: $row);

		return (string)($self['id'] ?? $row['id'] ?? $row['uuid'] ?? '');
	}//end idOf()

	/**
	 * The `@self` metadata block of an OpenRegister row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed> The block, or [] when the row carries none.
	 */
	private function selfOf(array $row): array {
		$self = ($row['@self'] ?? null);
		if (is_array($self) === false) {
			return [];
		}

		return $self;
	}//end selfOf()

	/**
	 * A register or schema id as the integer the leaf stores.
	 *
	 * The row's own `@self` value wins, because that is where the case lives;
	 * the configured value is used only when it is numeric. A slug is never
	 * cast, because `(int)'dossiq'` is 0 and a link to register 0 is a write to
	 * the wrong place rather than a refusal.
	 *
	 * @param mixed  $fromRow    The row's `@self` value.
	 * @param string $fromConfig The configured value.
	 *
	 * @return int The id, or 0 when neither is numeric.
	 */
	private function numericId(mixed $fromRow, string $fromConfig): int {
		$rowValue = '';
		if (is_scalar($fromRow) === true) {
			$rowValue = (string)$fromRow;
		}

		foreach ([$rowValue, $fromConfig] as $candidate) {
			if ($candidate !== '' && ctype_digit($candidate) === true) {
				return (int)$candidate;
			}
		}

		return 0;
	}//end numericId()
}//end class
