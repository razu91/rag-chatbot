# Laravel RAG Chatbot

A multi-tenant **Retrieval-Augmented Generation (RAG) chatbot API** built with Laravel 13, PHP 8.4, PostgreSQL, pgvector, and Google Gemini.

The application ingests text documents, generates vector embeddings, retrieves relevant content using tenant-scoped semantic search, and generates context-grounded answers with source references.

**Project status:** MVP implemented and verified through automated tests and a successful live RAG workflow.

## Features

- **Document ingestion:** Upload and process text documents into manageable chunks.
- **Text chunking:** Split documents into overlapping chunks to preserve contextual continuity.
- **Vector embeddings:** Generate embeddings using Google's Gemini embedding model.
- **Vector database:** Store document chunks and embeddings in PostgreSQL with pgvector.
- **Semantic search:** Retrieve relevant document chunks using vector similarity.
- **Multi-tenant isolation:** Restrict document access and semantic retrieval to the authenticated user's tenant.
- **AI answer generation:** Use retrieved document context to generate answers with Gemini.
- **Source references:** Return relevant chunk content and document identifiers with answers.
- **Document management:** List, view, update, replace, and delete documents with tenant-scoped authorization.
- **Re-ingestion:** Replace existing document chunks when document content is processed again.
- **API authentication:** Protect API endpoints using Laravel Sanctum bearer tokens.
- **Input validation:** Validate chat questions and document uploads.
- **Error handling:** Return API errors without exposing internal exception details.
- **Automated testing:** Test core services, API behavior, authentication, document operations, and tenant isolation.

## Technology Stack

| Technology | Purpose |
|---|---|
| PHP 8.4 | Backend programming language |
| Laravel 13 | Application framework and REST API |
| Laravel Sanctum | API token authentication |
| PostgreSQL 18 | Relational database |
| pgvector | Vector storage and similarity search |
| Google Gemini API | Embeddings and AI answer generation |
| Docker Compose | Local database environment |
| PHPUnit / Laravel testing | Automated tests |
| Git and GitHub | Version control and source hosting |

## Architecture

### Document ingestion

```text
              TEXT DOCUMENT
                    |
                    v
        Document Ingestion Service
                    |
                    v
                TextChunker
                    |
                    v
          Gemini Embedding Model
                    |
                    v
             PostgreSQL
              + pgvector
                    |
                    v
          Stored Document Chunks
```

### Question answering

```text
             Authenticated User
                    |
                    v
              POST /api/chat
                    |
                    v
        Resolve Authenticated Tenant
                    |
                    v
          Generate Query Embedding
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
            Answer + Sources
```

## Core Components

- `TextChunker` — splits document text into overlapping chunks.
- `EmbeddingService` — generates document and query embeddings.
- `DocumentIngestionService` — processes documents, generates embeddings, and stores chunks.
- `ChunkSearchService` — performs tenant-scoped vector similarity search.
- `RagService` — coordinates retrieval and Gemini answer generation.
- `ChatController` — exposes chat functionality through the authenticated API.

## Getting Started

### Prerequisites

- PHP 8.4 or a compatible version
- Composer
- Docker Engine
- Docker Compose v2
- A Google Gemini API key

### 1. Clone the repository

```bash
git clone https://github.com/razu91/rag-chatbot.git
cd rag-chatbot
```

### 2. Start PostgreSQL with pgvector

The project includes a `compose.yaml` file that runs PostgreSQL using the `pgvector/pgvector:pg18` image.

Start the database:

```bash
docker compose up -d postgres
```

Check the container status:

```bash
docker compose ps
```

View database logs if needed:

```bash
docker compose logs -f postgres
```

The database port is bound to `127.0.0.1` by default, keeping it accessible through the local machine rather than directly exposing it on all network interfaces.

The Compose configuration uses a named volume to persist database data and mounts `docker/postgres/init.sql` for database initialization.

### 3. Install PHP dependencies

```bash
composer install
```

