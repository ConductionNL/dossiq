<?php

/**
 * A saved `.eml` or `.msg` on a case, read as a message rather than stored
 * whole.
 *
 * Drop an Outlook `.msg` on a case today and you get a file nobody can read
 * without Outlook. integriq#2052 ships the reader: `MessageParser` takes the
 * bytes and the name, and the BYTES decide which reader runs rather than the
 * extension, so a `.msg` renamed `.eml` still parses as what it is.
 *
 * 🔴 DOSSIQ KEEPS NO SECOND PARSER. Reading MS-CFB in two apps is two answers
 * to one question, and the one in integriq is tested. This class hands the
 * bytes over and files what comes back; it never looks inside them.
 *
 * 🔴 IT CALLS INTEGRIQ IN PROCESS, NOT OVER HTTP. integriq's
 * `POST /api/mail-intake/import` is the same parser behind a session and a
 * multipart upload, and reaching it from PHP would mean forwarding the
 * caller's session to our own server. `FleetAppId::getService()` resolves the
 * parser itself, under whichever namespace this instance's integriq has, and
 * the import endpoint stays what a browser uses.
 *
 * 🔴 A MISSING APP IS NOT A PARSE THAT SUCCEEDED. Without integriq the file
 * is KEPT, untouched, and the reason is returned in words. An empty message
 * filed beside an unreadable attachment would be worse than the attachment
 * alone, because the case would then claim somebody had read it.
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
 * @spec openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands a saved mail file to integriq's reader and files the message.
 *
 * @spec openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md
 */
class SavedMailImport {

	/**
	 * integriq's parser, relative to integriq's own namespace.
	 *
	 * Resolved through FleetAppId rather than written as an FQN: integriq's id
	 * and namespace are both moving, and a literal against a dead one answers
	 * false without erroring, which would make this a silent no-op instead of
	 * the refusal it should be.
	 */
	private const PARSER = 'Service\\Mail\\MessageParser';

	/**
	 * The message was read and filed on the case.
	 */
	public const OUTCOME_IMPORTED = 'imported';

	/**
	 * The file is still there and nobody could read it.
	 */
	public const OUTCOME_KEPT = 'kept';

	/**
	 * Constructor.
	 *
	 * @param CaseEmailRepository $cases      Files the parsed message on the case.
	 * @param IAppManager         $appManager Answers whether integriq is present.
	 * @param ContainerInterface  $container  Resolves integriq's parser.
	 * @param LoggerInterface     $logger     Logger.
	 */
	public function __construct(
		private readonly CaseEmailRepository $cases,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read one saved mail file as a message on a case.
	 *
	 * @param string $caseId   The case the file is on.
	 * @param string $fileName The file's name, for the parser's hint and the reason.
	 * @param string $raw      The file's bytes.
	 *
	 * @return array{outcome: string, reason: string, subject: string, from: string, receivedAt: string}
	 *         What happened, and what was read.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is a stateless resolver.
	 *
	 * @spec openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md
	 */
	public function import(string $caseId, string $fileName, string $raw): array {
		if (trim($caseId) === '') {
			return $this->kept(reason: 'This file is not on a case, so there is nothing to file a message on.');
		}

		if ($raw === '') {
			return $this->kept(reason: 'The file is empty, so there is no message in it to read.');
		}

		if (FleetAppId::isEnabledForUser(appManager: $this->appManager, canonical: 'integriq') === false) {
			return $this->kept(
				reason: 'Integriq is not installed, and it is what reads a saved mail file. '
					. 'The file is unchanged on this case.'
			);
		}

		$parser = FleetAppId::getService(
			container: $this->container,
			canonical: 'integriq',
			relative: self::PARSER
		);
		if ($parser === null || method_exists($parser, 'parse') === false) {
			return $this->kept(
				reason: 'Integriq is installed but carries no mail reader, so this version cannot '
					. 'read a saved mail file. The file is unchanged on this case.'
			);
		}

		try {
			$parsed = $parser->parse($fileName, $raw);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: a saved mail file could not be read',
				['case' => $caseId, 'file' => $fileName, 'error' => $e->getMessage()]
			);

			return $this->kept(
				reason: 'This file could not be read as a message. It is unchanged on this case.'
			);
		}

		$subject = $this->stringFrom(parsed: $parsed, method: 'getSubject');
		$from = $this->stringFrom(parsed: $parsed, method: 'getFrom');

		$this->cases->recordReceivedEmail(
			caseId: $caseId,
			from: $from,
			recipient: $this->firstRecipient(parsed: $parsed),
			subject: ($subject === '' ? $fileName : $subject),
			body: $this->stringFrom(parsed: $parsed, method: 'getBodyText'),
			inReplyTo: ''
		);

		// 🔴 THE ORIGINAL STAYS. It is the record: a parsed message is a
		// reading of a file, and an archive that kept only the reading cannot
		// answer a question about the bytes later on. Nothing here deletes or
		// moves the node.
		return [
			'outcome' => self::OUTCOME_IMPORTED,
			'reason' => '',
			'subject' => ($subject === '' ? $fileName : $subject),
			'from' => $from,
			'receivedAt' => $this->stringFrom(parsed: $parsed, method: 'getReceivedAt'),
		];
	}//end import()

	/**
	 * The file stays, and the reason says why nothing was read.
	 *
	 * @param string $reason The sentence a handler reads.
	 *
	 * @return array{outcome: string, reason: string, subject: string, from: string, receivedAt: string} The outcome.
	 */
	private function kept(string $reason): array {
		return [
			'outcome' => self::OUTCOME_KEPT,
			'reason' => $reason,
			'subject' => '',
			'from' => '',
			'receivedAt' => '',
		];
	}//end kept()

	/**
	 * The first recipient the parser found, or ''.
	 *
	 * @param object $parsed The parsed message.
	 *
	 * @return string The recipient.
	 */
	private function firstRecipient(object $parsed): string {
		if (method_exists($parsed, 'getTo') === false) {
			return '';
		}

		$to = $parsed->getTo();
		if (is_array($to) === false) {
			return '';
		}

		foreach ($to as $recipient) {
			$value = trim((string)$recipient);
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}//end firstRecipient()

	/**
	 * Read a string off the parsed message, whatever it answers.
	 *
	 * Duck-typed for the same reason the listeners are: this is another app's
	 * class and a type hint on it is a fatal on an instance that has no
	 * integriq.
	 *
	 * @param object $parsed The parsed message.
	 * @param string $method The getter.
	 *
	 * @return string The value, or ''.
	 */
	private function stringFrom(object $parsed, string $method): string {
		if (method_exists($parsed, $method) === false) {
			return '';
		}

		$value = $parsed->$method();

		return (is_scalar($value) === true) ? trim((string)$value) : '';
	}//end stringFrom()
}//end class
