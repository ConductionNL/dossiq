<?php

/**
 * Files a document Filinq generated in a case's dossier.
 *
 * Filinq owns document generation and stores what it renders in the acting
 * user's Files. dossiq owns the case dossier, which knows a document by its
 * informatieobject: the type, the confidentiality, who it went to. This class
 * is the one place a generated file becomes that record, for both roads a
 * generation takes: a flow step (through {@see \OCA\Dossiq\Listener\DocumentGeneratedListener})
 * and the Generate document button (through
 * {@see \OCA\Dossiq\Service\CaseDocumentGenerationService}).
 *
 * WHAT THE METADATA SAYS. The step or the button passes, through Filinq's
 * untouched `metadata`, what the retired dossiq handlers used to decide
 * themselves: `informatieobjecttype` (required, the dossier refuses a document
 * without one), `vertrouwelijkheidaanduiding` or `classification` (optional; the
 * type's default applies otherwise), `direction` (defaults to outgoing), and
 * `addressees`: `case` means "whoever the case says a letter goes to", the rule
 * {@see AddressesTheCase} carries, and a list names the parties outright.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Service\Actions\AddressesTheCase;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Turns a generated file into an informatieobject on the case.
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */
class GeneratedDocumentFiler {
	use AddressesTheCase;

	/**
	 * The metadata key that marks a request whose caller files the document itself.
	 *
	 * The Generate document button files the document so it can answer with
	 * the informatieobject it made; the listener leaves such a document alone,
	 * or the case would get it twice.
	 *
	 * @var string
	 */
	public const FILED_BY_CALLER = 'dossiqFiledByCaller';

	/**
	 * The metadata key naming the document type.
	 *
	 * @var string
	 */
	public const TYPE = 'informatieobjecttype';

	/**
	 * Constructor.
	 *
	 * @param ZaakdossierService $dossier     The case dossier.
	 * @param IRootFolder        $rootFolder  Reads the file Filinq stored.
	 * @param IUserSession       $userSession Supplies the author.
	 * @param ContainerInterface $container   Resolves the party writer, for {@see AddressesTheCase}.
	 */
	public function __construct(
		private readonly ZaakdossierService $dossier,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether metadata asks for the document to be filed on the case at all.
	 *
	 * @param array<string, mixed> $metadata The request's metadata.
	 *
	 * @return bool True when it names a document type.
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function wantsFiling(array $metadata): bool {
		return trim((string)($metadata[self::TYPE] ?? '')) !== '';
	}//end wantsFiling()

	/**
	 * File one generated file on a case.
	 *
	 * @param string               $caseId   The case.
	 * @param int                  $fileId   The file Filinq stored.
	 * @param string               $title    The document's title.
	 * @param string               $mime     The file's type.
	 * @param array<string, mixed> $metadata What the request passed through.
	 *
	 * @return array<string, mixed> The informatieobject summary.
	 *
	 * @throws RuntimeException When the metadata names no type or the file cannot be read.
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function file(string $caseId, int $fileId, string $title, string $mime, array $metadata): array {
		$type = trim((string)($metadata[self::TYPE] ?? ''));
		if ($type === '') {
			throw new RuntimeException('The generated document names no document type, so the case dossier cannot file it.');
		}

		$file = $this->readFile(fileId: $fileId);

		$record = [
			'title' => $title,
			'informatieobjecttype' => $type,
			'direction' => (string)($metadata['direction'] ?? 'outgoing'),
			'auteur' => $this->currentAuthor(),
			'format' => $mime,
			'recipients' => $this->recipients(caseId: $caseId, metadata: $metadata),
		];

		$classification = trim((string)($metadata['vertrouwelijkheidaanduiding'] ?? ($metadata['classification'] ?? '')));
		if ($classification !== '') {
			$record['vertrouwelijkheidaanduiding'] = $classification;
		}

		return $this->dossier->uploadDocument($caseId, $file->getName(), $file->getContent(), $record);
	}//end file()

	/**
	 * Who the document is addressed to.
	 *
	 * @param string               $caseId   The case.
	 * @param array<string, mixed> $metadata What the request passed through.
	 *
	 * @return array<int, string> The addressed parties.
	 */
	private function recipients(string $caseId, array $metadata): array {
		$addressees = ($metadata['addressees'] ?? null);
		if ($addressees === 'case') {
			return $this->addressedParties(caseId: $caseId);
		}

		if (is_array($addressees) === false) {
			return [];
		}

		return array_values(array_filter(array_map('strval', $addressees), static fn (string $party): bool => $party !== ''));
	}//end recipients()

	/**
	 * The file Filinq stored, by id.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return File The file.
	 *
	 * @throws RuntimeException When no file answers to the id.
	 */
	private function readFile(int $fileId): File {
		foreach ($this->rootFolder->getById($fileId) as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}

		throw new RuntimeException('The generated document (file ' . $fileId . ') could not be read to file it on the case.');
	}//end readFile()

	/**
	 * The signed-in user's display name, for the document's author.
	 *
	 * Empty on a run with no session, which the schema allows: an empty
	 * author is honest where a fabricated one is not.
	 *
	 * @return string The display name, or empty.
	 */
	private function currentAuthor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getDisplayName();
	}//end currentAuthor()
}//end class
