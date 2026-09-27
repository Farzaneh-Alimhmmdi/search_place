"""
Neshan Search App - Searches categories defined in config/categories.php
Uses Playwright with system Chromium to simulate a real user and extract results from DOM.
Outputs clean JSON to stdout for PHP integration.
"""

import json
import re
import sys
import argparse
import time
from pathlib import Path
from typing import List, Dict, Any, Optional
from dataclasses import dataclass, asdict
from urllib.parse import quote_plus

# Fix encoding for Windows
if sys.platform == 'win32':
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')

# Playwright import
try:
    from playwright.sync_api import sync_playwright
except ImportError:
    print(json.dumps({"error": "Playwright not installed. Run: pip install playwright && playwright install chromium"}))
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


class NeshanSearcher:
    """
    Searches Neshan using Playwright with system Chromium.
    Extracts results from the DOM after searching.
    """
    
    BASE_URL = "https://neshan.org"
    SEARCH_URL = "https://neshan.org/maps/search"
    
    def __init__(self, headless: bool = False, slow_mo: int = 100):
        self.headless = headless
        self.slow_mo = slow_mo
        self.chrome_path = r'C:\Program Files\Google\Chrome\Application\chrome.exe'
    
    def search_category(self, category: Category, city: str = "تهران", max_results: int = 20) -> List[PlaceResult]:
        """Search for a specific category in a city on Neshan"""
        results = []
        
        with sync_playwright() as p:
            # Use different args for headless vs headed
            if self.headless:
                browser_args = [
                    '--disable-blink-features=AutomationControlled',
                    '--disable-dev-shm-usage',
                    '--no-sandbox',
                    '--disable-gpu',
                    '--window-size=1920,1080',
                ]
            else:
                browser_args = [
                    '--disable-blink-features=AutomationControlled',
                ]
            
            browser = p.chromium.launch(
                headless=self.headless,
                executable_path=self.chrome_path,
                args=browser_args,
                slow_mo=self.slow_mo
            )
            
            try:
                page = browser.new_page(
                    user_agent=(
                        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                        'AppleWebKit/537.36 (KHTML, like Gecko) '
                        'Chrome/120.0.0.0 Safari/537.36'
                    ),
                    viewport={'width': 1920, 'height': 1080},
                    locale='fa-IR',
                    timezone_id='Asia/Tehran',
                )
                
                # Add stealth scripts
                page.add_init_script("""
                    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
                    window.chrome = { runtime: {} };
                """)
                
                # Go to search page
                page.goto(self.SEARCH_URL, wait_until='networkidle', timeout=60000)
                # Wait longer in headless mode
                wait_time = 5000 if self.headless else 3000
                page.wait_for_timeout(wait_time)
                
                # Find and use search input - wait for it to appear
                search_input = None
                for _ in range(10):  # Wait up to 10 seconds
                    search_input = page.query_selector('input[type="search"]')
                    if search_input:
                        break
                    page.wait_for_timeout(1000)
                
                if not search_input:
                    print("Error: Search input not found after waiting", file=sys.stderr)
                    browser.close()
                    return results
                
                search_query = f"{category.label} {city}"
                print(f"Searching: {search_query}", file=sys.stderr)
                
                search_input.fill(search_query)
                page.wait_for_timeout(2000)
                search_input.press('Enter')
                
                # Wait for results to load (map markers and popups)
                page.wait_for_timeout(8000)
                
                # Extract results from DOM
                results = self._extract_results_from_dom(page, category, max_results)
                
            except Exception as e:
                print(f"Error during search: {e}", file=sys.stderr)
            finally:
                browser.close()
        
        return results
    
    def _extract_results_from_dom(self, page, category: Category, max_results: int) -> List[PlaceResult]:
        """Extract place results from the rendered DOM"""
        results = []
        
        try:
            # The results appear as map markers with class patterns like nrFZBE4, VtOzPyM, QDSxX77
            # They contain hotel name, rating, address, and action buttons
            # Strategy: Find elements that look like result cards (have rating, address, name)
            
            # Look for elements with specific class patterns that contain results
            # These are the map marker popups
            marker_selectors = [
                '[class*="search_result_popup"]',
                '[class*="mapboxgl-popup"]',
                'div[class*="result"]',
            ]
            
            # Also search for elements containing the category label
            elements = page.query_selector_all(f'*:has-text("{category.label}")')
            print(f"Found {len(elements)} elements containing '{category.label}'", file=sys.stderr)
            
            seen_names = set()
            noise_words = {'حذف', 'باز باشد', 'بازباشد', 'مسیرها', 'وب‌سایت', 'تماس', 'ارسال', 'پشتیبانی', 'نظر', 'اشتراک', 'گزارش', 'مشترک', 'نقشه', 'مسیریاب', 'دانلود', 'برنامه', 'نسخه', 'وب', 'جستجوی', 'جستجو', 'امتیاز', 'رای', 'بازدید', 'محبوب', 'جدید', 'پیشنهاد'}
            
            for el in elements:
                if len(results) >= max_results:
                    break
                    
                try:
                    text = el.inner_text().strip()
                    if not text or len(text) < 15:
                        continue
                    
                    # Check if this looks like a result card
                    lines = [line.strip() for line in text.split('\n') if line.strip()]
                    
                    if len(lines) < 3:
                        continue
                    
                    # First non-noise line should be the name
                    name = None
                    for line in lines:
                        if line not in noise_words and len(line) > 2 and not line.startswith('http'):
                            name = line
                            break
                    
                    if not name:
                        continue
                    
                    # Skip if we've seen this name
                    if name in seen_names:
                        continue
                    
                    # Skip if it's just the category label or noise
                    if name == category.label or name in noise_words:
                        continue
                    
                    # Skip very short or very long names
                    if len(name) < 3 or len(name) > 100:
                        continue
                    
                    # Skip if name contains only UI words
                    if any(nw in name for nw in noise_words):
                        continue
                    
                    # Try to extract rating
                    rating = None
                    for line in lines:
                        rating_match = re.search(r'(\d+[.,]\d+)\s*رای', line)
                        if rating_match:
                            rating = float(rating_match.group(1).replace(',', '.'))
                            break
                    
                    # Try to find address (usually contains Persian street/city names)
                    address = None
                    address_keywords = ['خیابان', 'میدان', 'کوچه', 'بلوار', 'شهر', 'محله', 'پلاک', 'بخش', 'منطقه', 'جاده', 'معاون', 'مجتمع', 'ساختمان']
                    for line in lines:
                        if any(keyword in line for keyword in address_keywords):
                            address = line
                            break
                    
                    # Try to find phone
                    phone = None
                    for line in lines:
                        if re.search(r'(\+?98|0)\d{10}', line):
                            phone = line
                            break
                    
                    # Try to find website
                    website = None
                    links = el.query_selector_all('a')
                    for link in links:
                        href = link.get_attribute('href')
                        if href and ('http' in href or 'www.' in href) and 'neshan.org' not in href and 'mapbox.com' not in href and 'neshan.blog' not in href:
                            website = href
                            break
                    
                    seen_names.add(name)
                    
                    results.append(PlaceResult(
                        name=name,
                        address=address,
                        phone=phone,
                        website=website,
                        category=category.label,
                        latitude=None,
                        longitude=None,
                        rating=rating,
                        neshan_url=page.url,
                    ))
                    
                except Exception as e:
                    continue
                    
        except Exception as e:
            print(f"Error extracting results: {e}", file=sys.stderr)
        
        print(f"Extracted {len(results)} unique results", file=sys.stderr)
        return results


