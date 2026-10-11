<?php

/**
 * Dossiq mail header reader
 *
 * Reads the header fields of a collected e-mail into the shape
 * `provenance.mail` stores (REQ-WRR-003): sender, recipients, cc, date and
 * message id, addresses lower-cased. It works for a document collected onto
 * any case type; nothing in it knows which procedure the case follows.
 *
 * The parse itself is OpenRegister's (`TextExtractionService::parseEmlStructured`,
 * which owns the MIME parser). This class only decides what is a mail, turns
 * the parsed headers into bare addresses, and marks a mail whose headers
 * cannot be read as `unreadable` so a report counts it instead of leaving it out.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-collected-mail-keeps-its-header-fields-req-wrr-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use DateTimeInterface;
use OCA\Dossiq\AppInfo\Application;
use OCP\Files\File;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a collected mail file into its `provenance.mail` fields.
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-collected-mail-keeps-its-header-fields-req-wrr-003
 */
class MailHeaderReader {

	/**
	 * OpenRegister's text extraction service, which parses an `.eml`.
	 */
	private const EXTRACTION = 'OCA\OpenRegister\Service\TextExtractionService';

	/**
	 * File extensions that are a mail, lower-case.
	 */
	private const MAIL_EXTENSIONS = ['eml', 'msg'];

	/**
	 * MIME types that are a mail.
	 */
	private const MAIL_TYPES = ['message/rfc822', 'application/vnd.ms-outlook'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's extraction service by name.
	 * @param LoggerInterface    $logger    Logs a parse failure, without header content.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a file is an e-mail, by its extension or MIME type.
	 *
	 * @param string $fileName The file name.
	 * @param string $mimeType The MIME type, '' when unknown.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-collected-mail-keeps-its-header-fields-req-wrr-003
	 */
	public function isMail(string $fileName, string $mimeType = ''): bool {
		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		if (in_array($extension, self::MAIL_EXTENSIONS, true) === true) {
			return true;
		}

		return in_array(strtolower(trim($mimeType)), self::MAIL_TYPES, true);
	}//end isMail()

	/**
	 * The header fields of a mail file, as `provenance.mail` stores them.
	 *
	 * An `.msg` is Outlook's binary format, which the parser does not read, so
	 * it answers unreadable, as does a mail whose headers name no sender.
	 *
	 * @param File $file The mail file.
	 *
	 * @return array<string, mixed> `{from, to, cc, date?, messageId}`, or `{unreadable: true}`.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-collected-mail-keeps-its-header-fields-req-wrr-003
	 */
	public function read(File $file): array {
		$unreadable = ['unreadable' => true];
		if (strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)) === 'msg') {
			return $unreadable;
		}

		$headers = $this->parsedHeaders(file: $file);
		if ($headers === null) {
			return $unreadable;
		}

		$from = $this->addresses(value: ($headers['from'] ?? null));
		if ($from === []) {
			return $unreadable;
		}

		$mail = [
			'from' => $from[0],
			'to' => $this->addresses(value: ($headers['to'] ?? null)),
			'cc' => $this->addresses(value: ($headers['cc'] ?? null)),
			'messageId' => trim((string)($headers['messageId'] ?? ''), " \t<>"),
		];

		$date = ($headers['date'] ?? null);
		if ($date instanceof DateTimeInterface) {
			$mail['date'] = $date->format(DateTimeInterface::ATOM);
		}

		return $mail;
	}//end read()

	/**
	 * The headers OpenRegister parsed from the file, or null when it could not.
	 *
	 * @param File $file The mail file.
	 *
	 * @return array<string, mixed>|null
	 */
	private function parsedHeaders(File $file): ?array {
		try {
			if ($this->container->has(self::EXTRACTION) === false) {
				return null;
			}

			$extraction = $this->container->get(self::EXTRACTION);
			if (is_object($extraction) === false || method_exists($extraction, 'parseEmlStructured') === false) {
				return null;
			}

			$structure = $extraction->parseEmlStructured($file);
		} catch (Throwable $e) {
			// The parser's message can carry header fragments; log only its class.
			$this->logger->info(
				'Dossiq: the headers of a collected mail could not be read, so it is marked unreadable',
				['app' => Application::APP_ID, 'fileId' => $file->getId(), 'error' => get_class($e)]
			);
			return null;
		}

		$headers = ($structure->headers ?? null);
		if (is_array($headers) === false) {
			return null;
		}

		return $headers;
	}//end parsedHeaders()

	/**
	 * Bare, lower-cased addresses from a header value or a list of them.
	 *
	 * `"Jansen, A." <A.Jansen@Gemeente.nl>` becomes `a.jansen@gemeente.nl`; a
	 * token holding no address is left out.
	 *
	 * @param mixed $value One header value or a list of address tokens.
	 *
	 * @return list<string>
	 */
	private function addresses(mixed $value): array {
		$tokens = [];
		if (is_array($value) === true) {
			$tokens = $value;
		} elseif (is_string($value) === true) {
			$tokens = [$value];
		}

		$addresses = [];
		foreach ($tokens as $token) {
			if (is_string($token) === false) {
				continue;
			}

			$address = $token;
			if (preg_match('/<([^<>]+)>/', $token, $match) === 1) {
				$address = $match[1];
			}

			$address = strtolower(trim($address));
			if (filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
				$addresses[] = $address;
			}
		}

		return array_values(array_unique($addresses));
	}//end addresses()
}//end class
