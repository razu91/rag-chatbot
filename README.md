# RAG Chatbot

A multi-tenant Retrieval-Augmented Generation (RAG) chatbot built with Laravel, PostgreSQL, pgvector, and Google Gemini. The application allows authenticated users to upload knowledge documents and ask questions answered using relevant document content.

## Features

- **Multi-tenant architecture:** Isolate users, documents, and document chunks by tenant.
- **Authentication:** Secure API endpoints using Laravel Sanctum.
- **Document ingestion:** Upload and process text documents for retrieval.
- **Vector search:** Store and search document embeddings using PostgreSQL and pgvector.
- **AI-powered responses:** Generate answers using Google Gemini.
- **Source attribution:** Associate generated answers with retrieved knowledge sources.
- **Document management:** List, view, update, and delete documents.
- **Input validation:** Validate chat questions, uploaded files, and document updates.
- **Resilient AI requests:** Retry selected transient Gemini API failures.
- **Automated tests:** Test authentication, tenant isolation, ingestion, document management, and chat behavior.
- **Dockerized database:** Run PostgreSQL with pgvector using Docker Compose.

## Tech Stack

| Technology | Purpose |
|---|---|
| PHP | Backend programming language |
| Laravel 13 | Application framework |
| Laravel Sanctum | API token authentication |
| PostgreSQL | Relational database |
| pgvector | Vector storage and similarity search |
| Google Gemini | Embeddings and response generation |
| Docker Compose | Local database environment |
| PHPUnit / Laravel testing | Automated testing |

## Architecture Overview

```text
Authenticated Client
        |
        v
Laravel API
        |
        +---- Sanctum Authentication
        |
        +---- Tenant Resolution
        |
        +---- Document Management
        |          |
        |          v
        |     Text Extraction / Chunking
        |          |
        |          v
        |     Embedding Generation
        |          |
        |          v
        |     PostgreSQL + pgvector
        |
        +---- Chat / RAG Pipeline
                   |
                   v
             Question Embedding
                   |
                   v
             Tenant-Scoped
             Vector Retrieval
                   |
                   v
             Relevant Context
                   |
                   v
             Google Gemini
                   |
                   v
             Answer + Sources
```

The authenticated user's tenant determines which knowledge base is accessible. The API does not rely on a client-supplied `tenant_id` to determine chat or document ownership.

## Prerequisites

Install the following tools before starting:

- PHP 8.4 or a compatible version supported by the application
- Composer
- Docker Engine
- Docker Compose
- Git
- A Google Gemini API key for live AI functionality

Check your installations:

```bash
php -v
composer --version
docker --version
docker compose version
git --version
```

## Installation and Setup

### 1. Clone the repository

```bash
git clone https://github.com/razu91/rag-chatbot.git
cd rag-chatbot
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Configure environment variables

Create your local environment file:

```bash
cp .env.example .env
```

Generate the Laravel application key:

```bash
php artisan key:generate
```

Update your local `.env` file with your Gemini API key:

```dotenv
GEMINI_API_KEY=your_gemini_api_key
GEMINI_EMBEDDING_MODEL=gemini-embedding-001
GEMINI_EMBEDDING_DIMENSION=1536
GEMINI_GENERATION_MODEL=gemini-3.1-flash-lite
```

Keep your real API keys and credentials in `.env`. Never commit them to Git.

### 4. Start PostgreSQL with pgvector

Start the database service:

```bash
docker compose up -d postgres
```

Check the container status:

```bash
docker compose ps
```

Inspect the database logs if needed:

```bash
docker compose logs postgres
```

The default local database configuration is:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rag_chatbot
DB_USERNAME=rag_user
DB_PASSWORD=rag_password
```

These values must match the PostgreSQL configuration in `compose.yaml`. Use different, secure credentials outside local development.

**Note:** The PostgreSQL container must have the pgvector extension available. If your database initialization script does not enable it, enable the extension before running migrations that depend on the `vector` type:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

### 5. Run database migrations

```bash
php artisan migrate
```

If Laravel cannot connect to PostgreSQL, verify the container status, database credentials, port mapping, and application configuration cache.

### 6. Start the Laravel development server

```bash
php artisan serve
```

The application will be available at:

`http://localhost:8000`

Keep the PostgreSQL container running while using the application.

## Environment Variables

The most important configuration variables are:

