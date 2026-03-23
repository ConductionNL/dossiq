# Design: AI-Assisted Processing

## Architecture
- **Pattern**: MCP (Model Context Protocol) integration for AI capabilities
- **Backend**: Leverages OpenRegister MCP server and n8n workflows for AI processing
- **Frontend**: AI suggestion panels within case detail views
- **Key principle**: AI assists human case workers; every suggestion requires human confirmation

## Components
- AI document classification via n8n workflows
- Knowledge base Q&A (RAG) for case worker support
- Decision support suggestions surfaced in case detail
- Case routing recommendations based on case type and content
- Auto-summarization of case documents and history
