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
5. When the user clicks **Save this accommodation** on a Balad result, only
   that place is upserted into `accommodations` (`provider = 'balad'`, `external_id` is the
   Balad place token). Its first valid phone is normalized and upserted into
   `contacts`, and `accommodations.contact_id` links to that contact. The full
   source telephone value remains in provider JSON. Repeated saves reuse contacts
   and update the selected place instead of creating duplicates. Other providers
   keep their existing behavior.

## Divar Phone Persistence (`/search_place`)
When **Divar** is selected, each result has **one** action: **دریافت شماره تماس**.
There is no separate save button and no call-log button (same as Balad: saving
the listing is enough). Clicking that button fetches the phone from Divar, then
stores the ad and its contact. After a successful save the card is marked
**ذخیره شده**, the phone is shown in the result, and the fetch button is
removed so the number cannot be requested again.

If the ad is already in `accommodations` with a contact phone, search hydrates
that phone onto the card, shows it as stored, and does not render the fetch
button. A later `get_phone` request for the same token returns the stored
number without calling Divar.

- The server remembers a bounded snapshot of the searched ads in the PHP
  session (`DivarSearchAdStore`); the browser only sends the ad token. Searching
  alone does not insert listings or contacts.
- The phone is normalized (Persian/Arabic digits and Iranian international
  prefixes) and inserted into `contacts`, or the existing contact with that
  phone is reused. Contact names and notes are not overwritten.
- A new ad is inserted into `accommodations` with its full search data and
  `contact_id`. If `(provider, external_id)` already exists, **only its
  `contact_id` is updated**; its other data stays unchanged. Changing an ad's
  phone does not change a shared contact's phone or other ads' contact links.
- Both writes run in one transaction. A failed save rolls back and returns a
  Persian error instead of reporting success. The tables are prepared using
  the existing `Schema::ensureTables()` mechanism.
- Unknown/expired ad snapshots ask the user to repeat the search. The session
  retains up to 240 recently viewed ads, rather than an entire result set.
- Failed Divar phone requests (including authentication/access failures,
  network errors, invalid responses, or no phone in the response) display:
  «وارد سایت دیوار شوید و کپجا را حل کنیدتا دسترسی شما باز شود».
  These provider failures show the message instead of reopening the OTP modal;
  the initial login flow and database-save error messages remain unchanged.
- Phone fetch steps and failures are written to `storage/logs/divar_phone.log`
  (and `storage/logs/app.log`) at the project root, not under `public/`.
  Cookie values are never stored. Use that file when a number does not appear.
- An expired Divar JWT (`Jwt is expired` / 401) clears the dead session cookies
  and reopens the existing phone/OTP login so the user can get a fresh token.
  After a successful OTP the pending **دریافت شماره تماس** request is retried.

### Indexes for the stored-phone lookup
No extra index was added. The current page's tokens (typically 24) are looked
up with `WHERE provider = ? AND external_id IN (...)`, which is exactly the
unique key `uq_provider_external_id (provider, external_id)`. The phone is
then read through `accommodations.contact_id` (`idx_accommodations_contact_id`)
joining `contacts.id` (primary key). `contacts.phone` is already unique. A
second `(provider, external_id)` index would only duplicate that unique key.

This does not change `/divar_collect`: that page still collects ads without
fetching phone numbers, and later collector reruns preserve `contact_id`.

### Regression tests
Run with PHP 8.2+ and the project's PHP extensions:

```sh
sh tests/run.sh
```

The tests cover snapshot mapping/limits, contact reuse, phone normalization,
contact-only listing updates, stored-phone lookup, transaction rollback,
schema initialization, and search/phone controller responses (including a
stored listing that shows its phone instead of the fetch button). Database
and Divar I/O are mocked, so the suite needs no credentials and never
contacts Divar or a production database.

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
Divar's search API needs the numeric city id (`city_ids`). `config/divar/cities.json`
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
automatically before a selected Balad place is saved or a Divar collection
starts (`Src\Support\Schema::ensureTables()`),
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
- `call_logs`: leftover table. Search no longer writes call-log records;
  saving the accommodation (and its contact phone) is enough.

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
- `config/divar/cities.json`: City list with Divar slugs and Divar city ids
- `config/divar.php`: Divar settings, including the `collect` block
- `database/schema.sql`: Database schema (`contacts`, `accommodations`, `call_logs`)
- `.env`: Environment variables for database and application settings

## Call Tracking
Call logging is disabled for every search provider. Balad and the other map
providers save the selected accommodation; Divar saves the ad when its phone
is fetched. The `call_logs` table is kept for older rows but is no longer
written to.
