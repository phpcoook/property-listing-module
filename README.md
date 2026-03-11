# Property Listing API Module

A Laravel API module for agents to create properties, upload images to S3, and list properties. Built with a layered architecture: **Controller → Service → Repository → Model**.

---

## How to Run Locally (Docker)

### Prerequisites

- Docker and Docker Compose
- A `.env` file in the project root (copy from `.env.example` if needed)

### 1. Configure environment

Ensure your `.env` uses PostgreSQL and matches the `db` service:

```env
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=property_listing
DB_USERNAME=postgres
DB_PASSWORD=postgres

QUEUE_CONNECTION=database
```

Set `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, and `AWS_BUCKET` when you want to upload images to S3.

### 2. Build and start containers

```bash
docker compose build
docker compose up
```

This starts:

- **app** – Laravel API at `http://localhost:8000`
- **db** – PostgreSQL 16
- **queue** – Worker that processes image-upload jobs

### 3. Create an agent (for testing)

Use the API or tinker:

**Option A – API:** `POST http://localhost:8000/api/v1/agents` with JSON body `{"name": "Agent One", "email": "agent1@example.com"}`. Use the returned `id` as `agent_id` when creating properties.

**Option B – Tinker:**

```bash
docker compose exec app php artisan tinker
```

Then: `\App\Models\Agent::create(['name' => 'Agent One', 'email' => 'agent1@example.com']);`

### 5. Test the API

- **Create property:** `POST http://localhost:8000/api/v1/properties` (JSON body with `title`, `description`, `price`, `address`, `city`, `country`, `agent_id`)
- **Upload images:** `POST http://localhost:8000/api/v1/properties/{id}/images` (form-data, key `images[]`, type File)
- **List properties:** `GET http://localhost:8000/api/v1/properties?per_page=10`

---

## Database Design Decisions

### Tables

| Table             | Purpose |
|-------------------|--------|
| **agents**        | Stores agents: `id`, `name`, `email` (unique), timestamps. |
| **properties**    | Properties linked to an agent: `agent_id` (FK), `title`, `description`, `price`, `address`, `city`, `country`, timestamps. |
| **property_images** | One row per image: `property_id` (FK), `image_path` (S3 key/URL), timestamps. |

### Constraints and indexes

- **Foreign keys:** `properties.agent_id → agents.id`, `property_images.property_id → properties.id`, both with `ON DELETE CASCADE` so removing an agent or property cleans up related rows.
- **Composite unique constraint** on `properties`: `UNIQUE (agent_id, address, city)` named `properties_agent_address_city_unique`. This enforces “one property per agent per address per city” at the database level.
- **Indexes** for common access patterns:
  - `(city, country)` – filter by location
  - `created_at` – sort by newest/oldest
  - `agent_id`, `property_id` – fast joins and lookups

This keeps the schema simple, referentially consistent, and ready for listing/filtering at scale.

---

## How Duplicate Properties Are Prevented

- **Database guarantee:** The composite unique index `(agent_id, address, city)` on `properties` ensures that only one row can exist for that combination. Any second insert with the same values fails with a unique constraint violation (PostgreSQL `23505`).
- **Application handling:** Property creation runs inside a **transaction**. On success, the transaction commits and we log `property_created`. If the insert fails due to that unique constraint, we:
  - Roll back the transaction
  - Detect the violation (by error code and constraint name)
  - Log `duplicate_property_rejected` with `agent_id`, `address`, `city`
  - Throw a `DuplicatePropertyException`, which the controller maps to **HTTP 409 Conflict** with a clear message
- **Concurrent requests:** Two requests creating the same property at the same time both try to insert; one succeeds, the other hits the unique constraint and receives 409. No duplicate row is ever created, regardless of timing or number of app instances.

---

## Performance Considerations

- **N+1 avoidance:** Property listing uses `Property::with('images')` so images are loaded in one extra query per page instead of one per property.
- **Indexes:** The unique index and the extra indexes on `(city, country)`, `created_at`, `agent_id`, and `property_id` keep filters and joins fast.
- **Pagination:** List endpoint is always paginated (`per_page` query param), limiting payload size and database load.
- **Offloading work:** Image uploads run in a **queue worker**, so the API responds quickly with 202 and the worker handles S3 upload and DB writes in the background.

---

## How the Queue Job Works

1. **API:** `POST /api/v1/properties/{id}/images` accepts multiple files. The controller validates them and calls `PropertyService::queuePropertyImages()`.
2. **Service:** For each file it saves to local disk (e.g. `storage/app/tmp/property-images/...`) and dispatches a **ProcessPropertyImage** job to the `property-images` queue with `propertyId`, `localPath`, and `originalName`.
3. **Response:** The API returns **202 Accepted** with “Images are being processed.” so the client is not blocked.
4. **Worker:** The `queue` container runs `php artisan queue:work --queue=property-images`. It picks jobs from the `jobs` table.
5. **Job (`ProcessPropertyImage`):**
   - Loads the property; if missing, logs and exits.
   - Uploads the file from `localPath` to S3 under `properties/{propertyId}/{originalName}`.
   - Inserts a row in `property_images` with the S3 path.
   - On failure, logs `property_image_upload_failed` and (depending on config) the job can be retried or stored in `failed_jobs`.
   - Deletes the temporary local file.

Because both `app` and `queue` mount the same project directory, the worker can read the temp file written by the web process.

---

## Scaling for 1M+ Properties

- **Database:** Rely on existing indexes; add read replicas for read-heavy listing/search. Consider table partitioning (e.g. by region or time) if certain segments become very large or hot.
- **Caching:** Cache popular listing queries or “featured”/“latest” results (e.g. Redis) to reduce DB load.
- **Search:** For complex filters and full-text search, add a search engine (Elasticsearch, OpenSearch, Meilisearch) and sync property data via queue jobs.
- **Queues:** Scale queue workers horizontally (more containers/processes for `property-images`). Use a robust queue backend (e.g. Redis or SQS) for high throughput and reliability.
- **Application:** Run multiple app instances behind a load balancer. Keep sessions, cache, and queues in shared stores (Redis, SQS, etc.) so instances stay stateless.
- **Observability:** Monitor queue depth, job processing time, and API latency to spot bottlenecks as data and traffic grow.

---

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST   | `/api/v1/agents` | Create agent. Body: `name`, `email`. |
| GET    | `/api/v1/properties` | List properties (paginated, with images). Query: `per_page`, `city`, `country`, `agent_id`. |
| POST   | `/api/v1/properties` | Create property. Body: `title`, `description`, `price`, `address`, `city`, `country`, `agent_id`. Returns 409 if duplicate. |
| POST   | `/api/v1/properties/{id}/images` | Upload images (queued). Body: form-data `images[]` (files). Returns 202. |

A **Postman collection** is included at `postman/Property_Listing_API.json`. Import it into Postman and set the `base_url` variable (e.g. `http://localhost:8000`).

---

## Property image URLs

Each property image in the API response includes:

- **`image_path`** – The S3 object key (e.g. `properties/1/photo.jpg`).
- **`image_url`** – A full URL that can be opened in a browser to view the image.

S3 objects are **private** by default, so a plain S3 URL would return “Access Denied”. The API therefore returns **temporary signed URLs** for `image_url`: they grant time-limited access without making the bucket or objects public.

- **Expiry:** Signed URLs are valid for **1 hour** from the time of the API response. After that, opening the link will return “Access Denied”. Clients should call the API again to get fresh URLs if they need to display images later.
- **Caching:** Because the URL changes over time (different signature and expiry), avoid caching `image_url` long-term; treat it as short-lived.

---

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
