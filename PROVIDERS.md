# Providers — Integration Details

How each place provider works, what it needs, and whether it can (or does)
plug into this system. Think of this as the field guide for adding provider #6, #7, …

All user-visible strings for any provider must be Persian (project rule).
Technical identifiers (table names, file paths, `HTTP`/`JSON`/`URL`) stay Latin.

## Integration types (legend)

| Type | Meaning | Example here |
|---|---|---|
| Official REST API | Documented, versioned, key-based, stable contract | Google Places (with key), Neshan official `api.neshan.org` (needs key) |
| Internal JSON API | Undocumented endpoint built for the site's own frontend; plain JSON, usually needs session/token replay | Divar `api.divar.ir`, mrestate `/_next/data/...json` |
| Internal encoded API | Internal JSON plus a custom envelope/obfuscation you must reverse | Neshan web `pwa-api` (base64 envelope, `uuid` + `X-Client-Version`) |
| Server HTML scrape | Classic site: full-page GETs, data baked into HTML; parse with DOM/regex | eforosh, makanchi, behtarino, niazerooz (if reachable) |
| AJAX hybrid | Page 1 is server HTML, pages 2+ are JSON via an XHR action on the same URL | **Vilayar** (`ajaxRequestType=villaList`) |
| Browser automation | Only reachable with a real browser (bot gates, JS rendering) | niazerooz (YARP gate), istgah (hard blocks) |

Rule of thumb: official REST > internal JSON > HTML scrape > AJAX hybrid >
internal encoded > browser automation. Fragility grows to the right, because
undocumented endpoints can change without notice.

---

## Summary

| Provider | Status | Type | Auth needed | Phone | Verdict | Effort |
|---|---|---|---|---|---|---|
| Balad (بلد) | Live | Official-ish REST (JSON) | None | In detail response | ✅ In use | — |
| Neshan (نشان) | Live | Internal encoded API (our `neshan_search.py` hits `pwa-api`) | None (no key; `uuid` header only) | In search response (`actions[type=CALL]`) | ✅ In use | — |
| Google Map (گوگل‌مپ) | Live | Official REST if key present, else scraping fallback | API key for reliable mode | Via Places Details (key mode) | ✅ In use (needs key for quality) | — |
| Divar (دیوار) | Live | Internal JSON API (`api.divar.ir/v8/...`, cursor pagination) | None for search; login cookies (OTP) for phones | Via `contact_info` endpoint after OTP login | ✅ In use | — |
| Vilayar (ویلایار) | Live | AJAX hybrid (plain HTML pages + per-card detail) | None | Public `tel:` on detail page, no login | ✅ In use | — |
| Makanchi (مکانچی) | Live | Server HTML scrape | None | Public `tel:` on detail page, no login | ✅ In use | — |
| Eforosh (ای‌فروش) | Candidate (probed ✓) | Server HTML scrape | None | Plain text in list HTML | ⚠️ Addable, thin inventory | Small–Medium |
| Mrestate (مستراستیت/آقای املاک) | Candidate (probed ✓) | Internal JSON API (Next.js `/_next/data`) | None for search; login likely for full phones | Masked (`0912***…`) until reveal/login | ⚠️ Addable, phone blocked | Medium |
| Behtarino (بهترینو) | Live | Server HTML scrape (JSON-LD + per-card detail) | None | Embedded in detail data/meta, no login | ✅ In use | — |
| Niazerooz (نیازروز) | Candidate (probed ✓) | Server HTML scrape | None, but JS-gate blocks plain HTTP | Public `tel:` on detail page, no login | ⚠️ Needs browser/JS-capable fetch | Medium |
| Istgah (ایستگاه) | Candidate (probed ✓) | Unknown (all server fetches refused) | Unknown | Unknown | ❌ Not feasible now | Large / risky |

---

## 1. Balad (بلد) — live

- **Type:** Official-ish REST (JSON). Two-step flow: `GET search.raah.ir/v4/placeslist/cat/?region=city-{slug}&name={category}&page=N`
  returns tokens → `GET poi.raah.ir/web/v4/preview-bulk/{tokens}` returns full details.
