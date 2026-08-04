<?php

/**
 * Procest AI prompt factory.
 *
 * Every prompt template procest sends to the configured AI model, in one place:
 * document classification, structured-data extraction, knowledge-base Q&A,
 * summarisation, routing and next-step suggestions. Each returns the prompt
 * text only — no model call, no config, no I/O.
 *
 * Split out of {@see \OCA\Procest\Service\AiService} so the wording of what we
 * ask a model (and the JSON shape we demand back) is reviewable as a set,
 * without the surrounding orchestration.
 *
 * @category Service
 * @package  OCA\Procest\Service\Ai
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/ai-assistance/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Ai;

/**
 * Builds the prompt text for each AI-assisted operation.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/ai-assistance/spec.md
 */
class AiPromptFactory
{
    /**
     * Build a classification prompt for the AI model.
     *
     * @param string $caseId     The case ID.
     * @param string $documentId The document ID.
     *
     * @return string The classification prompt.
     *
     * @spec openspec/specs/ai-assistance/spec.md
     */
    public function classification(string $caseId, string $documentId): string
    {
        return 'Classify the following document for case '.$caseId
            .'. Document ID: '.$documentId
            .'. Return JSON with fields: documentType (string), confidence (number 0-1), '
            .'metadata (object with date, sender, subject).';
    }//end classification()

    /**
     * Build a data extraction prompt for the AI model.
     *
     * @param string      $caseId     The case ID.
     * @param string|null $documentId Optional document ID.
     *
     * @return string The extraction prompt.
     *
     * @spec openspec/specs/ai-assistance/spec.md
     */
    public function extraction(string $caseId, ?string $documentId): string
    {
        $prompt = 'Extract structured data from documents in case '.$caseId.'.';
        if ($documentId !== null) {
            $prompt .= ' Focus on document '.$documentId.'.';
        }

        $prompt .= ' Return JSON with fields: array of {name, value, confidence (0-1), source}.';

        return $prompt;
    }//end extraction()

    /**
     * Build a Q&A prompt with case context.
     *
     * @param string $caseId   The case ID.
     * @param string $question The user's question.
     *
     * @return string The Q&A prompt.
     *
     * @spec openspec/specs/ai-assistance/spec.md
     */
    public function question(string $caseId, string $question): string
    {
        return 'Answer the following question in the context of case '.$caseId
            .'. Question: '.$question
            .'. Return JSON with fields: answer (string), sources (array of {document, page, quote}), '
            .'confidence (number 0-1). '
            .'If no relevant information is found, return: '
            .'{"answer": "Geen relevante informatie gevonden in de kennisbank", "sources": [], "confidence": 0}.';
    }//end question()

    /**
     * Build a summarization prompt.
     *
     * @param string      $caseId     The case ID.
     * @param string      $type       Summary type.
     * @param string|null $documentId Optional document ID.
     *
     * @return string The summary prompt.
     *
     * @spec openspec/specs/ai-assistance/spec.md
     */
    public function summary(string $caseId, string $type, ?string $documentId): string
    {
        $prompt = 'Generate a '.$type.' summary for case '.$caseId.'.';
        if ($type === 'document' && $documentId !== null) {
            $prompt .= ' Summarize document '.$documentId.'.';
        }

        $prompt .= ' Return JSON with field: summary (string, 3-5 sentences in Dutch).';

        return $prompt;
    }//end summary()

    /**
     * Build a routing suggestion prompt.
     *
     * @param string $caseId The case ID.
     *
     * @return string The routing prompt.
     *
     * @spec openspec/specs/ai-assistance/spec.md
     */
    public function routing(string $caseId): string
    {
        return 'Suggest the best case worker for case '.$caseId
            .' based on expertise and current workload. '
            .'Return JSON with fields: suggestions (array of {userId, name, reason, confidence}).';
    }//end routing()

    /**
     * Build a next-step suggestion prompt.
     *
     * @param string $caseId The case ID.
     *
     * @return string The next-step prompt.
     *
     * @spec openspec/specs/ai-assistance/spec.md
     */
    public function nextStep(string $caseId): string
    {
        return 'Analyze the current state of case '.$caseId
            .' and suggest what the case worker should do next. '
            .'Return JSON with fields: suggestions (array of {action, reason, priority}).';
    }//end nextStep()
}//end class
