<?php

/**
 * Dossiq Sender Authentication Verdict
 *
 * The four results dossiq records on every inbound message: SPF, DKIM, DMARC
 * and threading (design D-6).
 *
 * 🔴 NEXTCLOUD MAIL HAS NO SPF AND NO DMARC CHECKER. The tree carries
 * `DkimService` and a `ReplyToCheck` and nothing else, so dossiq reads the
 * `Authentication-Results` header off the raw source and records what the
 * RECEIVING server found. That is a real limitation stated rather than papered
 * over: dossiq is not doing DNS lookups here, it is reading the verdict of the
 * machine that accepted the message.
 *
 * 🔴 AND AN ABSENT HEADER IS `unavailable`, NEVER `pass`. A check nobody made
 * and a check that succeeded are different facts, and once they are collapsed
 * nothing downstream can tell them apart. This is the same lesson as a green
 * test that never ran.
 *
 * WHICH `Authentication-Results` HEADER IS READ. A message can carry several,
 * one per relay that checked it, oldest LAST. The topmost is the one the
 * receiving server that actually accepted the message wrote, so that is the one
 * whose verdicts are recorded. Reading a lower one would report what some
 * upstream relay found about a hop we do not control, which a forger can add
 * themselves.
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

/**
 * Reaches the four authentication results for one message.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) — the static calls here are named
 *  constructors and value-object factories (`InboundMessage::fromRow()`,
 *  `FilterVerdict::accept()`, `AuthenticationVerdict::unknown()`), which hold no
 *  state and exist so a caller cannot build a half-built value.
 */
class AuthenticationVerdict {

	/**
	 * The header the receiving server writes its verdicts into.
	 */
	public const HEADER = 'authentication-results';

	/**
	 * Constructor.
	 *
	 * @param MailGatewayInterface $gateway   The mail gateway, for the DKIM verdict.
	 * @param ThreadingCheck       $threading The threading claim checker.
	 */
	public function __construct(
		private readonly MailGatewayInterface $gateway,
		private readonly ThreadingCheck $threading,
	) {
	}//end __construct()

	/**
	 * The four results for one message.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return array{spf: string, dkim: string, dmarc: string, threading: string} The results.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function forMessage(InboundMessage $message): array {
		$header = $this->receivingServerHeader(message: $message);

		return [
			'spf' => $this->methodResult(header: $header, method: 'spf'),
			'dkim' => $this->gateway->dkimResult($message->source),
			'dmarc' => $this->methodResult(header: $header, method: 'dmarc'),
			'threading' => $this->threading->resultFor(message: $message),
		];
	}//end forMessage()

	/**
	 * Whether any of the four results actively failed.
	 *
	 * `none` and `unavailable` are absences of evidence and neither is one.
	 *
	 * @param array<string, string> $results The four results.
	 *
	 * @return boolean True when at least one is `fail`.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function hasFailure(array $results): bool {
		foreach ($results as $result) {
			if (AuthenticationResult::isFailure(value: $result) === true) {
				return true;
			}
		}

		return false;
	}//end hasFailure()

	/**
	 * The results with nothing known about them, for a message we cannot read.
	 *
	 * Published rather than assembled at each call site, because the one thing
	 * every caller must not do is invent a `pass`.
	 *
	 * @return array{spf: string, dkim: string, dmarc: string, threading: string} The results.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public static function unknown(): array {
		return [
			'spf' => AuthenticationResult::UNAVAILABLE,
			'dkim' => AuthenticationResult::UNAVAILABLE,
			'dmarc' => AuthenticationResult::UNAVAILABLE,
			'threading' => AuthenticationResult::UNAVAILABLE,
		];
	}//end unknown()

	/**
	 * The topmost `Authentication-Results` value, which is the receiving one.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return string The header value, or '' when there is none.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function receivingServerHeader(InboundMessage $message): string {
		$value = $message->header(name: self::HEADER);
		if ($value === '') {
			return '';
		}

		// Repeats are joined newline-separated by the header parser, oldest
		// last, so the first line is the receiving server's.
		$lines = explode("\n", $value);

		return trim($lines[0]);
	}//end receivingServerHeader()

	/**
	 * One method's result out of an `Authentication-Results` value.
	 *
	 * @param string $header The header value.
	 * @param string $method `spf` or `dmarc`.
	 *
	 * @return string One of the {@see AuthenticationResult} values.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function methodResult(string $header, string $method): string {
		if (trim($header) === '') {
			return AuthenticationResult::UNAVAILABLE;
		}

		$pattern = '/(?<![\w.-])' . preg_quote($method, '/') . '\s*=\s*([a-z]+)/i';
		if (preg_match($pattern, $header, $matches) !== 1) {
			// The header exists and says nothing about this method, which is
			// still a check nobody made.
			return AuthenticationResult::UNAVAILABLE;
		}

		return AuthenticationResult::fromToken(raw: $matches[1]);
	}//end methodResult()
}//end class
