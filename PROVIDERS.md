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
| Vilayar (ویلایار) | Candidate (probed ✓) | AJAX hybrid | None (session + page token) | Public on detail page, no login | ✅ Addable | Medium |
| Makanchi (مکانچی) | Candidate (probed ✓) | Server HTML scrape | None | Public `tel:` on detail page, no login | ✅ Addable | Small |
| Eforosh (ای‌فروش) | Candidate (probed ✓) | Server HTML scrape | None | Plain text in list HTML | ⚠️ Addable, thin inventory | Small–Medium |
| Mrestate (مستراستیت/آقای املاک) | Candidate (probed ✓) | Internal JSON API (Next.js `/_next/data`) | None for search; login likely for full phones | Masked (`0912***…`) until reveal/login | ⚠️ Addable, phone blocked | Medium |
| Behtarino (بهترینو) | Candidate (probed ✓) | Server HTML scrape | None | Embedded in detail JSON/meta, no login | ⚠️ Addable, low value (no prices) | Medium |
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
- **Auth:** None for search (needs numeric `city_id` from `config/cities.json` — a city without
  one cannot be searched, by design no Tehran fallback). Phone fetch needs login cookies
  obtained via the in-app OTP flow (`divar_send_otp` / `divar_verify_otp`).
- **Phone:** `POST api.divar.ir/v8/postcontact/web/contact_info_v2/{token}` after login.
  Saved transactionally: `contacts` row reused by phone, `accommodations.contact_id` linked —
  search alone never writes listings (`DivarSearchAdStore` keeps a 240-ad session snapshot).
- **Mapping:** `config/cities.json` (`city_id` + `divar_slug`); own category list in
  `config/divar.php` (`temporary-rent`); `DivarAdMapper` parses Persian prices
  (`۱٬۵۰۰٬۰۰۰ تومان` → `1500000`).
- **Code:** `src/Divar/*` + `DivarController` (search+phone) + `DivarCollectController`
  (step-wise harvest into `accommodations`, resumable via `HarvestJobStore`, schema auto-applied).
- **Notes:** The most complex provider; its collect pipeline is Divar-specific.

## 5. Vilayar (ویلایار) — candidate, probed ✓

- **Site:** Villa/Bookmark-style rental marketplace (`vilayar.com`). High relevance.
- **Type:** AJAX hybrid. Page 1 is server HTML (20 `article.vila` cards baked in);
  pages 2+ are JSON from the **same** `/search?...` URL plus
  `{_token, ajaxRequestType: "villaList", seed: "42546"}` and header
  `X-Requested-With: XMLHttpRequest` (verified live: 40 items on page 2 for مازندران).
- **Token:** Per-session CSRF from `input[name=_token]` — client must `GET` the page first
  (keeping cookies), extract the token, then call JSON. `seed` is hardcoded in their JS.
- **Record fields:** `id, villa_title, cityTitle, rent_daily_price_from, finalPrice,
  has_discount, avgScore, bed/bedroom counts, foundation_area, latitude, longitude,
  villa_address, villa_slug, villa_type_id, estate_type, main_img_dir`, … (~50 fields).
- **Pagination:** `?page=N` (HTML count 20 vs JSON count ~40 — dedupe by `id`).
- **Phone:** Public on `/VillaDetails/{id}`, no login: `tel://09369611987`, SMS link,
  owner name + villa code in `.box-property` (verified on id `1885`).
- **Categories:** DECIDED — use Vilayar's own types when selected (like Divar does):
  جنگلی=1، ساحلی=2، ییلاقی=3، شهرکی=5، کوهستانی=6، استخردار=7، چسبیده‌به‌دریا=8،
  شهری=9، روستایی=10، اجاره‌سال=11 (+ `estateType=1` = اجاره ویلا).
- **City mapping needed:** Persian city → vilayar numeric `(state, city)` pairs
  (e.g. مازندران=89، سوادکوه=340، تهران=71). Same role as `cities.json` for Divar.
- **Phone mode (open):** on-demand button (like Divar `get_phone`) vs eager detail fetch
  per card (20 extra requests/page — slow). On-demand recommended.
- **Verdict:** ✅ Addable, medium effort. Plain HTTP (`requests`/cURL), no browser, no key.

## 6. Makanchi (مکانچی) — candidate, probed ✓

- **Site:** Non-hotel stays (villa, suite, ecolodge/bomgardi, cottage) with nightly prices. High relevance.
- **Type:** Server HTML scrape. Stable URL schemes: cities `List-Tehran-1` / `List-Isfahan-79`,
  types `List-villa` / `List-ecolodge` / `List-studio` / `List-apartments` / `List-cottage` /
  `List-rural-house`, query filters (`minPriceRange, maxPriceRange, minBedRoomCount, minCapacity`).
- **Pagination:** Classic numbered links, ~30/page, param name is Persian: `?صفحه=2`.
- **Phone:** Not in lists; fully visible on detail pages (`/{Type}/{id}-{slug}`),
  e.g. `تماس بگیرید 09127197303 (مهین قدیری)` as `tel:` link. No login.
- **Auth/anti-bot:** None observed. All fetches succeeded.
- **Mapping needed:** Makanchi city IDs (`Tehran-1`, `Ramsar-392`, …).
- **Verdict:** ✅ Addable, small effort. Easiest candidate: plain HTTP scrape, open phones.

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

## 9. Behtarino (بهترینو) — candidate, probed ✓

- **Site:** Local-business directory/reviews (2M businesses), Next.js SSR. Has `اقامتگاه`
  (`/r/اقامتگاه/تهران`, sub `/r/اقامتگاه-بومگردی/تهران`) but mixes hotels, مسافرخانه،
  خوابگاه — mostly cheap guesthouses, **no prices anywhere**. Relevance medium–low.
- **Type:** Server HTML scrape, `?page=N`, 20/page.
- **Phone:** Detail-only, behind a "مشاهده شماره تماس" button — but the number is embedded
  in SSR data (`"phoneNumbers":["09384096996",…]`, meta description, WhatsApp link),
  so no extra call or login needed. Address + lat/lng also embedded.
- **Verdict:** ⚠️ Scraping is easy (medium: two-step crawl), but no prices and noisy
  categories make it low value here. Skip unless price becomes optional.

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

## Open decisions (Vilayar)

1. ~~Categories~~ — decided: Vilayar's own types when selected.
2. City coverage — all provinces at once, or north (مازندران/گیلان) + تهران first?
   (Needs the state→city ID table built per covered province.)
3. Phone mode — on-demand button (recommended, Divar-style) or eager per-card fetch?