- **Auth:** None.
- **Pagination:** Normal `page` param with `page_count`.
- **Phone:** Comes inside the preview-bulk detail payload (`telephone`).
- **Mapping:** City slug via `config/provinces.php`; categories via `config/categories.php` (shared).
- **Code:** `src/Balad/{BaladClient,BaladSearchService,BaladPlaceMapper}.php` + `BaladController`.
- **Notes:** The reference implementation — a new REST-like provider should copy this shape.

## 2. Neshan (نشان) — live

- **Type:** Internal encoded API. Our `src/Neshan/neshan_search.py` calls the website's own
  `GET neshan.org/maps/pwa-api/neshan-search?body={...}&search-in-bound=false` directly —
  no browser, no API key. (There is also an official REST API, `api.neshan.org/v1/search` +
  `v1/point?hash=`, but it needs a paid key with only a 200k-Toman/3-month trial, so we don't use it.)
- **Encoding (reversed from their JS):** request body is `base64(encodeURIComponent(JSON))`;
  responses are wrapped as `slice(28,-28)` → split at last `@` → `base64(tail+head)` → JSON
  (modules `32165`/`44640` in their bundle). See `pwa_encode`/`pwa_decode` in `neshan_search.py`.
- **Auth:** None — only a random `web_{uuid}` in the `uuid` header plus `X-Client-Version: 2035`.
- **Pagination:** `limit` param (max 50); our script slices pages client-side.
- **Phone:** Already inside the search response — `items[].actions[type=CALL].metaData.phone`
  (e.g. `09125863998`). No extra click or detail fetch. Website comes from `actions[type=BROWSER]`.
- **Mapping:** City center bias table (`CITY_CENTERS` in the script; term already contains the
  city name). Categories: shared `categories.php`.
- **Code:** `NeshanClient.php` (spawns the Python script) + `NeshanSearchService.php` + `NeshanController`.
- **Fragility:** If they rotate `X-Client-Version` or change the envelope, search breaks until
  the decoder is updated. Failures must stay Persian and graceful.

## 3. Google Map (گوگل‌مپ) — live

- **Type:** Official REST **if** an API key is configured (`maps.googleapis.com/maps/api/place/textsearch/json`
  with `language=fa&region=ir`); otherwise a scraping fallback (unreliable).
- **Auth:** API key for quality mode; none for fallback.
- **Phone:** Via Places Details in key mode; spotty in fallback.
- **Mapping:** `config/google_map.php` (`city_slugs` reuse shared map).
- **Code:** `src/GoogleMap/{GoogleMapClient,GoogleMapSearchService}.php` + `GoogleMapController`.
- **Notes:** Quality depends entirely on having a key. Without one, expect gaps.

## 4. Divar (دیوار) — live

- **Type:** Internal JSON API: `POST api.divar.ir/v8/postlist/w/search` with
  `{city_ids, category, query, pagination_data}`. Cursor pagination (base64url-encoded
  `PaginationData`, one page per PHP request, cursors kept in `$_SESSION`).
- **Auth:** None for search (needs numeric `city_id` from `config/divar/cities.json` — a city without
  one cannot be searched, by design no Tehran fallback). Phone fetch needs login cookies
  obtained via the in-app OTP flow (`divar_send_otp` / `divar_verify_otp`).
- **Phone:** `POST api.divar.ir/v8/postcontact/web/contact_info_v2/{token}` after login.
  Saved transactionally: `contacts` row reused by phone, `accommodations.contact_id` linked —
  search alone never writes listings (`DivarSearchAdStore` keeps a 240-ad session snapshot).
- **Mapping:** `config/divar/cities.json` (`city_id` + `divar_slug`); own category list in
  `config/divar.php` (`temporary-rent`); `DivarAdMapper` parses Persian prices
  (`۱٬۵۰۰٬۰۰۰ تومان` → `1500000`).
- **Code:** `src/Divar/*` + `DivarController` (search+phone) + `DivarCollectController`
  (step-wise harvest into `accommodations`, resumable via `HarvestJobStore`, schema auto-applied).
- **Notes:** The most complex provider; its collect pipeline is Divar-specific.

## 5. Vilayar (ویلایار) — live

- **Site:** Villa rental marketplace, province-based search (`/search?state={id}`).
- **Type:** AJAX hybrid, but only the plain-HTML half is used: fully server-rendered
  cards (`article.vila`), no session or token needed. (The JSON `ajaxRequestType=villaList`
  path exists but is unnecessary — plain `?page=N` renders full pages.)
- **Record fields:** title, `place` (province - city), nightly price, rating
  (`data-score`), beds/guests/rooms/area specs, image, `/VillaDetails/{id}` link.
- **Pagination:** classic `?page=N`.
- **Phone:** public `tel:` + owner name on detail pages, no login; fetched eagerly
  per card (~200ms apart, 15s per-detail budget, PHP limit raised to 180s).
- **Categories:** Vilayar's own 10 villa types (`config/vilayar.php`).
- **City mapping:** every province via `config/vilayar/states.json` (all 31 covered,
  read off the site's own dropdown); no city table needed.
- **Code:** `src/Vilayar/{VilayarClient,VilayarSearchService}.php` + `VilayarController`
  (mirrors `MakanchiController` + call logging); parser covered by `tests/vilayar_parse.php`.

## 6. Makanchi (مکانچی) — live

- **Site:** Non-hotel stays (villa, suite, ecolodge/bomgardi, cottage) with nightly prices.
- **Type:** Server HTML scrape. City resolution is dynamic (no city table):
  `POST /Search/SearchForm` (keyword) follows to the canonical list URL
  (`/List-Tehran-1`, `/List-Sari-376`, or `/List?جستجوی=…` for provinces).
  Filters append as query params: `?Category={type}&صفحه={page}` (both verified live).
- **Record fields:** title, city badge, nightly price text, capacity/rooms, image,
  detail link `/{Type}/{id}-{slug}`; phones via per-card detail fetch (`tel:` link, no login).
- **Pagination:** `?صفحه=N` (Persian param), ~30 cards/page; highest page parsed from links.
- **Categories:** Makanchi's own 12 types (`config/makanchi.php`), shown when selected.
- **Phone mode:** fetched during search (one detail request per card, ~200ms apart,
  15s per-detail budget, PHP limit raised to 120s for the search).
- **Code:** `src/Makanchi/{MakanchiClient,MakanchiSearchService}.php` + `MakanchiController`
  (mirrors `NeshanController` + call logging); parser covered by `tests/makanchi_parse.php`.

## 7. Eforosh (ای‌فروش) — candidate, probed ✓

- **Site:** General classifieds with a `مسکن (/res)` branch incl. `ویلا` (451+ ads) and
  `اجاره مسکونی`. Relevance low–medium: thin/stale short-stay inventory, sale spam.
- **Type:** Server HTML scrape. Category browse `/res/ویلا-c`, keyword `/key/اجاره-ویلا`.
  No city/price params — category + keyword only.
- **Pagination:** `-pN` URL suffix (`/res/ویلا-c-p2`), 20/page.
- **Phone:** Fully visible in list HTML as plain text (`تماس: 09121574300`). No masking, no login.
- **Weaknesses:** No structured city/price (free text, often `توافقی`), stale ads, spam.
- **Verdict:** ⚠️ Technically easy (small–medium) but low data value. Add only if coverage > quality.

## 8. Mrestate (مستراستیت) — candidate, probed ✓

- **Site:** Dedicated real-estate portal with native rental taxonomy
  (`/f/tehran/rent_residential_villa|suite|apartment`, 1,906 Tehran villa rentals,
  daily/furnished filters, neighbourhood slugs). High relevance.
- **Type:** Internal JSON API (Next.js): `/_next/data/{buildId}/f/{city}/rent_residential_{type}.json`
  (verified working; page size 18, envelope `{count_all, count, previous, next}`,
  exact prices/areas/rooms/images). `buildId` rotates per deploy — re-extract from homepage
  HTML or `__NEXT_DATA__` at runtime.
- **Phone: BLOCKED.** Masked everywhere (`091237***31`) including detail HTML
  (`__NEXT_DATA__`: `"showFilePhoneNumber":{"show":false}`). Full number needs
  click-to-reveal → almost certainly login/session + XHR (endpoint not yet captured —
  needs one logged-in browser-network session).
- **Auth:** None for search; login likely for phones.
- **Verdict:** ⚠️ Addable for listings (medium), but pointless for this app until the
  phone-reveal flow is cracked. Park unless a login session can be arranged.

## 9. Behtarino (بهترینو) — live

- **Site:** Local-business directory with an `اقامتگاه` section (guesthouses, suites,
  ecolodges) plus `اقامتگاه-بومگردی`, `مراکز-اقامتی`, `متل`. No prices anywhere.
- **Type:** Server HTML scrape. List pages (`/r/{type}/{city}?page=N`, 20/page)
  embed listings as JSON-LD `ItemList` (name, full address, geo, rating, images,
  `/p/{hash}` detail URL) — parsed directly, no fragile card scraping.
- **Cities:** the site has no province pages, so each province maps to 1–3 cities
  (`config/behtarino/cities.json`, every slug verified live); city results are
  merged and deduplicated per page.
- **Phone:** detail-only, embedded in the page (`"phoneNumbers":[…]` data first,
  then meta description `تلفن: …`), no login and no extra endpoint. Fetched eagerly
  per card (~200ms apart, 15s per-detail budget, PHP limit raised to 240s).
- **Categories:** Behtarino's own 4 sections (`config/behtarino.php`).
- **Code:** `src/Behtarino/{BehtarinoClient,BehtarinoSearchService}.php` +
  `BehtarinoController` (call logging + save flow included);
  parser covered by `tests/behtarino_parse.php`.

## 10. Niazerooz (نیازروز) — candidate, probed ✓

- **Site:** General classifieds with a dedicated villa-rental branch (`/c-935` اجاره ویلا,
  15 pages × ~20) + keyword search (`/keys/{kw}`, 82 ads for اجاره-ویلا). High relevance.
- **Type:** Server HTML scrape (`.classic-order-box`: title/link/city/image, no price/phone
  in lists; price lives in free-text descriptions).
- **Phone:** Public `tel:` on detail pages (`/a-{id}`), no login.
- **Blocker:** YARP JS-gate ("در حال انتقال…", `requestgate?token=…`, fallback "من ربات نیستم"
  checkbox) blocks plain HTTP fetchers; `robots.txt` allows only big crawlers.
  A JS-capable fetcher (real browser or proxy) passes fine.
- **Verdict:** ⚠️ Addable only with browser/JS-capable fetching (medium). Revisit when a
  browser path exists; plain cURL won't pass the gate.

## 11. Istgah (ایستگاه) — candidate, probed ✓

- **Site:** General classifieds, estate branch exists but **no dedicated rental/short-stay
  section found**; only sale-type villa ads seen. Relevance low–medium.
- **Blocker:** Every server-side fetch fails (transport errors on all hosts/schemes,
  Google Translate proxy refused with 400, Common Crawl now 403 where 2025 crawls got 200).
  Search params, pagination, and phone placement all unconfirmed.
- **Verdict:** ❌ Not feasible now. Revisit only with real-user browser egress; even then,
  integration value is speculative.

---

## What adding a provider requires in this codebase

Mirrors how Neshan (closest analog: web-origin data + phone) was added:

1. `config/{provider}.php` — endpoint, timeouts, user-agent, own category list (Vilayar/Divar
   pattern) or shared `categories.php`, city map file, DB credentials (shared keys).
2. City map file (e.g. `config/vilayar_cities.json`) — Persian city → provider IDs.
   Same role as `cities.json` (`city_id`) for Divar.
3. `src/{Provider}/{Provider}Client.php` — raw fetching (HTTP session, tokens, pagination).
4. `src/{Provider}/{Provider}SearchService.php` — normalize to the standard place shape
   (`id/name/address/telephone|phone/website/category/latitude/longitude/image_preview/
   {provider}_url/rating/instagram_id`).
5. `src/Controller/{Provider}Controller.php` — city+category+page flow + call logging
   (copy `NeshanController`, ~200 lines).
6. `public/index.php` — one `elseif ($provider === '...')` branch.
7. `src/View/SearchView.php` — Persian label, dropdown option, `provider-{x}` CSS tag,
   `{provider}_url` "مشاهده در … ↗" link block (keep Persian-only rule + Persian digits).
8. `src/Support/ProviderAccommodationMapper.php` — add to `PROVIDERS`, id rule, url field,
   fallback title prefix.
9. `tests/` — mocked search/phone cases (DB + HTTP mocked, no credentials).
10. README + this file — document the new provider.

## Open decisions (Vilayar — resolved)

1. Categories — Vilayar's own 10 villa types when selected. ✅
2. City coverage — all 31 provinces via `config/vilayar/states.json` (state-level
   search, no city table needed). ✅
3. Phone mode — eager per-card fetch (like Makanchi). ✅