| Variable | Description |
|---|---|
| `APP_KEY` | Laravel application encryption key |
| `APP_URL` | Application base URL |
| `DB_CONNECTION` | Database driver; use `pgsql` |
| `DB_HOST` | PostgreSQL host |
| `DB_PORT` | PostgreSQL port |
| `DB_DATABASE` | Application database name |
| `DB_USERNAME` | Database username |
| `DB_PASSWORD` | Database password |
| `GEMINI_API_KEY` | Google Gemini API key |
| `GEMINI_EMBEDDING_MODEL` | Model used to generate embeddings |
| `GEMINI_EMBEDDING_DIMENSION` | Embedding vector dimension |
| `GEMINI_GENERATION_MODEL` | Model used to generate answers |

The configured embedding dimension must match the vector column definition in the database. Changing the embedding model or dimension may require re-embedding existing documents.

## API Documentation

All protected endpoints require a valid Laravel Sanctum bearer token.

Include the token in the request header:

```http
Authorization: Bearer YOUR_ACCESS_TOKEN
Accept: application/json
```

### Authentication

Obtain a token through the authentication flow supported by your application. The application uses Laravel Sanctum to authenticate API requests.

For development, a token may be generated for an authorized user through Laravel's authentication or token-creation workflow. Never use a production user's token in public examples.

### API Endpoints

| Method | Endpoint | Description | Authentication |
|---|---|---|---|
| `POST` | `/api/chat` | Ask a question against the authenticated tenant's knowledge base | Required |
| `POST` | `/api/documents` | Upload a text document | Required |
| `GET` | `/api/documents` | List the authenticated tenant's documents | Required |
| `GET` | `/api/documents/{document}` | View a document | Required |
| `PATCH` | `/api/documents/{document}` | Update supported document fields | Required |
| `DELETE` | `/api/documents/{document}` | Delete a document | Required |

The authenticated user's tenant is resolved server-side. Clients must not be able to access another tenant's documents by changing a request parameter or document identifier.

### 1. Ask a Question

**Request**

```http
POST /api/chat
Content-Type: application/json
Authorization: Bearer YOUR_ACCESS_TOKEN
```

```json
{
  "question": "What is pgvector?"
}
```

The question must be between 2 and 4,000 characters.

The application retrieves relevant chunks from the authenticated tenant's knowledge base and uses the retrieved context to generate a response.

### 2. Upload a Document

**Request**

```http
POST /api/documents
Authorization: Bearer YOUR_ACCESS_TOKEN
Content-Type: multipart/form-data
```

Form field:

```text
file: your-document.txt
```

The API accepts `.txt` files up to 1,024 KB (1 MB). Uploaded files are stored privately and processed for retrieval.

A successful upload returns HTTP `201` with the document information. The ingestion process must complete successfully for the document to become available for retrieval.

### 3. List Documents

```http
GET /api/documents
Authorization: Bearer YOUR_ACCESS_TOKEN
```

Returns documents belonging to the authenticated user's tenant.

### 4. View a Document

```http
GET /api/documents/{document}
Authorization: Bearer YOUR_ACCESS_TOKEN
```

Replace `{document}` with the document ID.

### 5. Update a Document

```http
PATCH /api/documents/{document}
Authorization: Bearer YOUR_ACCESS_TOKEN
Content-Type: application/json
```

Send the supported update fields accepted by the document validation rules.

### 6. Delete a Document

```http
DELETE /api/documents/{document}
Authorization: Bearer YOUR_ACCESS_TOKEN
```

The application should enforce tenant ownership before allowing deletion.

## How RAG Works

Retrieval-Augmented Generation combines document retrieval with a language model to provide answers grounded in relevant knowledge.

1. **Upload:** An authenticated user uploads a supported text document.
2. **Chunking:** The document is divided into smaller text chunks.
3. **Embedding:** The configured embedding model converts chunks into vectors.
4. **Storage:** Document metadata, chunks, and embeddings are stored in PostgreSQL with pgvector.
5. **Question processing:** The user's question is converted into an embedding.
6. **Retrieval:** The application searches for relevant chunks within the authenticated tenant's knowledge base.
7. **Generation:** Relevant context is sent to Gemini to generate an answer.
8. **Source attribution:** The response can identify the retrieved knowledge sources.

The quality of the response depends on the uploaded content, chunking strategy, retrieval configuration, and AI model.

## Multi-Tenant Security

Tenant isolation is a core design requirement.

- Authentication is required for protected API endpoints.
- Tenant identity is derived from the authenticated user.
- Chat retrieval is scoped to the authenticated tenant.
- Document access and management enforce tenant ownership.
- Unauthenticated requests are rejected.
- Clients cannot choose another tenant simply by submitting a different tenant ID.
- API errors should not expose internal exceptions, secrets, or stack traces.

For production deployments, also configure HTTPS, secure credential management, rate limiting, backups, monitoring, and appropriate data-retention policies.

