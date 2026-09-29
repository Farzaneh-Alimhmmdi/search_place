"""
Neshan Search App - Searches categories defined in config/categories.php
Uses Playwright with system Chromium to simulate a real user and extract results from DOM.
Outputs clean JSON to stdout for PHP integration.

Fixed: Default to headless mode for server execution
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


class NeshanSearcher:
    """
    Searches Neshan using Playwright with system Chromium.
    Extracts results from the DOM after searching.
    """
    
    BASE_URL = "https://neshan.org"
    SEARCH_URL = "https://neshan.org/maps/search"
    
    def __init__(self, headless: bool = True, slow_mo: int = 100):
        self.headless = headless
        self.slow_mo = slow_mo
        self.chrome_path = r'C:\Program Files\Google\Chrome\Application\chrome.exe'
    
    def search_category(self, category: Category, city: str = "تهران", max_results: int = 20, page: int = 1) -> dict:
        """Search for a specific category in a city on Neshan
        Returns dict with 'results' (List[PlaceResult]), 'has_more' (bool), 'page' (int)"""
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
                page_obj = browser.new_page(
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
                page_obj.add_init_script("""
                    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
                    window.chrome = { runtime: {} };
                """)
                
                # Go to search page
                page_obj.goto(self.SEARCH_URL, wait_until='networkidle', timeout=60000)
                # Wait longer in headless mode for page to fully render
                wait_time = 8000 if self.headless else 3000
                page_obj.wait_for_timeout(wait_time)
                
                # Try to close cookie consent popup if present
                try:
                    cookie_selectors = [
                        'button:has-text("بستن")',
                        'button:has-text("خیر")',
                        'button:has-text("Reject")',
                        'button:has-text("Close")',
                        '[class*="cookie"] button',
                        '[class*="popup"] button',
                        '.close-button',
                        '[aria-label*="بستن"]',
                    ]
                    for selector in cookie_selectors:
                        cookie_btn = page_obj.query_selector(selector)
                        if cookie_btn:
                            cookie_btn.click()
                            page_obj.wait_for_timeout(1000)
                            break
                except:
                    pass
                
                # Navigate directly to search URL
                search_query = f"{category.label} {city}"
                print(f"Searching: {search_query}", file=sys.stderr)
                
                # URL encode the search query
                encoded_query = quote_plus(search_query)
                search_url = f"{self.SEARCH_URL}/{encoded_query}"
                
                # Navigate directly to search URL
                page_obj.goto(search_url, wait_until='networkidle', timeout=60000)
                
                # Wait for results to load
                page_obj.wait_for_timeout(15000)
                
                # Scroll to load more pages if needed
                if page > 1:
                    print(f"Scrolling to load page {page}...", file=sys.stderr)
                    for p_num in range(page - 1):
                        # Scroll down to trigger loading more results
                        page_obj.evaluate("window.scrollTo(0, document.body.scrollHeight)")
                        page_obj.wait_for_timeout(3000)
                        # Also try scrolling the map container
                        page_obj.evaluate("""
                            const mapContainer = document.querySelector('.mapboxgl-map') || 
                                               document.querySelector('[class*="map"]') || 
                                               document.body;
                            mapContainer.scrollTop = mapContainer.scrollHeight;
                        """)
                        page_obj.wait_for_timeout(2000)
                
                # Extract results from DOM
                results = self._extract_results_from_dom(page_obj, category, max_results)
                
            except Exception as e:
                print(f"Error during search: {e}", file=sys.stderr)
            finally:
                browser.close()
        
        return {
            'results': results,
            'has_more': len(results) >= max_results,
            'page': page,
        }

    def _extract_results_from_dom(self, page, category: Category, max_results: int) -> List[PlaceResult]:
        """Extract place results from the rendered DOM"""
        results = []
        
        try:
            # Strategy: Find individual result cards by looking for elements that:
            # 1. Have a rating pattern (X,Y رای)
            # 2. Have address keywords
            # 3. Are not huge containers with all results
            
            seen_names = set()
            noise_words = {'حذف', 'باز باشد', 'بازباشد', 'مسیرها', 'وب‌سایت', 'تماس', 'ارسال', 'پشتیبانی', 'نظر', 'اشتراک', 'گزارش', 'مشترک', 'نقشه', 'مسیریاب', 'دانلود', 'برنامه', 'نسخه', 'وب', 'جستجوی', 'جستجو', 'امتیاز', 'رای', 'بازدید', 'محبوب', 'جدید', 'پیشنهاد', 'آگهی'}
            
            # First try: look for elements with rating pattern
            # These are likely individual result cards
            rating_elements = page.query_selector_all('*:has-text("رای")')
            print(f"Found {len(rating_elements)} elements containing 'رای'", file=sys.stderr)
            
            # Also get elements with category label as fallback
            category_elements = page.query_selector_all(f'*:has-text("{category.label}")')
            print(f"Found {len(category_elements)} elements containing '{category.label}'", file=sys.stderr)
            
            # Combine and deduplicate elements
            all_elements = []
            seen_element_ids = set()
            for el_list in [rating_elements, category_elements]:
                for el in el_list:
                    try:
                        # Use element handle as identifier
                        el_id = id(el)
                        if el_id not in seen_element_ids:
                            seen_element_ids.add(el_id)
                            all_elements.append(el)
                    except:
                        pass
            
            print(f"Total unique elements to process: {len(all_elements)}", file=sys.stderr)
            
            for el in all_elements:
                if len(results) >= max_results:
                    break
                    
                try:
                    text = el.inner_text().strip()
                    if not text or len(text) < 15:
                        continue
                    
                    # Skip huge container elements (likely the full results list)
                    if len(text) > 2000:
                        if len(results) < 3:
                            print(f"DEBUG Skipping huge element (len={len(text)})", file=sys.stderr)
                        continue
                    
                    # Check if this looks like a result card
                    lines = [line.strip() for line in text.split('\n') if line.strip()]
                    
                    # Accept elements with at least 1 line (the name)
                    
                    # print(f"DEBUG Processing element (len={len(text)}, lines={len(lines)}): first line='{lines[0]}'", file=sys.stderr)
                    
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
                    
                    # Skip if name contains noise words (use word boundary-aware check)
                    name_words = name.split()
                    skip = False
                    for nw in noise_words:
                        if nw in name_words:  # Exact word match
                            skip = True
                            break
                        # Also check if noise word is a standalone word in the name
                        # (surrounded by spaces or at start/end)
                        if f' {nw} ' in f' {name} ' or name.startswith(nw + ' ') or name.endswith(' ' + nw):
                            skip = True
                            break
                    if skip:
                        continue
                    
                    # Accept any valid name (simplified for headless mode)
                    # No minimum line requirement - just need a valid name
                    
                    # Try to extract rating
                    rating = None
                    for line in lines:
                        # Match both "33 رای" and "33.5 رای" patterns
                        rating_match = re.search(r'(\d+[.,]?\d*)\s*رای', line)
                        if rating_match:
                            try:
                                rating = float(rating_match.group(1).replace(',', '.'))
                            except:
                                rating = None
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
                    instagram_id = None
                    links = el.query_selector_all('a')
                    for link in links:
                        href = link.get_attribute('href')
                        if href and ('http' in href or 'www.' in href) and 'neshan.org' not in href and 'mapbox.com' not in href and 'neshan.blog' not in href:
                            # Check if it's an Instagram link
                            if 'instagram.com' in href:
                                # Extract Instagram username from URL
                                ig_match = re.search(r'instagram\.com/([^/?#]+)', href)
                                if ig_match:
                                    instagram_id = ig_match.group(1)
                            else:
                                website = href
                    
                    # Also check for Instagram in text content
                    if not instagram_id:
                        for line in lines:
                            ig_match = re.search(r'(?:instagram|اینستاگرام|اینستا)[\s:@]*([a-zA-Z0-9_.]{1,30})', line, re.IGNORECASE)
                            if ig_match:
                                instagram_id = ig_match.group(1)
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
                        instagram_id=instagram_id,
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
    parser.add_argument('--page', type=int, default=1, help='Page number to fetch (each page loads more results by scrolling)')
    parser.add_argument('--output', help='Output JSON file path (use - for stdout)')
    parser.add_argument('--headless', action='store_true', help='Run headless (default: True for server)')
    parser.add_argument('--no-headless', action='store_false', dest='headless', help='Run with visible browser (default)')
    parser.set_defaults(headless=True)
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
    pagination_info = {}
    for category in categories:
        print(f"\nSearching category: {category.label} ({category.value})", file=sys.stderr)
        result_data = searcher.search_category(category, args.city, args.max_results, args.page)
        results = result_data['results']
        pagination_info[category.value] = {
            'has_more': result_data['has_more'],
            'page': result_data['page'],
        }
        all_results[category.value] = results
        print(f"Found {len(results)} results", file=sys.stderr)
        
        # Small delay between categories
        if len(categories) > 1:
            time.sleep(3)
    
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


if __name__ == "__main__":
    main()