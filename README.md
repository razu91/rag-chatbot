# Laravel RAG Chatbot

A Retrieval-Augmented Generation (RAG) chatbot built with **Laravel 13, PHP, PostgreSQL, pgvector, and Google Gemini**. The project demonstrates document ingestion, vector embeddings, tenant-scoped semantic search, and context-grounded AI responses through a REST API.

> **Project status:** Active development. Core ingestion, semantic search, and chat API components are implemented, with automated tests covering the current functionality.

## Overview

Large language models can generate inaccurate answers when they lack relevant information about a particular knowledge base. This project explores a RAG-based approach that retrieves relevant document chunks and provides them as context to an AI model before generating an answer.

The goal is to build a backend foundation for a document-aware chatbot that can answer questions using information retrieved from stored documents rather than relying solely on the model's general knowledge.

## Key Features

- **Document ingestion:** Processes document text into manageable chunks.
- **Text chunking:** Splits text into overlapping chunks to preserve context between adjacent segments.
- **Vector embeddings:** Uses Google's Gemini embedding model to convert document text and user questions into numerical vectors.
- **Vector storage:** Stores embeddings in PostgreSQL using the pgvector extension.
- **Semantic search:** Retrieves relevant document chunks based on query embeddings.
- **Tenant-scoped retrieval:** Restricts semantic search to the requested tenant's data.
- **RAG answer generation:** Supplies retrieved document context to Gemini to generate context-grounded answers.
- **Source references:** Returns retrieved chunk information alongside the generated answer.
- **Re-ingestion support:** Replaces existing document chunks when a document is ingested again.
- **Automated testing:** Includes unit and feature tests for chunking, ingestion, semantic search, and chat API behavior.

## Technology Stack

| Technology | Purpose |
|---|---|
| PHP 8.4 | Backend programming language |
| Laravel 13 | Application framework and REST API |
| PostgreSQL | Relational database |
| pgvector | Vector storage and similarity search |
| Google Gemini API | Embedding generation and AI responses |
| PHPUnit / Laravel testing | Automated testing |
| Git and GitHub | Version control and source code hosting |

## Architecture

The application follows a retrieval-augmented generation workflow:

```text
                 DOCUMENT INGESTION

Document Text
     |
     v
Text Chunker
     |
     v
Gemini Embeddings
     |
     v
PostgreSQL + pgvector
     |
     |  Stored document chunks
     v

                  QUESTION ANSWERING

User Question
     |
     v
Query Embedding
     |
     v
Tenant-Scoped Semantic Search
     |
     v
Relevant Document Chunks
     |
     v
Context + User Question
     |
     v
Gemini Answer Generation
     |
     v
Answer + Source References
```

### Core Components

- `TextChunker` — splits text into overlapping chunks.
- `EmbeddingService` — generates document and query embeddings.
- `DocumentIngestionService` — generates embeddings and stores document chunks.
- `ChunkSearchService` — retrieves relevant chunks within a tenant's scope.
- `RagService` — coordinates retrieval and AI answer generation.
- `ChatController` — exposes the chat functionality through an HTTP endpoint.

## API Usage

### Chat endpoint

`POST /api/chat`

**Example request:**

```json
{
  "question": "What information is available in my documents?",
  "tenant_id": 1
}
```

**Example response:**

```json
{
  "answer": "Based on the retrieved document context, ...",
  "sources": [
    {
      "chunk_id": 10,
      "document_id": 1,
      "content": "Relevant information retrieved from a document..."
    }
  ]
}
```

The response structure illustrates the current API contract. The actual answer and sources depend on the stored documents and retrieval results.

**Note:** Tenant authentication and authorization must be enforced before exposing this endpoint to production users. The current development endpoint accepts `tenant_id` in the request; this must be replaced with a server-authorized tenant identity before production deployment.

## Getting Started

### Prerequisites

- PHP 8.4 or a compatible version
- Composer
- PostgreSQL with the pgvector extension
- A Google Gemini API key

### Installation

1. Clone the repository:

   ```bash
   git clone https://github.com/razu91/rag-chatbot.git
   cd rag-chatbot
   ```

2. Install dependencies:

   ```bash
   composer install
   ```

3. Configure the environment:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. Configure `.env` with your PostgreSQL database credentials and Gemini API key. Use the environment variable names expected by the application's existing configuration.

5. Ensure PostgreSQL has the required pgvector extension enabled and configure the database connection.

6. Run the migrations:

   ```bash
   php artisan migrate
   ```

7. Run the automated test suite:

   ```bash
   php artisan test
   ```

Never commit `.env` or expose API credentials in source control.

## Testing

The current automated test suite covers:

- Text chunk splitting and overlap behavior
- Empty text handling
- Document chunk storage and re-ingestion
- Tenant-scoped semantic search
- Chat API response structure and input validation

At the current development checkpoint, **10 tests pass with 29 assertions**.

The chat controller test mocks the RAG service. A full integration test of the real embedding, retrieval, and Gemini generation pipeline remains planned.

## Current Limitations and Roadmap

The project is under active development. Planned improvements include:

- End-to-end RAG integration testing
- Document upload and document management APIs
- Authentication and server-side tenant authorization
- Improved retrieval evaluation and answer quality checks
- A chat frontend with source citations
- Production-oriented logging, rate limiting, and error handling
- Containerized deployment and operational documentation

## Project Goals

This project is intended to demonstrate practical backend engineering concepts, including service-layer design, REST API development, relational and vector data storage, external AI API integration, tenant-aware data retrieval, and automated testing.


