<?php

/**
 * Dossiq CaseMailOptOut.
 *
 * The opt-out half of a case mail: ask integriq, then dress the message with
 * integriq's unsubscribe link and, through OpenRegister's one helper, the
 * List-Unsubscribe headers.
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
 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-every-non-exempt-case-mail-carries-the-unsubscribe-link-req-coo-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\Service\OptOutGate;
use OCA\OpenRegister\Service\Notification\UnsubscribeHeaders;
use OCP\IL10N;
use OCP\Mail\IMessage;

/**
 * Asks before a case mail and places the link after.
 *
 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-every-non-exempt-case-mail-carries-the-unsubscribe-link-req-coo-003
 */
class CaseMailOptOut {

	/**
	 * Constructor.
	 *
	 * The header helper is optional so dossiq still loads against an
	 * OpenRegister that predates it; the body link is always placed.
	 *
	 * @param OptOutGate              $gate               Asks integriq.
	 * @param IL10N                   $l10n               Translates the link line.
	 * @param UnsubscribeHeaders|null $unsubscribeHeaders OpenRegister's header helper, when installed.
	 */
	public function __construct(
		private readonly OptOutGate $gate,
		private readonly IL10N $l10n,
		private readonly ?UnsubscribeHeaders $unsubscribeHeaders = null,
	) {
	}//end __construct()

	/**
	 * Ask integriq whether this recipient may be sent this mail about the case.
	 *
	 * @param string $recipient The address.
	 * @param string $category  The category.
	 * @param string $caseId    The case, as caseRef.
	 *
	 * @return array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null} The decision.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
	 */
	public function decide(string $recipient, string $category, string $caseId): array {
		return $this->gate->ask(recipient: $recipient, category: $category, caseRef: $caseId);
	}//end decide()

	/**
	 * Set the bodies, with the link line when there is one, and the headers.
	 *
	 * @param IMessage                 $message     The message being built.
	 * @param string                   $body        The HTML body as written.
	 * @param array<string,mixed>|null $unsubscribe integriq's link material, or null for an exempt mail.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-every-non-exempt-case-mail-carries-the-unsubscribe-link-req-coo-003
	 */
	public function dress(IMessage $message, string $body, ?array $unsubscribe): void {
		$message->setHtmlBody($this->htmlWithLink(body: $body, unsubscribe: $unsubscribe));
		$message->setPlainBody($this->plainWithLink(body: strip_tags($body), unsubscribe: $unsubscribe));
		if ($unsubscribe !== null && $this->unsubscribeHeaders !== null) {
			// Best effort (RFC 8058): OpenRegister's one helper sets the
			// headers when the mailer exposes them; the body link stays.
			$this->unsubscribeHeaders->apply(message: $message, unsubscribe: $unsubscribe);
		}
	}//end dress()

	/**
	 * The HTML body with integriq's unsubscribe line, when there is one.
	 *
	 * @param string                   $body        The body as written.
	 * @param array<string,mixed>|null $unsubscribe integriq's link material, or null.
	 *
	 * @return string The body to send.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-every-non-exempt-case-mail-carries-the-unsubscribe-link-req-coo-003
	 */
	private function htmlWithLink(string $body, ?array $unsubscribe): string {
		$url = $this->linkOf(unsubscribe: $unsubscribe);
		if ($url === null) {
			return $body;
		}

		$link = '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
			. htmlspecialchars($this->l10n->t('Unsubscribe'), ENT_QUOTES) . '</a>';

		return $body . "\n<p>" . htmlspecialchars($this->l10n->t('No longer want updates about this case?'), ENT_QUOTES)
			. ' ' . $link . '</p>';
	}//end htmlWithLink()

	/**
	 * The plain body with integriq's unsubscribe line, when there is one.
	 *
	 * @param string                   $body        The body as text.
	 * @param array<string,mixed>|null $unsubscribe integriq's link material, or null.
	 *
	 * @return string The body to send.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-every-non-exempt-case-mail-carries-the-unsubscribe-link-req-coo-003
	 */
	private function plainWithLink(string $body, ?array $unsubscribe): string {
		$url = $this->linkOf(unsubscribe: $unsubscribe);
		if ($url === null) {
			return $body;
		}

		return $body . "\n\n" . $this->l10n->t('No longer want updates about this case?') . ' '
			. $this->l10n->t('Unsubscribe') . ': ' . $url;
	}//end plainWithLink()

	/**
	 * The link out of integriq's material, when it is a usable http(s) url.
	 *
	 * The token is integriq's; dossiq mints none of its own.
	 *
	 * @param array<string,mixed>|null $unsubscribe The material.
	 *
	 * @return string|null The url.
	 */
	private function linkOf(?array $unsubscribe): ?string {
		if ($unsubscribe === null) {
			return null;
		}

		$url = trim((string)($unsubscribe['url'] ?? ''));
		if ($url === '' || preg_match('#^https?://[^\s<>"]+$#i', $url) !== 1) {
			return null;
		}

		return $url;
	}//end linkOf()
}//end class