## Running Tests

Run the automated test suite:

```bash
php artisan test
```

The latest recorded test run passed **45 tests with 184 assertions**. Re-run the suite against your current checkout before relying on this number.

The test coverage includes:

- Unauthenticated API access
- Chat request validation
- Tenant resolution and isolation
- Document upload validation
- Document ingestion and re-ingestion
- Document listing, viewing, updating, and deletion
- Cross-tenant access prevention
- File replacement and rollback behavior
- RAG response and fallback behavior

### Dependency Security Audit

Check Composer dependencies for known security advisories:

```bash
composer audit
```

The last recorded audit reported no known security vulnerability advisories. Run it again periodically as dependencies change.

## Troubleshooting

### PostgreSQL connection failure

Check whether the container is running:

```bash
docker compose ps
docker compose logs postgres
```

Confirm that the database values in `.env` match `compose.yaml`.

### `type "vector" does not exist`

The pgvector extension is missing from the database used by the migration.

Connect to the correct database and run:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

Ensure the extension is enabled in the database used by the application and, separately, in any PostgreSQL database used for tests.

### Gemini API errors

Verify:

- `GEMINI_API_KEY` is set correctly.
- The configured models are available to your Google AI project.
- The API key has the required permissions.
- Your API quota and billing or usage limits permit the request.
- The embedding dimension matches the database schema.

After changing `.env`, clear cached configuration if necessary:

```bash
php artisan config:clear
```

### Uploaded documents are not retrieved

Confirm that ingestion completed successfully, the document has stored chunks, the embeddings have the expected dimension, and the query is scoped to the correct tenant.

## Project Structure

Key areas of the application include:

- `app/` — application logic, models, services, and API-related code
- `routes/api.php` — API route definitions
- `database/migrations/` — database schema migrations
- `docker/` — database initialization assets
- `compose.yaml` — local PostgreSQL service configuration
- `.env.example` — environment configuration template
- `tests/` — automated test suite

Exact class and directory names may vary as the project evolves.

## Development Notes

- Keep `.env.example` free of real secrets.
- Update this README when API contracts, model names, database setup, or installation steps change.
- Run automated tests after changing tenant authorization or document ingestion.
- Re-embed existing documents when making incompatible embedding-model or vector-dimension changes.
- Validate all configuration changes against the current codebase before deployment.

# RAG Chatbot

A multi-tenant Retrieval-Augmented Generation (RAG) chatbot built with Laravel, PostgreSQL, pgvector, and Google Gemini. The application allows authenticated users to upload knowledge documents and ask questions answered using relevant document content.

## Features

- **Multi-tenant architecture:** Isolate users, documents, and document chunks by tenant.
- **Authentication:** Secure API endpoints using Laravel Sanctum.
- **Document ingestion:** Upload and process text documents for retrieval.
- **Vector search:** Store and search document embeddings using PostgreSQL and pgvector.
- **AI-powered responses:** Generate answers using Google Gemini.
- **Source attribution:** Associate generated answers with retrieved knowledge sources.
- **Document management:** List, view, update, and delete documents.
- **Input validation:** Validate chat questions, uploaded files, and document updates.
- **Resilient AI requests:** Retry selected transient Gemini API failures.
- **Automated tests:** Test authentication, tenant isolation, ingestion, document management, and chat behavior.
- **Dockerized database:** Run PostgreSQL with pgvector using Docker Compose.

## Tech Stack

| Technology | Purpose |
|---|---|
| PHP | Backend programming language |
| Laravel 13 | Application framework |
| Laravel Sanctum | API token authentication |
| PostgreSQL | Relational database |
| pgvector | Vector storage and similarity search |
| Google Gemini | Embeddings and response generation |
| Docker Compose | Local database environment |
| PHPUnit / Laravel testing | Automated testing |

## Architecture Overview

```text
Authenticated Client
        |
        v
Laravel API
        |
        +---- Sanctum Authentication
        |
        +---- Tenant Resolution
        |
        +---- Document Management
        |          |
        |          v
        |     Text Extraction / Chunking
        |          |
        |          v
        |     Embedding Generation
        |          |
        |          v
        |     PostgreSQL + pgvector
        |
        +---- Chat / RAG Pipeline
                   |
                   v
             Question Embedding
                   |
                   v
             Tenant-Scoped
             Vector Retrieval
                   |
                   v
             Relevant Context
                   |
                   v
             Google Gemini
                   |
                   v
             Answer + Sources
```

The authenticated user's tenant determines which knowledge base is accessible. The API does not rely on a client-supplied `tenant_id` to determine chat or document ownership.

