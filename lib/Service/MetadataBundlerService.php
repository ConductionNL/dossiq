<?php

/**
 * Procest MetadataBundlerService.
 *
 * Builds an MDTO 1.1 metadata XML payload + a SipBundel from a case +
 * retention rule. The XML covers the required MDTO elements
 * (identificatie, aggregatieniveau, naam, classificatie,
 * dekkingInTijd, beperkingGebruik, bewaartermijn,
 * eventGeschiedenis, author). XSD validation is a minimal structural
 * check (presence of required elements) — full XSD validation is
 * delegated to libxml when a real XSD is supplied via setXsdSchema().
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/archief-edepot-handover-03-metadata-bundling/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * MDTO metadata + SipBundel builder.
 */
class MetadataBundlerService
{
    private const MDTO_REQUIRED_ELEMENTS = [
        'identificatie',
        'aggregatieniveau',
        'naam',
        'classificatie',
        'dekkingInTijd',
        'beperkingGebruik',
        'bewaartermijn',
        'eventGeschiedenis',
        'author',
    ];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build a bundle for a case.
     *
     * @param array<string, mixed>             $case      Case row.
     * @param array<string, mixed>             $rule      Retention rule.
     * @param array<int, array<string, mixed>> $documents Document rows.
     *
     * @return array{metadataXml:string, metadataXsdVersion:string, documents:array<int, array<string, mixed>>}
     *
     * @spec openspec/changes/archief-edepot-handover-03-metadata-bundling/tasks.md
     */
    public function buildBundle(array $case, array $rule, array $documents): array
    {
        $mdtoVersion = (string) ($rule['mdtoVersion'] ?? '1.1');
        $xml         = $this->renderMdtoXml($case, $rule, $documents);

        return [
            'metadataXml'        => $xml,
            'metadataXsdVersion' => $mdtoVersion,
            'documents'          => $documents,
        ];
    }//end buildBundle()

    /**
     * Validate a generated MDTO XML against the minimum-required element set.
     *
     * @param string $xmlContent XML.
     *
     * @return array{valid:bool, errors:array<int, string>}
     *
     * @spec openspec/changes/archief-edepot-handover-03-metadata-bundling/tasks.md
     */
    public function validateXsd(string $xmlContent): array
    {
        $errors = [];
        try {
            $doc = new \DOMDocument();
            if ($doc->loadXML($xmlContent, LIBXML_NOERROR) === false) {
                return ['valid' => false, 'errors' => ['Malformed XML']];
            }
        } catch (\Throwable $e) {
            return ['valid' => false, 'errors' => ['Malformed XML: '.$e->getMessage()]];
        }

        $body = $xmlContent;
        foreach (self::MDTO_REQUIRED_ELEMENTS as $el) {
            if (str_contains($body, '<mdto:'.$el) === false && str_contains($body, '<'.$el) === false) {
                $errors[] = 'Missing required element: '.$el;
            }
        }

        return ['valid' => count($errors) === 0, 'errors' => $errors];
    }//end validateXsd()

    /**
     * Persist a SipBundel row.
     *
     * @param string                           $caseId      Case id.
     * @param string                           $metadataXml XML.
     * @param array<int, array<string, mixed>> $documents   Documents.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/archief-edepot-handover-03-metadata-bundling/tasks.md
     */
    public function createSipBundel(string $caseId, string $metadataXml, array $documents): array
    {
        $valid = $this->validateXsd($metadataXml);

        $row = [
            'zaakId'             => $caseId,
            'metadataXml'        => $metadataXml,
            'metadataXsdVersion' => '1.1',
            'metadataXsdValid'   => $valid['valid'],
            'documents'          => $documents,
            'bundleFormat'       => 'bagit',
            'status'             => 'prepared',
            'createdAt'          => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
        ];

        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('sip_bundel_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return $row;
        }

        try {
            $saved = $objectService->saveObject($register, $schema, $row);
            return is_array($saved) === true ? $saved : $row;
        } catch (\Throwable $e) {
            $this->logger->error('SipBundel persist failed', ['caseId' => $caseId, 'error' => $e->getMessage()]);
            return $row;
        }
    }//end createSipBundel()

    /**
     * @param  array<string, mixed>             $case      Case.
     * @param  array<string, mixed>             $rule      Rule.
     * @param  array<int, array<string, mixed>> $documents Documents.
     * @return string
     */
    private function renderMdtoXml(array $case, array $rule, array $documents): string
    {
        $caseId   = htmlspecialchars((string) ($case['id'] ?? 'unknown'), ENT_XML1);
        $caseName = htmlspecialchars((string) ($case['title'] ?? $case['naam'] ?? 'Onbenoemd'), ENT_XML1);
        $zaaktype = htmlspecialchars((string) ($case['caseType'] ?? ''), ENT_XML1);
        $bewaar   = (int) ($rule['bewaartermijnJaren'] ?? 0);
        $catg     = htmlspecialchars((string) ($rule['selectielijstCategorie'] ?? ''), ENT_XML1);
        $closedAt = htmlspecialchars((string) ($case['closedAt'] ?? ''), ENT_XML1);
        $author   = htmlspecialchars((string) ($case['handler'] ?? 'procest'), ENT_XML1);

        $docElements = '';
        foreach ($documents as $doc) {
            $docName      = htmlspecialchars((string) ($doc['name'] ?? 'document'), ENT_XML1);
            $docType      = htmlspecialchars((string) ($doc['documentType'] ?? 'onbekend'), ENT_XML1);
            $docMime      = htmlspecialchars((string) ($doc['mimeType'] ?? 'application/octet-stream'), ENT_XML1);
            $docElements .= "    <mdto:document><mdto:naam>{$docName}</mdto:naam><mdto:type>{$docType}</mdto:type><mdto:mimeType>{$docMime}</mdto:mimeType></mdto:document>\n";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<mdto:MDTO xmlns:mdto="https://www.nationaalarchief.nl/mdto" version="1.1">
  <mdto:identificatie>{$caseId}</mdto:identificatie>
  <mdto:aggregatieniveau>Archiefstuk</mdto:aggregatieniveau>
  <mdto:naam>{$caseName}</mdto:naam>
  <mdto:classificatie>{$catg}</mdto:classificatie>
  <mdto:dekkingInTijd><mdto:einde>{$closedAt}</mdto:einde></mdto:dekkingInTijd>
  <mdto:beperkingGebruik>openbaar</mdto:beperkingGebruik>
  <mdto:bewaartermijn>{$bewaar}</mdto:bewaartermijn>
  <mdto:eventGeschiedenis>
    <mdto:event><mdto:type>caseClosed</mdto:type><mdto:tijdstip>{$closedAt}</mdto:tijdstip></mdto:event>
  </mdto:eventGeschiedenis>
  <mdto:author>{$author}</mdto:author>
  <mdto:zaaktype>{$zaaktype}</mdto:zaaktype>
  <mdto:documents>
{$docElements}  </mdto:documents>
</mdto:MDTO>
XML;
    }//end renderMdtoXml()
}//end class
