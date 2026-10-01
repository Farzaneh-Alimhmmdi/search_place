"""
Neshan Search App - Searches categories defined in config/categories.php
Uses Neshan's own web API (pwa-api/neshan-search) directly - no browser needed.

The phone number is inside the search response itself:
  items[].actions[type=CALL].metaData.phone
plus website in actions[type=BROWSER].metaData.uri.
No extra click or detail request is required.

No Api-Key needed (unlike api.neshan.org). Only a random uuid header.
Outputs clean JSON to stdout for PHP integration (same format as before).
"""

import argparse
import base64
import json
import re
import sys
import time
import uuid
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional
from urllib.parse import quote

# Fix encoding for Windows
if sys.platform == 'win32':
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')

try:
    import requests
except ImportError:
    print(json.dumps({"error": "requests not installed. Run: pip install requests"}))
    sys.exit(1)


@dataclass
class Category:
    """Represents a category from categories.php"""
    value: str
    label: str


@dataclass
class PlaceResult:
    """Represents a search result from Neshan"""
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
    """Loads and manages categories from the PHP config file"""

    # The categories.php is at the project root config/ folder (3 levels up from this file)
    CATEGORIES_PHP_PATH = Path(__file__).parent.parent.parent / "config" / "categories.php"

    DEFAULT_CATEGORIES = [
        Category('hotel', 'هتل'),
        Category('motel', 'متل'),
        Category('guest-house', 'مهمانسرا'),
        Category('hostel', 'هاستل'),
        Category('vernacular-accommodation', 'بوم‌گردی'),
        Category('apartment', 'آپارتمان'),
        Category('villa', 'ویلا'),
    ]

    @classmethod
    def load_categories(cls) -> List[Category]:
        """Load categories from the PHP config file"""
        try:
            content = cls.CATEGORIES_PHP_PATH.read_text(encoding='utf-8')
            pattern = r"'value'\s*=>\s*'([^']+)',\s*'label'\s*=>\s*'([^']+)'"
            matches = re.findall(pattern, content)
            if matches:
                return [Category(value, label) for value, label in matches]
        except Exception as e:
            print(f"Warning: Could not parse categories.php: {e}", file=sys.stderr)

        return cls.DEFAULT_CATEGORIES


# Approximate map centers per city (x=lng, y=lat). The search term already
# contains the city name, so the center only biases ranking, not filtering.
CITY_CENTERS: Dict[str, Dict[str, float]] = {
    'تهران': {'x': 51.3890, 'y': 35.6892},
    'کرج': {'x': 50.9916, 'y': 35.8401},
    'اصفهان': {'x': 51.6776, 'y': 32.6546},
    'مشهد': {'x': 59.6067, 'y': 36.2974},
    'تبریز': {'x': 46.2919, 'y': 38.0808},
    'شیراز': {'x': 52.5311, 'y': 29.5918},
    'اهواز': {'x': 48.6693, 'y': 31.3183},
    'قم': {'x': 50.8764, 'y': 34.6406},
    'کرمانشاه': {'x': 47.0650, 'y': 34.3142},
    'ارومیه': {'x': 45.0769, 'y': 37.5527},
    'رشت': {'x': 49.5832, 'y': 37.2808},
    'زاهدان': {'x': 60.8629, 'y': 29.4956},
    'همدان': {'x': 48.5167, 'y': 34.7992},
    'کرمان': {'x': 57.0679, 'y': 30.2904},
    'اردبیل': {'x': 48.2933, 'y': 38.2537},
    'یزد': {'x': 54.3569, 'y': 31.8974},
    'بندر عباس': {'x': 56.2666, 'y': 27.1832},
    'بندرعباس': {'x': 56.2666, 'y': 27.1832},
    'خرم آباد': {'x': 48.3538, 'y': 33.4878},
    'سنندج': {'x': 47.0030, 'y': 35.3219},
    'گرگان': {'x': 54.4342, 'y': 36.8417},
    'ساری': {'x': 53.0601, 'y': 36.5659},
    'بابل': {'x': 52.6779, 'y': 36.5513},
    'آمل': {'x': 52.3507, 'y': 36.4696},
    'چالوس': {'x': 51.2358, 'y': 36.6559},
    'نوشهر': {'x': 51.5000, 'y': 36.6500},
    'تنکابن': {'x': 50.8750, 'y': 36.8167},
}
DEFAULT_CENTER = {'x': 51.3890, 'y': 35.6892}


def pwa_encode(payload: Dict[str, Any]) -> str:
    """Mirror of web `r.o.encode(encodeURIComponent(JSON.stringify(payload)))`."""
    raw = json.dumps(payload, ensure_ascii=False, separators=(',', ':'))
    quoted = quote(raw, safe="~()*!.'")
    return base64.b64encode(quoted.encode('ascii')).decode('ascii')


