<?php

/**
 * Dossiq Junk Rules
 *
 * A junk verdict that cannot be read is a junk verdict nobody can argue with,
 * so every rule here has a name, a readable description and an administrator
 * who can change it (design D-9, D-10).
 *
 * The rules an administrator writes are one per line: a header name, a colon
 * and a substring, or a bare substring which is read against the subject. That
 * is deliberately smaller than a scoring engine. A municipality that wants
 * scoring runs it in Sieve or at its own mail server, where it belongs, and
 * dossiq's pipeline then never sees the message at all.
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The rules that classify a message as junk, and their names.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
class JunkRules {

	/**
	 * The app-config key holding the administered rules.
	 */
	public const RULES_KEY = 'email_intake_junk_rules';

	/**
	 * The rule that reads the mail server's own spam verdict.
	 */
	public const SPAM_FLAG_RULE = 'mail-server-marked-it-spam';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Instance configuration.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Every rule, shipped and administered, as an administrator reads them.
	 *
	 * @return array<int, array{name: string, description: string}> The rules.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function rules(): array {
		$rules = [
			[
				'name' => self::SPAM_FLAG_RULE,
				'description' => 'The receiving mail server set X-Spam-Flag: YES on the message.',
			],
		];

		foreach ($this->administeredRules() as $index => $rule) {
			$rules[] = [
				'name' => 'administered-' . ($index + 1),
				'description' => 'An administrator wrote: ' . $rule,
			];
		}

		return $rules;
	}//end rules()

	/**
	 * The rule that classifies a message as junk, if one does.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return array{name: string, description: string}|null The rule, or null.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function matching(InboundMessage $message): ?array {
		if (strtolower(trim($message->header(name: 'x-spam-flag'))) === 'yes') {
			return [
				'name' => self::SPAM_FLAG_RULE,
				'description' => 'The receiving mail server set X-Spam-Flag: YES on the message.',
			];
		}

		foreach ($this->administeredRules() as $index => $rule) {
			if ($this->matches(rule: $rule, message: $message) === false) {
				continue;
			}

			return [
				'name' => 'administered-' . ($index + 1),
				'description' => 'An administrator wrote: ' . $rule,
			];
		}

		return null;
	}//end matching()

	/**
	 * Whether one administered rule matches a message.
	 *
	 * @param string         $rule    The rule, `header: substring` or a bare substring.
	 * @param InboundMessage $message The message.
	 *
	 * @return boolean True when it matches.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function matches(string $rule, InboundMessage $message): bool {
		$position = strpos($rule, ':');
		if ($position === false || $position === 0) {
			return (stripos($message->subject, trim($rule)) !== false);
		}

		$header = trim(substr($rule, 0, $position));
		$needle = trim(substr($rule, ($position + 1)));
		if ($needle === '') {
			return $message->hasHeader(name: $header);
		}

		return (stripos($message->header(name: $header), $needle) !== false);
	}//end matches()

	/**
	 * The rules an administrator wrote, one per line.
	 *
	 * @return array<int, string> The rules.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function administeredRules(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::RULES_KEY, '');
		$lines = preg_split('/\R/', trim($raw));
		if ($lines === false) {
			return [];
		}

		return array_values(array_filter(array_map('trim', $lines)));
	}//end administeredRules()
}//end class
