# Search Place - Rules and Guidelines

## General Rules

### 1. Provider Selection
- Users must select one provider from the available list: بلد, نشان, دیوار, گوگل مپ
- Each provider has its own API endpoint and data structure
- The selected provider determines which search service is used

### 2. Data Handling
- All user inputs must be validated before processing
- City names should match those in the config/provinces.json file or config/provinces.php
- Category values must correspond to predefined options in categories.php
- Error messages should be displayed in Persian (Farsi)

### 3. Search Process
- Searches are executed in two steps:
  1. Initial search to get location tokens/IDs
  2. Preview-bulk call to fetch detailed information using tokens
- Maximum of 3 retries allowed for failed requests
- Request delay of 1 second between consecutive calls
- Timeout set to 30 seconds with connect timeout of 10 seconds

### 4. User Interface
- Form fields must include proper labels in Persian
- Dropdown selections should show "-- انتخاب --" as default option
- Results display in a responsive grid layout
- Cards should show all available information including images when present
- Links to external platforms should open in new tabs

### 5. Security Considerations
- All user inputs must be sanitized before use
- URLs should be properly encoded using rawurlencode()
- External links should use target="_blank" attribute
- No sensitive data should be logged or exposed

### 6. Performance Optimization
- Cache frequently accessed data where possible
- Implement pagination for large result sets
  (exception: the collection page `/divar_collect`, see rule 13 - it stores
  everything in the database and shows no list at all)
- Use efficient data structures for quick lookups
- Minimize unnecessary API calls by reusing existing data
- Never hold a whole result set in PHP memory: process and store it in batches

### 7. Error Handling
- Display user-friendly error messages in Persian
- Log errors to storage/logs directory
- Handle network failures gracefully with retry logic
- Provide fallback options when primary provider fails

### 8. Code Organization
- Follow PSR-4 autoloading standards
- Keep controllers thin and delegate business logic to services
- Use dependency injection for better testability
- Maintain clear separation of concerns between components

### 9. Testing Requirements
- Test all major functionality paths
- Verify edge cases like empty results and invalid inputs
- Ensure backward compatibility with existing features
- Check responsiveness across different screen sizes

### 10. Documentation Standards
- Document all public methods and classes
- Include inline comments explaining complex logic
- Update README.md when adding new features
- Maintain changelog for version tracking

### 11. Code Implementation Confirmation
- **Every code change must be confirmed by the project owner before implementation**
- No production code should be committed or deployed without explicit approval
- All new features, bug fixes, and modifications require review and sign-off
- This rule ensures consistency, quality, and alignment with project objectives
- Changes should be submitted for review with clear documentation of what and why

### 12. Saving instead of call tracking
- Search providers must not write to `call_logs`. Saving the accommodation
  (and its contact phone) is enough
- Balad, Neshan and Google Maps keep a single **Save this accommodation**
  button
- Divar keeps a single **دریافت شماره تماس** button. After a successful fetch
  the phone is stored and the card is shown as **ذخیره شده**; the fetch button
  must not come back
- If a Divar ad is already in `accommodations` with a contact phone, search
  must show that phone in the result and must not render the fetch button

### 13. Divar Collection Page (`/divar_collect`)
- The selection inputs must stay identical to the Divar search page: province,
  city, category and the optional keyword
- This page stores the results in the database instead of displaying them: no
  result cards and no pagination (approved exception to rule 6)
- Divar collection writes only to `contacts` and `accommodations`
  (see `database/schema.sql`). The separate `call_logs` table is only for
  call tracking and must not be used by the collector. The collector must not
  add its own bookkeeping table; run state lives in the PHP session
- Large result sets must be harvested in small steps: one HTTP request fetches
  exactly one Divar page and writes that one batch
- Divar's cursor is kept server side (PHP session) so a run can be paused and
  resumed, also after a page reload
- Every write must be an upsert on the unique key `(provider, external_id)`, so
  re-running or resuming a search can never create duplicates
- Values that later steps fill in (`contact_id`, `latitude`, `longitude`,
  `price`) must not be overwritten with NULL by a re-run of the collector
- A failed step must not move the cursor forward, so the same page can simply be
  fetched again
- Phone numbers are NOT collected here; that is a later step which writes into
  `contacts` and links `accommodations.contact_id`
- Progress, counters and errors are reported to the user in Persian
