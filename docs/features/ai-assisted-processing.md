# AI-Assisted Processing

The AI-assisted processing feature leverages large language models and AI services to assist case workers with case analysis, document processing, and decision support.

## Overview

AI-assisted processing integrates with Nextcloud's AI infrastructure (ExApps like Ollama and OpenWebUI) to provide intelligent assistance during case handling.

## Planned Features

- **Document summarization** -- Automatically summarize long documents attached to cases.
- **Classification** -- Auto-classify incoming cases by type based on content analysis.
- **Anonymization** -- Redact personal data from documents for WOO publication using Presidio.
- **Suggested responses** -- Generate draft responses based on case content and templates.
- **Similar case finding** -- Find historically similar cases to inform decision-making.
- **Deadline risk assessment** -- Predict which cases are at risk of exceeding their deadline.
- **Translation** -- Translate case documents for multilingual communication.
- **Data extraction** -- Extract structured data from unstructured documents.

## AI Infrastructure

Procest leverages the following AI services (available via Docker profiles):
- **Ollama** -- Local LLM inference.
- **OpenWebUI** -- Chat interface for AI interaction.
- **Presidio** -- PII detection and anonymization.
- **TGI** -- Text Generation Inference for high-performance LLM serving.

## Status

This feature is defined in the spec at `openspec/specs/ai-assisted-processing/spec.md` and is under development.
