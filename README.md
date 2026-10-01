# Search Place - Project Documentation

## Overview
This project is a place search application that allows users to search for locations in Iranian cities. It currently supports multiple providers and provides detailed information about places including name, address, phone number, website, coordinates, and images.

## Providers
The following providers are available for selection:
- بلد (Balad)
- نشان (Neshan)
- دیوار (Divar)
- گوگل مپ (Google Maps)

## How the Process Works

### Step 1: Provider Selection
Users select their desired provider from the list above. Each provider has its own API and data structure.

### Step 2: City/Province Selection
After selecting a provider, users choose a city or province from the available options.

### Step 3: Category Selection
Users select the type of place they're looking for (hotel, restaurant, etc.).

### Step 4: Search Execution
The system executes the search based on the selected parameters and displays results.

### Step 5: Result Display
Results are displayed in a grid format with cards showing:
- Place name
- Address
- Phone number
- Website link
- Image preview
- Link to view more details on the provider's platform

## Technical Implementation

### Architecture
The project follows a clean architecture pattern with separate components for:
- Controllers (handle HTTP requests)
- Services (business logic)
- Clients (API communication)
- Views (UI rendering)

### Key Components
- **BaladClient**: Handles low-level HTTP communication with Balad API
- **BaladSearchService**: Orchestrates the two-step search flow (search → getDetails)
- **BaladController**: Manages the overall search process
- **SearchView**: Renders the user interface

### Data Flow
1. User selects city and category via form submission
2. Controller processes the request and calls the service
3. Service uses client to make API calls
4. Search results are rendered and paginated without automatically saving all
   returned places.
5. When the user clicks **Save this place** on a Balad result, only that place is
   upserted into `accommodations` (`provider = 'balad'`, `external_id` is the
   Balad place token). Its first valid phone is normalized and upserted into
   `contacts`, and `accommodations.contact_id` links to that contact. The full
   source telephone value remains in provider JSON. Repeated saves reuse contacts
   and update the selected place instead of creating duplicates. Other providers
   keep their existing behavior.

## Divar Collection Page (`/divar_collect`)
A second Divar page whose only job is to **fetch everything and store it in the
database**.

The selection part is identical to the Divar search page
(province → city → category → optional keyword), but the result is **not
displayed and not paginated at all**: every ad of that search is written into
the `accommodations` table until Divar has no next page.

URL: `.../search_place/public/divar_collect`

### Why the harvest runs in steps
A city/category combination can contain thousands of ads. Downloading all of
them inside a single PHP request would hit `max_execution_time`, `memory_limit`
and the web server timeout. The collector therefore splits the work:

```
browser ──POST action=collect_step──▶ PHP: fetch ONE Divar page (cursor)
                                          store that batch in MySQL
                                          keep the next cursor in the session
browser ◀────────── JSON counters ───────┘
browser waits step_delay_ms, then requests the next step … until done
```

- every request stays short: one Divar call + one small DB batch
- PHP memory stays flat: a batch is stored and then dropped, so the total size
  of the result set does not matter
- the run can be paused and resumed, because Divar's cursor is kept in the PHP
  session (server side), not in the browser
- re-running the same search is harmless: `(provider, external_id)` is UNIQUE
  and every write is an `INSERT ... ON DUPLICATE KEY UPDATE`, so no duplicates
  are created and data added by later steps (phone numbers, coordinates) is not
  overwritten
- instead of result cards the page shows live counters (pages, fetched ads,
  new/updated/duplicate rows, errors, elapsed time, ads per minute) and a log

Keep the tab open and active while collecting; the browser drives the loop.

### AJAX actions of the page
| `action` | purpose |
| --- | --- |
| `collect_start` | create a job for the selection, or resume it with `resume=1` |
| `collect_step` | fetch one Divar page with the stored cursor, store the batch, return counters |
| `collect_stop` | pause the job (the cursor is kept, so it can continue later) |
| `collect_status` | state of the unfinished job of the current selection |
| `collect_reset` | drop the job from the session |