def pwa_decode(response_text: str, parse_json: bool = True) -> Any:
    """Mirror of web module 32165 `A(e, t)`: strip 28/28, split at last '@'."""
    if not response_text:
        raise ValueError('empty pwa-api response')
    s = response_text.strip()
    if len(s) < 60 or '@' not in s:
        # Not obfuscated (e.g. plain JSON error) - try direct parse
        return json.loads(s) if parse_json else s
    inner = s[28:]
    inner = inner[:-28]
    cut = inner.rfind('@')
    if cut < 0:
        raise ValueError('invalid pwa-api envelope (no @)')
    offset_raw = inner[cut + 1:]
    inner = inner[:cut]
    try:
        offset = int(offset_raw)
    except ValueError:
        raise ValueError('invalid pwa-api envelope (bad offset)')
    head = inner[:offset]
    tail = inner[offset:]
    b64 = (tail + head).strip()
    # standard base64 -> utf-8 (mirror of module 44640 decode)
    b64 = re.sub(r'[^A-Za-z0-9+/=]', '', b64)
    b64 += '=' * (-len(b64) % 4)
    text = base64.b64decode(b64).decode('utf-8')
    return json.loads(text) if parse_json else text


class NeshanSearcher:
    """
    Searches Neshan via its own pwa-api (same endpoint the website uses).
    No browser, no Api-Key, phone numbers included in the search response.
    """

    BASE_URL = "https://neshan.org"
    SEARCH_API = "https://neshan.org/maps/pwa-api/neshan-search"
    TMP_LOGIN_API = "https://neshan.org/maps/pwa-api/login/tmp/"
    CLIENT_VERSION = "2035"

    def __init__(self, headless: bool = True, slow_mo: int = 100,
                 timeout: int = 30, client_version: str = CLIENT_VERSION):
        # headless/slow_mo kept for CLI compatibility (no longer used)
        self.headless = headless
        self.slow_mo = slow_mo
        self.timeout = timeout
        self.client_version = client_version
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': ('Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                           'AppleWebKit/537.36 (KHTML, like Gecko) '
                           'Chrome/120.0.0.0 Safari/537.36'),
            'Accept': '*/*',
            'Accept-Language': 'fa-IR,fa;q=0.9,en;q=0.8',
            'Referer': 'https://neshan.org/maps/',
            'Origin': 'https://neshan.org',
        })
        self._cookies_ready = False

    def _ensure_session(self) -> None:
        if self._cookies_ready:
            return
        try:
            self.session.get('https://neshan.org/maps/', timeout=self.timeout)
        except Exception:
            pass
        self._cookies_ready = True

    def _tmp_login(self, uid: str) -> None:
        try:
            self.session.get(self.TMP_LOGIN_API + '?uuid=' + uid,
                             timeout=self.timeout)
        except Exception:
            pass

    def _api_search(self, term: str, center: Dict[str, float], limit: int) -> Dict[str, Any]:
        uid = 'web_' + str(uuid.uuid4())
        self._ensure_session()
        self._tmp_login(uid)
        payload = {
            'uuid': uid,
            'term': term,
            'zoom': 11,
            'limit': max(1, min(int(limit), 50)),
            'filters': {},
            'night': False,
            'location': None,
            'center': {'x': center['x'], 'y': center['y']},
            'boundary': None,
        }
        body = pwa_encode(payload)
        url = self.SEARCH_API + '?body=' + quote(body, safe='') + '&search-in-bound=false'
        headers = {
            'X-Client-Version': self.client_version,
            'Content-Type': 'application/json',
            'uuid': uid,
            'Referer': 'https://neshan.org/maps/search/' + quote(term),
        }
        resp = self.session.get(url, headers=headers, timeout=self.timeout)
        if resp.status_code != 200:
            raise RuntimeError(f'neshan-search HTTP {resp.status_code}: {resp.text[:200]}')
        text = resp.text
        if 'در حال بررسی مرورگر' in text or len(text) < 100:
            raise RuntimeError('neshan bot check blocked the request (try again later)')
        return pwa_decode(text, parse_json=True)

    @staticmethod
    def _phone_of(item: Dict[str, Any]) -> Optional[str]:
        for action in item.get('actions') or []:
            if not isinstance(action, dict) or action.get('type') != 'CALL':
                continue
            meta = action.get('metaData')
            if isinstance(meta, dict):
                phone = meta.get('phone')
            else:
                try:
                    phone = json.loads(meta or '{}').get('phone') if meta else None
                except Exception:
                    phone = None
            if phone:
                return str(phone).strip()
        return None

    @staticmethod
    def _website_of(item: Dict[str, Any]) -> Optional[str]:
        for action in item.get('actions') or []:
            if not isinstance(action, dict) or action.get('type') != 'BROWSER':
                continue
            meta = action.get('metaData')
            if isinstance(meta, dict):
                uri = meta.get('uri')
            else:
                try:
                    uri = json.loads(meta or '{}').get('uri') if meta else None
                except Exception:
                    uri = None
            if uri and isinstance(uri, str) and uri.startswith('http'):
                return uri.strip()
        return None

    def _to_place(self, item: Dict[str, Any], category: Category) -> Optional[PlaceResult]:
        title = (item.get('title') or '').strip()
        if not title:
            return None
        loc = item.get('location') or {}
        try:
            lat = float(loc.get('y')) if loc.get('y') is not None else None
            lng = float(loc.get('x')) if loc.get('x') is not None else None
        except (TypeError, ValueError):
            lat, lng = None, None
        rate = None
        stars = item.get('rateStars') or {}
        try:
            rate = float(stars.get('rate')) if stars.get('rate') is not None else None
        except (TypeError, ValueError):
            rate = None
        crowd_id = item.get('crowdId')
        return PlaceResult(
            name=title,
            address=(item.get('subtitle') or None),
            phone=self._phone_of(item),
            website=self._website_of(item),
            category=category.label,
            latitude=lat,
            longitude=lng,
            rating=rate,
            instagram_id=None,
            neshan_url=f'https://neshan.org/maps/places/{crowd_id}' if crowd_id else None,
        )

    def search_category(self, category: Category, city: str = "تهران",
                        max_results: int = 20, page: int = 1) -> dict:
        """Search for a specific category in a city on Neshan.

        Returns dict with 'results' (List[PlaceResult]), 'has_more' (bool), 'page' (int).
        """
        max_results = max(1, int(max_results or 20))
        page = max(1, int(page or 1))
        city = (city or '').strip().strip('"').strip("'").strip('\\').strip()
        center = CITY_CENTERS.get(city, DEFAULT_CENTER)
        term = f"{category.label} {city}".strip()
        limit = min(max_results * page, 50)

        data = self._api_search(term, center, limit)
        items = data.get('items') if isinstance(data, dict) else None
        if not isinstance(items, list):
            return {'results': [], 'has_more': False, 'page': page}

        places: List[PlaceResult] = []
        for item in items:
            if not isinstance(item, dict):
                continue
            place = self._to_place(item, category)
            if place is not None:
                places.append(place)

        start = (page - 1) * max_results
        end = start + max_results
        page_places = places[start:end]
        has_more = end < len(places) or len(places) >= limit
        return {'results': page_places, 'has_more': has_more, 'page': page}


