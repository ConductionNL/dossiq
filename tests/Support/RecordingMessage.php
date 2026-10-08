<?php

/**
 * A mail message the tests can read back.
 *
 * It keeps what was set on it and, like Nextcloud's own `OC\Mail\Message`,
 * exposes `getSymfonyEmail()`, so OpenRegister's UnsubscribeHeaders helper can
 * set its headers on it. The headers object keeps the text headers in order,
 * which is all the helper uses (`has`, `remove`, `addTextHeader`).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
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

namespace OCA\Dossiq\Tests\Support;

use OCP\Mail\IAttachment;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMessage;

/**
 * Keeps what a sender set.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) -- it mirrors IMessage.
 */
final class RecordingMessage implements IMessage {

	public string $subject = '';

	public string $plainBody = '';

	public string $htmlBody = '';

	/**
	 * @var array<mixed>
	 */
	public array $to = [];

	/**
	 * Text headers by name.
	 *
	 * @var array<string, string>
	 */
	public array $headers = [];

	public function setSubject(string $subject): IMessage {
		$this->subject = $subject;
		return $this;
	}

	public function setPlainBody(string $body): IMessage {
		$this->plainBody = $body;
		return $this;
	}

	public function setHtmlBody(string $body): IMessage {
		$this->htmlBody = $body;
		return $this;
	}

	public function attach(IAttachment $attachment): IMessage {
		return $this;
	}

	public function attachInline(string $body, string $name, ?string $contentType = null): IMessage {
		return $this;
	}

	public function setFrom(array $addresses): IMessage {
		return $this;
	}

	public function setReplyTo(array $addresses): IMessage {
		return $this;
	}

	public function setTo(array $recipients): IMessage {
		$this->to = $recipients;
		return $this;
	}

	public function setCc(array $recipients): IMessage {
		return $this;
	}

	public function setBcc(array $recipients): IMessage {
		return $this;
	}

	public function useTemplate(IEMailTemplate $emailTemplate): IMessage {
		return $this;
	}

	public function setAutoSubmitted(string $value): IMessage {
		return $this;
	}

	/**
	 * The mail object, as `OC\Mail\Message::getSymfonyEmail()` hands it out.
	 *
	 * @return object Something with `getHeaders()`.
	 */
	public function getSymfonyEmail(): object {
		$message = $this;
		return new class($message) {
			public function __construct(private RecordingMessage $message) {
			}

			public function getHeaders(): object {
				$message = $this->message;
				return new class($message) {
					public function __construct(private RecordingMessage $message) {
					}

					public function has(string $name): bool {
						return isset($this->message->headers[$name]);
					}

					public function remove(string $name): void {
						unset($this->message->headers[$name]);
					}

					public function addTextHeader(string $name, string $value): void {
						$this->message->headers[$name] = $value;
					}
				};
			}
		};
	}//end getSymfonyEmail()
}//end class