## Prerequisites

Install the following tools before starting:

- PHP 8.4 or a compatible version supported by the application
- Composer
- Docker Engine
- Docker Compose
- Git
- A Google Gemini API key for live AI functionality

Check your installations:

```bash
php -v
composer --version
docker --version
docker compose version
git --version
```

## Installation and Setup

### 1. Clone the repository

```bash
git clone https://github.com/razu91/rag-chatbot.git
cd rag-chatbot
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Configure environment variables

Create your local environment file:

```bash
cp .env.example .env
```

Generate the Laravel application key:

```bash
php artisan key:generate
```

Update your local `.env` file with your Gemini API key:

```dotenv
GEMINI_API_KEY=your_gemini_api_key
GEMINI_EMBEDDING_MODEL=gemini-embedding-001
GEMINI_EMBEDDING_DIMENSION=1536
GEMINI_GENERATION_MODEL=gemini-3.1-flash-lite
```

Keep your real API keys and credentials in `.env`. Never commit them to Git.

### 4. Start PostgreSQL with pgvector

Start the database service:

```bash
docker compose up -d postgres
```

Check the container status:

```bash
docker compose ps
```

Inspect the database logs if needed:

```bash
docker compose logs postgres
```

The default local database configuration is:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rag_chatbot
DB_USERNAME=rag_user
DB_PASSWORD=rag_password
```

These values must match the PostgreSQL configuration in `compose.yaml`. Use different, secure credentials outside local development.

**Note:** The PostgreSQL container must have the pgvector extension available. If your database initialization script does not enable it, enable the extension before running migrations that depend on the `vector` type:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

### 5. Run database migrations

```bash
php artisan migrate
```

If Laravel cannot connect to PostgreSQL, verify the container status, database credentials, port mapping, and application configuration cache.

### 6. Start the Laravel development server

```bash
php artisan serve
```

The application will be available at:

`http://localhost:8000`

Keep the PostgreSQL container running while using the application.

## Environment Variables

The most important configuration variables are:

| Variable | Description |
|---|---|
| `APP_KEY` | Laravel application encryption key |
| `APP_URL` | Application base URL |
| `DB_CONNECTION` | Database driver; use `pgsql` |
| `DB_HOST` | PostgreSQL host |
| `DB_PORT` | PostgreSQL port |
| `DB_DATABASE` | Application database name |
| `DB_USERNAME` | Database username |
| `DB_PASSWORD` | Database password |
| `GEMINI_API_KEY` | Google Gemini API key |
| `GEMINI_EMBEDDING_MODEL` | Model used to generate embeddings |
| `GEMINI_EMBEDDING_DIMENSION` | Embedding vector dimension |
| `GEMINI_GENERATION_MODEL` | Model used to generate answers |

The configured embedding dimension must match the vector column definition in the database. Changing the embedding model or dimension may require re-embedding existing documents.

## API Documentation

All protected endpoints require a valid Laravel Sanctum bearer token.

Include the token in the request header:

```http
Authorization: Bearer YOUR_ACCESS_TOKEN
Accept: application/json
```

### Authentication

Obtain a token through the authentication flow supported by your application. The application uses Laravel Sanctum to authenticate API requests.

For development, a token may be generated for an authorized user through Laravel's authentication or token-creation workflow. Never use a production user's token in public examples.

### API Endpoints

| Method | Endpoint | Description | Authentication |
|---|---|---|---|
| `POST` | `/api/chat` | Ask a question against the authenticated tenant's knowledge base | Required |
| `POST` | `/api/documents` | Upload a text document | Required |
| `GET` | `/api/documents` | List the authenticated tenant's documents | Required |
| `GET` | `/api/documents/{document}` | View a document | Required |
| `PATCH` | `/api/documents/{document}` | Update supported document fields | Required |
| `DELETE` | `/api/documents/{document}` | Delete a document | Required |

The authenticated user's tenant is resolved server-side. Clients must not be able to access another tenant's documents by changing a request parameter or document identifier.

### 1. Ask a Question

**Request**

```http
POST /api/chat
Content-Type: application/json
Authorization: Bearer YOUR_ACCESS_TOKEN
```

```json
{
  "question": "What is pgvector?"
}
```

The question must be between 2 and 4,000 characters.

The application retrieves relevant chunks from the authenticated tenant's knowledge base and uses the retrieved context to generate a response.

### 2. Upload a Document

**Request**

```http
POST /api/documents
Authorization: Bearer YOUR_ACCESS_TOKEN
Content-Type: multipart/form-data
```

Form field:

```text
file: your-document.txt
```