def main():
    parser = argparse.ArgumentParser(description="Search Neshan for places by category")
    parser.add_argument('--city', default='تهران', help='City to search in (Persian name)')
    parser.add_argument('--category', help='Specific category to search (value from categories.php)')
    parser.add_argument('--max-results', type=int, default=20, help='Max results per category')
    parser.add_argument('--page', type=int, default=1, help='Page number to fetch')
    parser.add_argument('--output', help='Output JSON file path (use - for stdout)')
    parser.add_argument('--headless', action='store_true', help='Kept for compatibility (no browser used)')
    parser.add_argument('--no-headless', action='store_false', dest='headless', help='Kept for compatibility')
    parser.set_defaults(headless=True)
    parser.add_argument('--slow-mo', type=int, default=100, help='Kept for compatibility (no browser used)')

    args = parser.parse_args()

    categories = NeshanCategories.load_categories()

    if args.category:
        categories = [c for c in categories if c.value == args.category]
        if not categories:
            print(f"Category '{args.category}' not found!", file=sys.stderr)
            for c in NeshanCategories.load_categories():
                print(f"  - {c.value}: {c.label}", file=sys.stderr)
            sys.exit(1)

    print("=" * 60, file=sys.stderr)
    print("Neshan Search - pwa-api (no browser, no Api-Key)", file=sys.stderr)
    print("=" * 60, file=sys.stderr)

    searcher = NeshanSearcher(headless=args.headless, slow_mo=args.slow_mo)

    all_results = {}
    pagination_info = {}
    failed = False
    for category in categories:
        print(f"\nSearching category: {category.label} ({category.value})", file=sys.stderr)
        try:
            result_data = searcher.search_category(category, args.city, args.max_results, args.page)
        except Exception as e:
            print(f"Error during search: {e}", file=sys.stderr)
            result_data = {'results': [], 'has_more': False, 'page': args.page}
            failed = True
        results = result_data['results']
        pagination_info[category.value] = {
            'has_more': result_data['has_more'],
            'page': result_data['page'],
        }
        all_results[category.value] = results
        phones = sum(1 for p in results if p.phone)
        print(f"Found {len(results)} results ({phones} with phone)", file=sys.stderr)

        # Small delay between categories
        if len(categories) > 1:
            time.sleep(1)

    # Convert to serializable format
    output_data = {}
    for cat_value, places in all_results.items():
        output_data[cat_value] = [asdict(p) for p in places]

    # Include pagination info in output
    output_data['_pagination'] = pagination_info

    # Output JSON (compact, single line for PHP parsing)
    json_output = json.dumps(output_data, ensure_ascii=False, separators=(',', ':'))

    if args.output and args.output != '-':
        Path(args.output).write_text(json_output, encoding='utf-8')
        print(f"\nResults saved to: {args.output}", file=sys.stderr)
    else:
        # Only JSON to stdout
        print(json_output)

    if failed and all(not v for v in all_results.values()):
        sys.exit(2)


if __name__ == "__main__":
    main()
