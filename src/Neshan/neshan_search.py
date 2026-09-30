#!/usr/bin/env python3
"""Search Neshan Maps and return relevant place cards as JSON for PHP.

Neshan's public map UI uses an infinite-scrolling result list rather than
numbered pages. This script reads actual place links from that list, scrolls it
until the requested slice (plus a look-ahead item) is available, and caches the
loaded results briefly so moving to the next page does not open another browser.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import sys
import tempfile
import time
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Any, Optional
from urllib.parse import quote, urljoin, urlparse

if sys.platform == "win32":
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding="utf-8", errors="replace")


@dataclass(frozen=True)
class Category:
    value: str
    label: str


@dataclass
class PlaceResult:
    place_id: str
    name: str
    address: Optional[str] = None
    phone: Optional[str] = None
    website: Optional[str] = None
    category: Optional[str] = None
    latitude: Optional[float] = None
    longitude: Optional[float] = None
    rating: Optional[float] = None
    instagram_id: Optional[str] = None
    neshan_url: Optional[str] = None


class NeshanCategories:
    """Load the categories offered by the PHP search form."""

    CATEGORIES_PHP_PATH = Path(__file__).resolve().parents[2] / "config" / "categories.php"
    DEFAULT_CATEGORIES = [
        Category("hotel", "هتل"),
        Category("motel", "متل"),
        Category("guest-house", "مهمانسرا"),
        Category("hostel", "هاستل"),
        Category("vernacular-accommodation", "بوم‌گردی"),
        Category("apartment", "آپارتمان"),
        Category("villa", "ویلا"),
    ]

    @classmethod
    def load_categories(cls) -> list[Category]:
        try:
            content = cls.CATEGORIES_PHP_PATH.read_text(encoding="utf-8")
            pattern = r"'value'\s*=>\s*'([^']+)',\s*'label'\s*=>\s*'([^']+)'"
            matches = re.findall(pattern, content)
            if matches:
                return [Category(value, label) for value, label in matches]
        except (OSError, UnicodeError):
            pass
        return cls.DEFAULT_CATEGORIES


# The Neshan search box sometimes returns text matches which are not places of
# the selected type. Match the card's own type/name and discard a card when its
# displayed type clearly belongs to another category.
CATEGORY_TERMS: dict[str, tuple[str, ...]] = {
    "hotel": ("هتل",),
    "motel": ("متل",),
    "guest-house": (
        "مهمانسرا", "مهمان سرا", "مهمانپذیر", "مهمان پذیر", "مسافرخانه", "خانه مسافر", "اقامتگاه",
    ),
    "hostel": ("هاستل", "خوابگاه", "پانسیون"),
    "vernacular-accommodation": ("بوم گردی", "بومگردی"),
    "apartment": ("هتل آپارتمان", "آپارتمان"),
    "villa": ("ویلا", "خانه ویلایی"),
    "suite": ("سوئیت",),
    "cottage": ("کلبه",),
}

# More specific types come before broader types (e.g. "hotel apartment" before
# "hotel") so a hotel-apartment card is not misclassified as a hotel.
PLACE_KIND_TERMS: tuple[tuple[str, tuple[str, ...]], ...] = (
    ("apartment", ("هتل آپارتمان", "آپارتمان")),
    ("vernacular-accommodation", ("بوم گردی", "بومگردی")),
    ("guest-house", ("مهمانسرا", "مهمان سرا", "مهمانپذیر", "مهمان پذیر", "مسافرخانه", "خانه مسافر", "اقامتگاه")),
    ("hostel", ("هاستل", "خوابگاه", "پانسیون")),
    ("motel", ("متل",)),
    ("hotel", ("هتل",)),
    ("villa", ("ویلا", "خانه ویلایی")),
    ("suite", ("سوئیت",)),
    ("cottage", ("کلبه",)),
    ("medical", ("مجتمع پزشکی", "مرکز پزشکی", "درمانگاه", "بیمارستان", "کلینیک", "پزشکی", "دندانپزشکی", "داروخانه")),
    ("restaurant", ("رستوران", "کافه", "کافی شاپ", "فست فود", "غذاخوری", "بوفه", "پیتزا")),
    ("retail", ("فروشگاه", "مرکز خرید", "پاساژ", "سوپرمارکت", "مغازه", "بوتیک")),
    ("bank", ("بانک", "خودپرداز", "موسسه مالی")),
    ("education", ("دانشگاه", "مدرسه", "آموزشگاه")),
    ("transport", ("فرودگاه", "ترمینال", "ایستگاه")),
    ("religious", ("مسجد", "حسینیه", "امامزاده", "کلیسا")),
    ("event", ("سمینار", "همایش", "کنفرانس", "جشنواره", "نمایشگاه", "تالار")),
    ("service", ("نیازمندی", "آگهی", "شرکت خدماتی", "دفتر خدمات", "مرکز جامع")),
    ("organization", ("سازمان", "بنیاد", "موسسه", "مؤسسه", "اداره", "دفتر", "گروه", "شرکت", "نهاد", "شهرداری")),
)

NOISE_LINES = {
    "حذف", "باز باشد", "بازباشد", "مسیرها", "وب سایت", "تماس", "ارسال",
    "پشتیبانی", "نظر", "اشتراک", "گزارش", "مشترک", "نقشه", "مسیریاب",
    "دانلود", "برنامه", "نسخه", "جستجو", "جستجوی", "امتیاز", "رای",
    "بازدید", "محبوب", "جدید", "پیشنهاد", "آگهی", "مسیر", "وب سایت",
}

ADDRESS_MARKERS = (
    "خیابان", "میدان", "کوچه", "بلوار", "محله", "پلاک", "منطقه", "جاده",
    "بزرگراه", "طبقه", "ساختمان", "مجتمع", "شهرک", "استان",
)
PHONE_PATTERN = re.compile(r"(?<!\d)(?:\+?98|0)\d{9,10}(?!\d)")

PERSIAN_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")
PERSIAN_TEXT = str.maketrans({"ي": "ی", "ى": "ی", "ك": "ک", "ـ": "", "\u200c": " "})


def find_system_browser(platform_name: Optional[str] = None) -> Optional[str]:
    """Find a browser already installed on the machine before downloading one."""
    platform_name = platform_name or sys.platform
    candidates: list[str] = []

    configured_path = os.environ.get("CHROME_PATH", "").strip().strip('"').strip("'")
    if configured_path:
        candidates.append(configured_path)

    if platform_name == "win32":
        roots = [
            os.environ.get("ProgramFiles", ""),
            os.environ.get("ProgramFiles(x86)", ""),
            os.environ.get("LOCALAPPDATA", ""),
        ]
        for root in roots:
            if root:
                candidates.extend([
                    str(Path(root) / "Google" / "Chrome" / "Application" / "chrome.exe"),
                    str(Path(root) / "Microsoft" / "Edge" / "Application" / "msedge.exe"),
                ])
    elif platform_name == "darwin":
        candidates.extend([
            "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
            str(Path.home() / "Applications/Google Chrome.app/Contents/MacOS/Google Chrome"),
            "/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge",
        ])
    else:
        candidates.extend([
            "/usr/bin/google-chrome",
            "/usr/bin/google-chrome-stable",
            "/usr/bin/chromium",
            "/usr/bin/chromium-browser",
            "/usr/bin/microsoft-edge",
        ])

    for executable in ("chrome", "google-chrome", "google-chrome-stable", "chromium", "chromium-browser", "msedge"):
        resolved = shutil.which(executable)
        if resolved:
            candidates.append(resolved)

    for candidate in candidates:
        path = Path(candidate).expanduser()
        if path.is_file():
            return str(path)
    return None


def normalize_text(value: Any) -> str:
    """Normalize Persian/Arabic text and digits for comparisons."""
    text = str(value or "").translate(PERSIAN_TEXT).translate(PERSIAN_DIGITS).lower()
    return re.sub(r"\s+", " ", text).strip()


def _has_any(text: str, terms: tuple[str, ...] | list[str]) -> bool:
    normalized = normalize_text(text)
    return any(normalize_text(term) in normalized for term in terms)


def detect_place_kind(lines: list[str]) -> tuple[Optional[str], Optional[str]]:
    """Return the first identifiable Neshan place type in the supplied lines."""
    for line in lines:
        normalized = normalize_text(line)
        matches: list[tuple[int, int, str, str]] = []
        for kind, terms in PLACE_KIND_TERMS:
            for term in terms:
                normalized_term = normalize_text(term)
                position = normalized.find(normalized_term)
                if position >= 0:
                    # Prefer the earliest phrase in a title and the most
                    # specific phrase when two category terms start together.
                    matches.append((position, -len(normalized_term), kind, normalized_term))
        if matches:
            # "اقامتگاه" is intentionally a broad guest-house synonym. When
            # the same line identifies a more specific style (e.g. بوم‌گردی),
            # trust that specific type instead.
            specific_matches = [
                match for match in matches
                if not (match[2] == "guest-house" and match[3] == normalize_text("اقامتگاه"))
            ]
            _, _, kind, _ = min(specific_matches or matches)
            return kind, line.strip()
    return None, None


def is_relevant_record(name: str, card_lines: list[str], category: Category) -> bool:
    """Drop clear non-accommodation results without losing Neshan's ranked hits."""
    detected_kind, _ = detect_place_kind(card_lines[1:] + [name])

    # The form searches accommodation types. Keep all accommodation types that
    # Neshan returns (the app may mix hotels, guest houses, hostels, etc.) rather
    # than forcing an exact type match and dropping its first/ranked result.
    if detected_kind in CATEGORY_TERMS:
        return True

    if detected_kind is not None:
        return False

    # An unclassified place link is still an actual result from Neshan. Do not
    # silently discard it just because its brand name omits the query word.
    # If this searcher is later used for a non-accommodation category, retain
    # the older query-term check for that unsupported category.
    if category.value not in CATEGORY_TERMS:
        all_text = normalize_text(" ".join([name, *card_lines]))
        return _has_any(all_text, (category.label,))
    return True