### 4. Configure the environment

Create your local environment file:

```bash
cp .env.example .env
php artisan key:generate
```

Configure the database connection and Gemini settings in `.env`.

Example database settings:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rag_chatbot
DB_USERNAME=rag_user
DB_PASSWORD=your_local_database_password
```

Use the same database credentials in Laravel and Docker Compose. The Compose file supports `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE`, and `DB_PORT`, with local development defaults.

Configure the Gemini API key and model variables using the names defined in `.env.example` and the application's configuration.

**Security:** Do not commit `.env`, real API keys, or production credentials. Replace development-only default passwords before using the application in a shared or production environment.

### 5. Run database migrations

Ensure the PostgreSQL container is healthy and the pgvector extension has been initialized. Then run:

```bash
php artisan migrate
```

The initialization script mounted at `docker/postgres/init.sql` runs when PostgreSQL initializes a new data directory. Editing the script does not automatically rerun it against an existing database volume.

### 6. Run the application

Start Laravel's development server:

```bash
php artisan serve
```

The default development URL is `http://127.0.0.1:8000`.

### 7. Run automated tests

```bash
php artisan test
```

## API Usage

Protected endpoints require a valid Laravel Sanctum bearer token.

### Authentication header

```http
Authorization: Bearer YOUR_SANCTUM_TOKEN
Accept: application/json
```

Use a token generated for your own local development account. Never publish real tokens.

### Chat endpoint

**`POST /api/chat`**

Example request:

```json
{
  "question": "What information is available in my documents?"
}
```

Example response:

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

The response illustrates the API structure. Actual answers and sources depend on the documents available to the authenticated user's tenant.

**Tenant security:** The application derives tenant identity from the authenticated user. Clients should not be allowed to select another tenant by supplying an arbitrary `tenant_id`.

### Document management endpoints

The implemented API supports the following operations:

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/api/documents` | Upload and process a text document |
| `GET` | `/api/documents` | List accessible documents |
| `GET` | `/api/documents/{document}` | Retrieve an accessible document |
| `PATCH` | `/api/documents/{document}` | Update document information or replace content |
| `DELETE` | `/api/documents/{document}` | Delete a document |

All operations are subject to authentication, validation, and tenant-scoped authorization. See `routes/api.php` and the relevant controllers for the exact request and response contracts.

## Testing and Verification

At the latest verified development checkpoint:

- **45 automated tests passed**
- **184 assertions passed**
- Text chunking and overlap behavior verified.
- Document ingestion and re-ingestion verified.
- Tenant-scoped semantic search verified.
- Chat authentication, validation, and error handling verified.
- Document listing, viewing, updating, replacement, and deletion authorization verified.
- Document upload validation and size restrictions verified.
- Live document ingestion, Gemini answer generation, and source attribution successfully exercised.
- `composer audit` reported no known security vulnerability advisories.
- Git working tree was clean and the local `main` branch was synchronized with `origin/main`.

These results reflect the checks performed at that development checkpoint. They do not guarantee that the application is free of every possible vulnerability.

## Current Limitations and Future Improvements

Potential future improvements include:

- Retrieval-quality evaluation using a representative question-and-answer dataset.
- Expanded integration tests against a dedicated PostgreSQL/pgvector environment.
- Rate limiting and production monitoring.
- Containerized application deployment.
- A frontend for chat, document management, and source inspection.
- Additional operational documentation and deployment automation.

These are potential extensions, not claims that the current MVP is incomplete.

## Project Goals

This project demonstrates practical backend engineering concepts:

- REST API development with Laravel.
- Token-based authentication and tenant-aware authorization.
- Document ingestion and text chunking.
- Vector embeddings and semantic retrieval.
- PostgreSQL and pgvector integration.
- External AI API integration.
- Service-layer architecture.
- Automated testing and failure handling.
- Docker-based local development.

## Repository

[GitHub — Laravel RAG Chatbot](https://github.com/razu91/rag-chatbot)
