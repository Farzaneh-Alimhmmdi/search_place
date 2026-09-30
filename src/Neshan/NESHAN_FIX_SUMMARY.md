# Neshan search and pagination

The Neshan website presents search results in an infinite-scrolling list. It does
not provide a dependable numbered-page count, so this integration derives pages
by scrolling that list and uses a look-ahead result to decide whether another
page exists.

## What changed

- Search requests now use the exact Persian query `category در city` and open
  only that search URL (no extra homepage request).
- Results are extracted from Neshan place links and their individual cards,
  instead of scanning every DOM element. The scraper uses stable Neshan place
  IDs and filters only cards whose displayed type is clearly non-accommodation.
  It keeps Neshan-ranked results with unknown types and does not drop other
  lodging types solely because their label differs from the selected filter.
- The first request loads the requested page plus one page of look-ahead. Later
  requests use a short-lived cache, so clicking **بارگذاری نتایج بیشتر** does
  not immediately make another Neshan search request. The UI appends the next
  page while retaining earlier cards; ordinary form navigation still works
  without JavaScript.
- The pagination indicator is based on the real presence of a look-ahead result.
  Neshan does not publish an exact total, so the interface does not label the
  current page size as the total result count.
- PHP starts Python with an argument array (not nested shell quoting), captures
  errors, and enforces a process timeout. Empty/broken responses no longer look
  like successful searches.
- An already-installed Chrome/Chromium is detected and reused first, including
  Chrome's standard Windows installation paths. Set `CHROME_PATH` when the
  browser is installed in a custom location. Playwright's downloaded Chromium
  is used only when no local browser is found.

## Install / run

```bash
pip install -r src/Neshan/requirments
```

An installed Chrome/Chromium is reused when available. If the machine has no
system browser, install Playwright's browser binary as well:

```bash
python -m playwright install chromium
```

On slow or proxied networks, set `PLAYWRIGHT_DOWNLOAD_CONNECTION_TIMEOUT` (in
milliseconds) and configure `HTTPS_PROXY` if needed before installing. The
Python script caches results for 10 minutes under the system temporary
folder. If Neshan returns HTTP 429, the app displays a retry-later error rather
than presenting a misleading empty result list. Neshan may still limit access;
this project does not bypass that limit.

## Tests

Run the scraper's parsing, relevance-filtering, and pagination tests without a
browser or network connection:

```bash
python -m unittest discover -s tests -v
```