### Collection settings (`config/divar.php` → `collect`)
| key | default | meaning |
| --- | --- | --- |
| `page_limit` | `24` | ads per Divar request (Divar's own page size) |
| `step_delay_ms` | `800` | pause between two steps, also editable in the form |
| `max_steps` | `0` | hard cap of pages per run, `0` = unlimited, also editable in the form |
| `max_retries` | `6` | browser side retries for one failed step |
| `retry_base_ms` | `2000` | backoff base: `retry_base_ms * attempt` |
| `store_raw` | `true` | store the complete raw Divar payload in `raw_data` |
| `max_empty_pages` | `30` | stop when Divar keeps returning empty pages (loop guard) |
| `log_tail` | `8` | job log lines kept in the session |

### Divar city IDs
Divar's search API needs the numeric city id (`city_ids`). `config/cities.json`
therefore carries a `city_id` for every listed city (Tehran = 1, Karaj = 2,
Mashhad = 3, Isfahan = 4, Tabriz = 5, Shiraz = 6, Ahvaz = 7, Qom = 8, ...).
A city without `city_id` cannot be collected: both Divar pages now say so
explicitly instead of silently falling back to Tehran.

### Files of this feature
- `src/Controller/DivarCollectController.php` - route controller + AJAX actions
- `src/View/DivarCollectView.php` - the page (form + progress panel + JS loop)
- `src/Divar/DivarAdMapper.php` - Divar ad → `accommodations` row (incl. Persian
  price parsing, e.g. `۱٬۵۰۰٬۰۰۰ تومان` → `1500000`)
- `src/Divar/AccommodationRepository.php` - batched upserts + counters
- `src/Support/HarvestJobStore.php` - session storage of the job/cursor state
- `src/Support/Schema.php` - applies `database/schema.sql`
- `database/schema.sql` - the schema itself

## Database Schema
`database/schema.sql` is the single source of truth and is applied
automatically before Balad call logs are read/saved, a selected Balad place is
saved, or a Divar collection starts (`Src\Support\Schema::ensureTables()`),
so no manual migration is required.
Every statement uses `CREATE TABLE IF NOT EXISTS`,
therefore the file can also be applied by hand as often as you like:

```
mysql -u root -p search_place < database/schema.sql
```

There are three application tables (the Divar collector itself still writes only to
`contacts` and `accommodations`):

- `contacts`: phone numbers, unique per phone (`uq_contacts_phone`). When a user
  explicitly saves a Balad result, its first valid phone is saved here and linked
  through `accommodations.contact_id`; Divar phone collection remains a later step.
- `accommodations`: stored provider places/listings. Balad places are saved only
  when the user clicks that result's save button; searching and paging do not
  persist every result. Divar collection continues to harvest its full result set
  in batches.
  - `contact_id`: nullable FK to `contacts` (`ON DELETE SET NULL`)
  - `(provider, external_id)`: unique; the value is the provider's place/ad ID
  - `provider_data` (JSON): provider-specific fields and search/harvest context
    that do not have dedicated columns
  - `raw_data` (JSON): the complete raw provider payload for the stored place/ad
  - `latitude` / `longitude` / `price` are nullable and are **never overwritten
    with NULL** by a re-run of the collector
- `call_logs`: click-to-call records and their `pending` / `completed` /
  `cancelled` status. This is separate from the Divar collection flow.

Requires MySQL 5.7+ / MariaDB 10.2+ (JSON column type). On very old InnoDB
setups that reject a full length index on `title` (error 1071), `Schema`
automatically retries with a prefix index.

## Installation Notes
- After adding new classes, run `composer dump-autoload` if the autoloader was
  generated with `--optimize-autoloader` / classmap authoritative.
- Database credentials come from `config/divar.php` (`DB_HOST`, `DB_PORT`,
  `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` environment variables).

## Configuration
Configuration files include:
- `config/balad.php`: Main configuration settings
- `config/categories.php`: Available place categories
- `config/provinces.php`: Province and city mappings
- `config/cities.json`: City list with Divar slugs and Divar city ids
- `config/divar.php`: Divar settings, including the `collect` block
- `database/schema.sql`: Database schema (`contacts`, `accommodations`, `call_logs`)
- `.env`: Environment variables for database and application settings

## Call Tracking
When a user clicks the "call" button on a place result, the action is logged to the database:
- Call data is stored in the `call_logs` table
- Information stored includes: place ID, phone number, city, category, timestamp, IP address, and user agent
- Database connection is configured via the `.env` file
- All call data is sanitized before database insertion for security