def _clean_lines(text: str) -> list[str]:
    cleaned: list[str] = []
    for raw_line in (text or "").splitlines():
        line = re.sub(r"\s+", " ", raw_line).strip(" \t\r\n•|·")
        if not line:
            continue
        if normalize_text(line) in NOISE_LINES:
            continue
        if line not in cleaned:
            cleaned.append(line)
    return cleaned


def _extract_place_id(href: str) -> Optional[str]:
    parsed = urlparse(urljoin("https://neshan.org", href or ""))
    match = re.search(r"/maps/places/([^/?#]+)", parsed.path)
    return match.group(1) if match else None


def parse_place_record(record: dict[str, Any], category: Category) -> Optional[PlaceResult]:
    """Turn one place link/card into a stable, filtered result."""
    href = str(record.get("href") or "").strip()
    place_id = _extract_place_id(href)
    if not place_id:
        return None

    link_lines = _clean_lines(str(record.get("link_text") or ""))
    card_lines = _clean_lines(str(record.get("card_text") or ""))
    if not card_lines:
        card_lines = link_lines
    if not link_lines:
        link_lines = card_lines

    name = next(
        (
            line for line in link_lines + card_lines
            if len(line) >= 3
            and len(line) <= 140
            and not line.lower().startswith(("http://", "https://"))
            and normalize_text(line) not in NOISE_LINES
        ),
        None,
    )
    if not name or not is_relevant_record(name, card_lines, category):
        return None

    # Prefer the visible Neshan type line, if present, over echoing the search
    # term as though it were the listing's actual category.
    _, actual_category = detect_place_kind(card_lines[1:] + [name])
    if actual_category is not None and normalize_text(actual_category) == normalize_text(name):
        actual_category = None

    address = next(
        (
            line for line in card_lines
            if line != name
            and line != actual_category
            and any(marker in normalize_text(line) for marker in ADDRESS_MARKERS)
        ),
        None,
    )

    normalized_card = normalize_text(" ".join(card_lines))
    phone_match = PHONE_PATTERN.search(normalized_card)
    phone = phone_match.group(0) if phone_match else None

    rating: Optional[float] = None
    for index, line in enumerate(card_lines):
        normalized_line = normalize_text(line)
        # Neshan often renders the score and review count on adjacent lines:
        # "5" followed by "2 رای". Never mistake the review count for a score.
        if "رای" in normalized_line and index > 0:
            score = normalize_text(card_lines[index - 1]).replace(",", ".")
            if re.fullmatch(r"[0-5](?:\.[0-9]+)?", score):
                candidate = float(score)
                if 0 <= candidate <= 5:
                    rating = candidate
                    break
        score_match = re.search(r"(?:امتیاز\s*)?([0-5](?:[.,][0-9]+)?)\s*(?:از\s*5|★|⭐)", normalized_line)
        if score_match:
            candidate = float(score_match.group(1).replace(",", "."))
            if 0 <= candidate <= 5:
                rating = candidate
                break

    website: Optional[str] = None
    instagram_id: Optional[str] = None
    for link in record.get("external_links", []) or []:
        url = str(link or "").strip()
        lowered = url.lower()
        if "instagram.com/" in lowered:
            match = re.search(r"instagram\.com/([^/?#]+)", url, re.IGNORECASE)
            if match:
                instagram_id = match.group(1).lstrip("@")
        elif lowered.startswith(("https://", "http://")) and not any(
            host in lowered for host in ("neshan.org", "mapbox.com", "neshan.blog")
        ):
            website = url

    if not instagram_id:
        match = re.search(
            r"(?:instagram|اینستاگرام|اینستا)[\s:@]*([a-zA-Z0-9_.]{1,30})",
            " ".join(card_lines),
            re.IGNORECASE,
        )
        if match:
            instagram_id = match.group(1)

    return PlaceResult(
        place_id=place_id,
        name=name,
        address=address,
        phone=phone,
        website=website,
        category=actual_category,
        rating=rating,
        instagram_id=instagram_id,
        neshan_url=urljoin("https://neshan.org", href),
    )