def main():
    parser = argparse.ArgumentParser(description="Search Neshan for places by category")
    parser.add_argument('--city', default='تهران', help='City to search in (Persian name)')
    parser.add_argument('--category', help='Specific category to search (value from categories.php)')
    parser.add_argument('--max-results', type=int, default=20, help='Max results per category')
    parser.add_argument('--output', help='Output JSON file path (use - for stdout)')
    parser.add_argument('--headless', action='store_true', help='Run headless (default: False - headed mode works better)')
    parser.add_argument('--no-headless', action='store_false', dest='headless', help='Run with visible browser (default)')
    parser.set_defaults(headless=False)
    parser.add_argument('--slow-mo', type=int, default=100, help='Slow motion delay (ms)')
    
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
    print("Neshan Search - Playwright DOM Extraction", file=sys.stderr)
    print("=" * 60, file=sys.stderr)
    
    searcher = NeshanSearcher(headless=args.headless, slow_mo=args.slow_mo)
    
    all_results = {}
    for category in categories:
        print(f"\nSearching category: {category.label} ({category.value})", file=sys.stderr)
        results = searcher.search_category(category, args.city, args.max_results)
        all_results[category.value] = results
        print(f"Found {len(results)} results", file=sys.stderr)
        
        # Small delay between categories
        if len(categories) > 1:
            time.sleep(3)
    
    # Convert to serializable format
    output_data = {}
    for cat_value, places in all_results.items():
        output_data[cat_value] = [asdict(p) for p in places]
    
    # Output JSON (compact, single line for PHP parsing)
    json_output = json.dumps(output_data, ensure_ascii=False, separators=(',', ':'))
    
    if args.output and args.output != '-':
        Path(args.output).write_text(json_output, encoding='utf-8')
        print(f"\nResults saved to: {args.output}", file=sys.stderr)
    else:
        # Only JSON to stdout
        print(json_output)


if __name__ == "__main__":
    main()