The API accepts `.txt` files up to 1,024 KB (1 MB). Uploaded files are stored privately and processed for retrieval.

A successful upload returns HTTP `201` with the document information. The ingestion process must complete successfully for the document to become available for retrieval.

### 3. List Documents

```http
GET /api/documents
Authorization: Bearer YOUR_ACCESS_TOKEN
```

Returns documents belonging to the authenticated user's tenant.

### 4. View a Document

```http
GET /api/documents/{document}
Authorization: Bearer YOUR_ACCESS_TOKEN
```

Replace `{document}` with the document ID.

### 5. Update a Document

```http
PATCH /api/documents/{document}
Authorization: Bearer YOUR_ACCESS_TOKEN
Content-Type: application/json
```

Send the supported update fields accepted by the document validation rules.

### 6. Delete a Document

```http
DELETE /api/documents/{document}
Authorization: Bearer YOUR_ACCESS_TOKEN
```

The application should enforce tenant ownership before allowing deletion.

## How RAG Works

Retrieval-Augmented Generation combines document retrieval with a language model to provide answers grounded in relevant knowledge.

1. **Upload:** An authenticated user uploads a supported text document.
2. **Chunking:** The document is divided into smaller text chunks.
3. **Embedding:** The configured embedding model converts chunks into vectors.
4. **Storage:** Document metadata, chunks, and embeddings are stored in PostgreSQL with pgvector.
5. **Question processing:** The user's question is converted into an embedding.
6. **Retrieval:** The application searches for relevant chunks within the authenticated tenant's knowledge base.
7. **Generation:** Relevant context is sent to Gemini to generate an answer.
8. **Source attribution:** The response can identify the retrieved knowledge sources.

The quality of the response depends on the uploaded content, chunking strategy, retrieval configuration, and AI model.

## Multi-Tenant Security

Tenant isolation is a core design requirement.

- Authentication is required for protected API endpoints.
- Tenant identity is derived from the authenticated user.
- Chat retrieval is scoped to the authenticated tenant.
- Document access and management enforce tenant ownership.
- Unauthenticated requests are rejected.
- Clients cannot choose another tenant simply by submitting a different tenant ID.
- API errors should not expose internal exceptions, secrets, or stack traces.

For production deployments, also configure HTTPS, secure credential management, rate limiting, backups, monitoring, and appropriate data-retention policies.

## Running Tests

Run the automated test suite:

```bash
php artisan test
```

The latest recorded test run passed **45 tests with 184 assertions**. Re-run the suite against your current checkout before relying on this number.

The test coverage includes:

- Unauthenticated API access
- Chat request validation
- Tenant resolution and isolation
- Document upload validation
- Document ingestion and re-ingestion
- Document listing, viewing, updating, and deletion
- Cross-tenant access prevention
- File replacement and rollback behavior
- RAG response and fallback behavior

### Dependency Security Audit

Check Composer dependencies for known security advisories:

```bash
composer audit
```

The last recorded audit reported no known security vulnerability advisories. Run it again periodically as dependencies change.

## Troubleshooting

### PostgreSQL connection failure

Check whether the container is running:

```bash
docker compose ps
docker compose logs postgres
```

Confirm that the database values in `.env` match `compose.yaml`.

### `type "vector" does not exist`

The pgvector extension is missing from the database used by the migration.

Connect to the correct database and run:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

Ensure the extension is enabled in the database used by the application and, separately, in any PostgreSQL database used for tests.

### Gemini API errors

Verify:

- `GEMINI_API_KEY` is set correctly.
- The configured models are available to your Google AI project.
- The API key has the required permissions.
- Your API quota and billing or usage limits permit the request.
- The embedding dimension matches the database schema.

After changing `.env`, clear cached configuration if necessary:

```bash
php artisan config:clear
```

### Uploaded documents are not retrieved

Confirm that ingestion completed successfully, the document has stored chunks, the embeddings have the expected dimension, and the query is scoped to the correct tenant.

## Project Structure

Key areas of the application include:

- `app/` — application logic, models, services, and API-related code
- `routes/api.php` — API route definitions
- `database/migrations/` — database schema migrations
- `docker/` — database initialization assets
- `compose.yaml` — local PostgreSQL service configuration
- `.env.example` — environment configuration template
- `tests/` — automated test suite

Exact class and directory names may vary as the project evolves.

## Development Notes

- Keep `.env.example` free of real secrets.
- Update this README when API contracts, model names, database setup, or installation steps change.
- Run automated tests after changing tenant authorization or document ingestion.
- Re-embed existing documents when making incompatible embedding-model or vector-dimension changes.
- Validate all configuration changes against the current codebase before deployment.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