def paginate_results(
    results: list[dict[str, Any]], page: int, page_size: int, complete: bool
) -> tuple[list[dict[str, Any]], bool, Optional[int]]:
    """Return one page, has-more, and an exact total only when the list ended."""
    start = (page - 1) * page_size
    end = start + page_size
    page_results = results[start:end]
    has_more = len(results) > end or (not complete and len(page_results) == page_size)
    total = len(results) if complete else None
    return page_results, has_more, total


class NeshanSearcher:
    """Use the Neshan web search result list as the source of place cards."""

    SEARCH_URL = "https://neshan.org/maps/search"
    CACHE_VERSION = 3
    DEFAULT_CACHE_TTL = 600
    MAX_SCROLL_STEPS = 60
    STABLE_SCROLLS_TO_FINISH = 3

    def __init__(
        self,
        headless: bool = True,
        slow_mo: int = 0,
        cache_ttl: int = DEFAULT_CACHE_TTL,
        max_scroll_steps: int = MAX_SCROLL_STEPS,
    ) -> None:
        self.headless = headless
        self.slow_mo = max(0, slow_mo)
        self.cache_ttl = max(0, cache_ttl)
        self.max_scroll_steps = max(1, max_scroll_steps)

    @staticmethod
    def _cache_path(city: str, category: Category) -> Path:
        cache_root = Path(tempfile.gettempdir()) / "search-place-neshan-cache"
        key = hashlib.sha256(f"{city}\n{category.value}\n{category.label}".encode("utf-8")).hexdigest()
        return cache_root / f"{key}.json"

    def _read_cache(self, city: str, category: Category) -> Optional[dict[str, Any]]:
        path = self._cache_path(city, category)
        try:
            cached = json.loads(path.read_text(encoding="utf-8"))
            age = time.time() - float(cached.get("cached_at", 0))
            if (
                cached.get("version") != self.CACHE_VERSION
                or age < 0
                or age > self.cache_ttl
                or not isinstance(cached.get("results"), list)
            ):
                return None
            return cached
        except (OSError, ValueError, TypeError):
            return None

    def _write_cache(
        self,
        city: str,
        category: Category,
        results: list[dict[str, Any]],
        complete: bool,
    ) -> None:
        path = self._cache_path(city, category)
        try:
            path.parent.mkdir(parents=True, exist_ok=True)
            payload = {
                "version": self.CACHE_VERSION,
                "cached_at": time.time(),
                "complete": complete,
                "results": results,
            }
            temporary_path = path.with_name(f"{path.name}.{os.getpid()}.tmp")
            temporary_path.write_text(
                json.dumps(payload, ensure_ascii=False, separators=(",", ":")),
                encoding="utf-8",
            )
            os.replace(temporary_path, path)
        except OSError as error:
            print(f"Could not write Neshan search cache: {error}", file=sys.stderr)

    @staticmethod
    def _merge_results(
        old_results: list[dict[str, Any]], new_results: list[dict[str, Any]]
    ) -> list[dict[str, Any]]:
        merged: list[dict[str, Any]] = []
        seen_ids: set[str] = set()
        for result in old_results + new_results:
            place_id = str(result.get("place_id") or "")
            if not place_id or place_id in seen_ids:
                continue
            seen_ids.add(place_id)
            merged.append(result)
        return merged

    def search_category(
        self,
        category: Category,
        city: str = "تهران",
        max_results: int = 20,
        page: int = 1,
    ) -> dict[str, Any]:
        page = max(1, page)
        page_size = max(1, max_results)
        page_start = (page - 1) * page_size
        page_end = page_start + page_size
        cached = self._read_cache(city, category)
        cached_results = list(cached.get("results", [])) if cached else []
        cached_complete = bool(cached and cached.get("complete"))

        # If this page plus a look-ahead place is already cached, avoid launching
        # Chromium. A completed cache can also serve the final page exactly.
        cache_is_enough = cached_complete or len(cached_results) >= page_end
        if cache_is_enough:
            return self._page_response(cached_results, page, page_size, cached_complete)

        try:
            from playwright.sync_api import sync_playwright
        except ImportError:
            return {"error": "Playwright is not installed. Install it with: pip install playwright && python -m playwright install chromium"}

        # Load the requested page and one further page in the same browser
        # session. This gives the UI a quick next-page response without having
        # to send another Neshan search request immediately.
        target_count = page_end + page_size + 1
        query = f"{category.label} در {city}".strip()
        search_url = f"{self.SEARCH_URL}/{quote(query, safe='')}"
        scraped_results: list[dict[str, Any]] = []
        scrape_complete = False
        page_error: Optional[str] = None

        try:
            with sync_playwright() as playwright:
                launch_options: dict[str, Any] = {
                    "headless": self.headless,
                    "slow_mo": self.slow_mo,
                    "args": [
                        "--disable-blink-features=AutomationControlled",
                        "--disable-dev-shm-usage",
                        "--no-sandbox",
                        "--disable-gpu",
                    ],
                }
                # Reuse the user's installed Chrome/Chromium (including the
                # default Windows Chrome path used by the original script). Only
                # fall back to Playwright's downloaded browser when none exists.
                chrome_path = find_system_browser()
                if chrome_path:
                    print(f"Using installed browser: {chrome_path}", file=sys.stderr)
                    launch_options["executable_path"] = chrome_path
                else:
                    print("No installed Chrome/Chromium found; using Playwright's bundled browser.", file=sys.stderr)

                browser = playwright.chromium.launch(**launch_options)
                try:
                    browser_context = browser.new_context(
                        user_agent=(
                            "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
                            "(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
                        ),
                        viewport={"width": 1440, "height": 1000},
                        locale="fa-IR",
                        timezone_id="Asia/Tehran",
                    )
                    page_object = browser_context.new_page()
                    page_object.add_init_script(
                        """
                        Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
                        window.chrome = window.chrome || { runtime: {} };
                        """
                    )

                    response = page_object.goto(
                        search_url,
                        wait_until="domcontentloaded",
                        timeout=45000,
                    )
                    if response is not None and response.status == 429:
                        page_error = "Neshan returned HTTP 429 (Too Many Requests). Please wait a few minutes and try again."
                    elif response is not None and response.status >= 500:
                        page_error = f"Neshan is temporarily unavailable (HTTP {response.status}). Please try again later."
                    else:
                        self._dismiss_cookie_dialog(page_object)
                        try:
                            page_object.wait_for_function(
                                "document.querySelectorAll('a[href*=\"/maps/places/\"]').length > 0",
                                timeout=20000,
                            )
                        except Exception:
                            # A valid zero-result search has no place links. Give
                            # the app a moment to render its empty-state text first.
                            page_object.wait_for_timeout(1200)

                        body_text = ""
                        try:
                            body_text = page_object.locator("body").inner_text(timeout=3000)
                        except Exception:
                            pass
                        normalized_body = normalize_text(body_text)
                        if "too many requests" in normalized_body or "429" in normalized_body:
                            page_error = "Neshan is rate-limiting search requests (HTTP 429). Please wait a few minutes before retrying."
                        elif not self._has_place_links(page_object) and not self._looks_like_empty_state(normalized_body):
                            page_error = "Neshan loaded without any place results. The search may be temporarily blocked; please retry later."
                        elif not self._has_place_links(page_object):
                            # A rendered no-results message is a complete (empty)
                            # search, not a reason to keep scrolling for a minute.
                            scrape_complete = True
                        else:
                            all_records: dict[str, PlaceResult] = {}
                            stable_scrolls = 0
                            raw_ids: set[str] = set()

                            for _ in range(self.max_scroll_steps + 1):
                                records = self._extract_records(page_object)
                                before_raw = len(raw_ids)
                                for record in records:
                                    place_id = _extract_place_id(str(record.get("href") or ""))
                                    if place_id:
                                        raw_ids.add(place_id)
                                    place = parse_place_record(record, category)
                                    if place:
                                        all_records.setdefault(place.place_id, place)

                                if len(all_records) >= target_count:
                                    break

                                scroll_info = self._scroll_result_list(page_object)
                                page_object.wait_for_timeout(1200)
                                if len(raw_ids) == before_raw:
                                    stable_scrolls += 1
                                else:
                                    stable_scrolls = 0

                                if (
                                    stable_scrolls >= self.STABLE_SCROLLS_TO_FINISH
                                    and scroll_info.get("at_bottom")
                                ):
                                    scrape_complete = True
                                    break

                            # Read once more after the final scroll/wait so the
                            # last lazily-loaded batch is not missed.
                            for record in self._extract_records(page_object):
                                place = parse_place_record(record, category)
                                if place:
                                    all_records.setdefault(place.place_id, place)

                            scraped_results = [asdict(place) for place in all_records.values()]
                            if len(all_records) < target_count and stable_scrolls >= self.STABLE_SCROLLS_TO_FINISH:
                                scrape_complete = True
                            elif len(all_records) < target_count and self.max_scroll_steps <= 1:
                                scrape_complete = False
                            # Reaching the requested target is not proof that
                            # Neshan has no more results.
                finally:
                    browser.close()
        except Exception as error:
            message = str(error).strip()
            if "429" in message or "Too Many Requests" in message:
                page_error = "Neshan is rate-limiting search requests (HTTP 429). Please wait a few minutes before retrying."
            else:
                page_error = f"Could not search Neshan: {message or error.__class__.__name__}"

        if page_error:
            # A cached page can still be served during a transient block, but do
            # not pretend that an uncached/partial page is an empty search.
            if cached_complete or len(cached_results) >= page_end:
                return self._page_response(cached_results, page, page_size, cached_complete)
            return {"error": page_error}

        merged_results = self._merge_results(cached_results, scraped_results)
        complete = cached_complete or scrape_complete
        self._write_cache(city, category, merged_results, complete)
        return self._page_response(merged_results, page, page_size, complete)

    @staticmethod
    def _page_response(
        results: list[dict[str, Any]], page: int, page_size: int, complete: bool
    ) -> dict[str, Any]:
        page_results, has_more, total = paginate_results(results, page, page_size, complete)
        page_count = max(1, (total + page_size - 1) // page_size) if total is not None else page + int(has_more)
        return {
            "results": page_results,
            "has_more": has_more,
            "page": page,
            "page_size": page_size,
            "total_results": total,
            "loaded_results": len(results),
            "complete": complete,
            "page_count": page_count,
        }

    @staticmethod
    def _dismiss_cookie_dialog(page_object: Any) -> None:
        selectors = (
            'button:has-text("بستن")',
            'button:has-text("رد کردن")',
            'button:has-text("Reject")',
            'button:has-text("Close")',
            '[aria-label*="بستن"]',
        )
        for selector in selectors:
            try:
                button = page_object.locator(selector).first
                if button.count() and button.is_visible():
                    button.click(timeout=700)
                    page_object.wait_for_timeout(250)
                    return
            except Exception:
                continue

    @staticmethod
    def _has_place_links(page_object: Any) -> bool:
        try:
            return page_object.locator('a[href*="/maps/places/"]').count() > 0
        except Exception:
            return False

    @staticmethod
    def _looks_like_empty_state(normalized_body: str) -> bool:
        return bool(re.search(r"نتیجه.{0,12}(یافت|وجود|پیدا)", normalized_body))

    @staticmethod
    def _extract_records(page_object: Any) -> list[dict[str, Any]]:
        """Extract visible place anchors and the smallest useful card ancestor."""
        try:
            records = page_object.evaluate(
                """() => {
                    const selector = 'a[href*="/maps/places/"]';
                    const isVisible = (element) => {
                        const rect = element.getBoundingClientRect();
                        const style = window.getComputedStyle(element);
                        return rect.width > 0 && rect.height > 0 &&
                            style.visibility !== 'hidden' && style.display !== 'none';
                    };
                    const cleanText = (element) => (element.innerText || element.textContent || '').trim();
                    return Array.from(document.querySelectorAll(selector))
                        .filter(isVisible)
                        .map((link) => {
                            const linkText = cleanText(link);
                            let card = link;
                            let node = link;
                            for (let depth = 0; depth < 9 && node && node !== document.body; depth++) {
                                const text = cleanText(node);
                                const placeLinkCount = node.querySelectorAll(selector).length;
                                if (placeLinkCount > 1) break;
                                if (text && text.length <= 1200 && text.length >= linkText.length) {
                                    card = node;
                                }
                                const hasDetails = /رای|خیابان|میدان|کوچه|بلوار|تماس|وب.?سایت|\+?98|0\d{10}/.test(text);
                                if (hasDetails && text.length <= 800 && placeLinkCount <= 1) {
                                    card = node;
                                    break;
                                }
                                node = node.parentElement;
                            }
                            const externalLinks = Array.from(card.querySelectorAll('a[href]'))
                                .map((anchor) => anchor.href)
                                .filter((href) => /^https?:/i.test(href) && !href.includes('/maps/places/'));
                            return {
                                href: link.href,
                                link_text: linkText,
                                card_text: cleanText(card),
                                external_links: Array.from(new Set(externalLinks))
                            };
                        });
                }"""
            )
            return records if isinstance(records, list) else []
        except Exception as error:
            print(f"Could not read Neshan result cards: {error}", file=sys.stderr)
            return []

    @staticmethod
    def _scroll_result_list(page_object: Any) -> dict[str, Any]:
        """Scroll the nearest result-list container to trigger Neshan's next batch."""
        try:
            info = page_object.evaluate(
                """() => {
                    const selector = 'a[href*="/maps/places/"]';
                    const link = Array.from(document.querySelectorAll(selector)).find((element) => {
                        const rect = element.getBoundingClientRect();
                        return rect.width > 0 && rect.height > 0;
                    });
                    if (!link) {
                        const before = window.scrollY;
                        window.scrollBy(0, Math.max(window.innerHeight * 0.8, 500));
                        return {moved: window.scrollY !== before, at_bottom: false};
                    }
                    let node = link.parentElement;
                    let container = null;
                    while (node && node !== document.body) {
                        const style = window.getComputedStyle(node);
                        const overflowY = style.overflowY;
                        if ((overflowY === 'auto' || overflowY === 'scroll') &&
                            node.scrollHeight > node.clientHeight + 8 && node.clientHeight > 160) {
                            container = node;
                            break;
                        }
                        node = node.parentElement;
                    }
                    if (container) {
                        const before = container.scrollTop;
                        const amount = Math.max(container.clientHeight * 0.85, 420);
                        container.scrollTop = Math.min(before + amount, container.scrollHeight);
                        const atBottom = container.scrollTop + container.clientHeight >= container.scrollHeight - 4;
                        return {moved: container.scrollTop !== before, at_bottom: atBottom};
                    }
                    const before = window.scrollY;
                    link.scrollIntoView({block: 'end', behavior: 'instant'});
                    window.scrollBy(0, Math.max(window.innerHeight * 0.8, 500));
                    return {
                        moved: window.scrollY !== before,
                        at_bottom: window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 4
                    };
                }"""
            )
            return info if isinstance(info, dict) else {"moved": False, "at_bottom": True}
        except Exception:
            return {"moved": False, "at_bottom": True}


def _emit_error(message: str, exit_code: int = 2) -> None:
    print(json.dumps({"error": message}, ensure_ascii=False, separators=(",", ":")))
    raise SystemExit(exit_code)


def main() -> None:
    parser = argparse.ArgumentParser(description="Search Neshan for relevant places")
    parser.add_argument("--city", default="تهران", help="City/province name in Persian")
    parser.add_argument("--category", help="Category value from config/categories.php")
    parser.add_argument("--max-results", type=int, default=20, help="Results returned per page")
    parser.add_argument("--page", type=int, default=1, help="Result page number")
    parser.add_argument("--output", help="Output JSON file path (use - for stdout)")
    parser.add_argument("--headless", action="store_true", help="Run in headless mode (default)")
    parser.add_argument("--no-headless", action="store_false", dest="headless", help="Run a visible browser")
    parser.add_argument("--slow-mo", type=int, default=0, help="Optional Playwright delay in milliseconds")
    parser.add_argument("--cache-ttl", type=int, default=600, help="Result cache lifetime in seconds")
    parser.add_argument("--max-scroll-steps", type=int, default=60, help="Maximum infinite-list scrolls")
    parser.set_defaults(headless=True)
    args = parser.parse_args()

    page = max(1, args.page)
    page_size = max(1, min(args.max_results, 100))
    categories = NeshanCategories.load_categories()
    if args.category:
        categories = [item for item in categories if item.value == args.category]
        if not categories:
            _emit_error(f"Category '{args.category}' was not found in config/categories.php")

    searcher = NeshanSearcher(
        headless=args.headless,
        slow_mo=args.slow_mo,
        cache_ttl=args.cache_ttl,
        max_scroll_steps=args.max_scroll_steps,
    )
    output_data: dict[str, Any] = {}
    pagination_info: dict[str, Any] = {}

    for category in categories:
        print(
            f"Searching Neshan: {category.label} in {args.city}, page {page}",
            file=sys.stderr,
        )
        result = searcher.search_category(category, args.city, page_size, page)
        if result.get("error"):
            _emit_error(str(result["error"]))
        output_data[category.value] = result["results"]
        pagination_info[category.value] = {
            "page": result["page"],
            "page_size": result["page_size"],
            "has_more": result["has_more"],
            "total_results": result["total_results"],
            "loaded_results": result["loaded_results"],
            "complete": result["complete"],
            "page_count": result["page_count"],
        }

    output_data["_pagination"] = pagination_info
    json_output = json.dumps(output_data, ensure_ascii=False, separators=(",", ":"))
    if args.output and args.output != "-":
        Path(args.output).write_text(json_output, encoding="utf-8")
    else:
        print(json_output)


if __name__ == "__main__":
    main()
