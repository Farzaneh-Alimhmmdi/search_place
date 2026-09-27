import asyncio
import json
from urllib.parse import urljoin

from playwright.async_api import (
    async_playwright,
    TimeoutError as PlaywrightTimeoutError,
)


# ============================================================
# CONFIGURATION
# ============================================================

CHROME_PATH = r"C:\Program Files\Google\Chrome\Application\chrome.exe"

NESHAN_URL = "https://neshan.org/maps"

MAX_RESULTS = 20


# ============================================================
# HELPER: WAIT FOR ANY SEARCH INPUT
# ============================================================

async def find_search_input(page):
    """
    Find Neshan's search input.

    Neshan can initially show:
        <input readonly ...>

    After clicking it, it can change to:
        <input ...>

    We handle both states.
    """

    # --------------------------------------------------------
    # First: look for an already editable search input
    # --------------------------------------------------------

    editable = page.locator(
        'input[placeholder="جستجو در نشان"]:not([readonly])'
    ).first

    try:
        if await editable.count() > 0:
            if await editable.is_visible():
                print("Editable search input already exists.")
                return editable
    except Exception:
        pass

    # --------------------------------------------------------
    # Second: look for readonly search input
    # --------------------------------------------------------

    readonly = page.locator(
        'input[placeholder="جستجو در نشان"][readonly]'
    ).first

    try:
        await readonly.wait_for(
            state="visible",
            timeout=10000,
        )

        print("Readonly search box found.")

        await readonly.click()

        print("Clicked readonly search box.")

        await page.wait_for_timeout(1000)

    except PlaywrightTimeoutError:
        print(
            "Readonly search box was not found."
        )

    # --------------------------------------------------------
    # Third: try editable input again
    # --------------------------------------------------------

    editable = page.locator(
        'input[placeholder="جستجو در نشان"]:not([readonly])'
    ).first

    try:
        await editable.wait_for(
            state="visible",
            timeout=10000,
        )

        print("Editable search input found.")

        return editable

    except PlaywrightTimeoutError:
        pass

    # --------------------------------------------------------
    # Fourth: search by placeholder without readonly state
    # --------------------------------------------------------

    generic = page.locator(
        'input[placeholder="جستجو در نشان"]'
    ).first

    try:
        await generic.wait_for(
            state="visible",
            timeout=5000,
        )

        print("Generic Neshan search input found.")

        # If readonly, click it
        is_readonly = await generic.get_attribute("readonly")

        if is_readonly is not None:

            print("Input is readonly. Clicking it...")

            await generic.click()

            await page.wait_for_timeout(1000)

        # Re-query after click
        generic = page.locator(
            'input[placeholder="جستجو در نشان"]'
        ).first

        await generic.wait_for(
            state="visible",
            timeout=5000,
        )

        return generic

    except Exception:
        pass

    return None


# ============================================================
# SEARCH NESHAN
# ============================================================

async def search_neshan(
    query: str,
    max_results: int = MAX_RESULTS,
):

    async with async_playwright() as p:

        print("=" * 60)
        print("Starting Google Chrome...")
        print("=" * 60)

        browser = await p.chromium.launch(
            headless=False,
            executable_path=CHROME_PATH,
        )

        page = await browser.new_page(
            viewport={
                "width": 1440,
                "height": 900,
            },
            locale="fa-IR",
        )

        # ----------------------------------------------------
        # OPEN NESHAN
        # ----------------------------------------------------

        print("Opening Neshan...")

        await page.goto(
            NESHAN_URL,
            wait_until="domcontentloaded",
            timeout=60000,
        )

        print("Neshan opened.")

        # Wait for React/Vue/etc.
        await page.wait_for_timeout(4000)

        # ----------------------------------------------------
        # FIND SEARCH INPUT
        # ----------------------------------------------------

        print("Opening Neshan search...")

        search_input = await find_search_input(page)

        if search_input is None:

            print()
            print("=" * 60)
            print("ERROR")
            print("=" * 60)
            print(
                "Could not find Neshan search input."
            )

            await page.screenshot(
                path="neshan_search_error.png",
                full_page=True,
            )

            print(
                "Screenshot saved as:"
            )
            print(
                "neshan_search_error.png"
            )

            input(
                "\nPress ENTER to close Chrome..."
            )

            await browser.close()

            return []

        # ----------------------------------------------------
        # SEARCH QUERY
        # ----------------------------------------------------

        print()
        print("=" * 60)
        print("SEARCH")
        print("=" * 60)

        print("Query:")
        print(query)

        print("Unicode:")
        print(
            " ".join(
                f"U+{ord(c):04X}"
                for c in query
            )
        )

        # ----------------------------------------------------
        # CLEAR INPUT
        # ----------------------------------------------------

        try:
            await search_input.click()

            await search_input.fill("")

        except Exception:
            pass

        # ----------------------------------------------------
        # TYPE QUERY
        # ----------------------------------------------------

        print("Typing query...")

        await search_input.fill(query)

        await page.wait_for_timeout(500)

        # Verify what Playwright typed
        actual_value = await search_input.input_value()

        print(
            "Input value:"
        )
        print(
            actual_value
        )

        # ----------------------------------------------------
        # SUBMIT
        # ----------------------------------------------------

        print("Submitting search...")

        await search_input.press("Enter")

        print("Search submitted.")

        # ----------------------------------------------------
        # WAIT FOR RESULTS
        # ----------------------------------------------------

        print(
            "Waiting for results..."
        )

        await page.wait_for_timeout(5000)

        # ----------------------------------------------------
        # SCREENSHOT
        # ----------------------------------------------------

        await page.screenshot(
            path="neshan_after_search.png",
            full_page=True,
        )

        # ----------------------------------------------------
        # SCROLL
        # ----------------------------------------------------

        print(
            "Loading more results..."
        )

        for i in range(5):

            print(
                f"Scroll {i + 1}/5"
            )

            await page.mouse.wheel(
                0,
                1000,
            )

            await page.wait_for_timeout(1000)

        # ----------------------------------------------------
        # FIND PLACE LINKS
        # ----------------------------------------------------

        print()
        print(
            "Looking for place results..."
        )

        links = page.locator(
            'a[href*="/maps/places/"]'
        )

        count = await links.count()

        print(
            f"Found {count} possible place links."
        )

        # ----------------------------------------------------
        # EXTRACT RESULTS
        # ----------------------------------------------------

        results = []

        for i in range(count):

            if len(results) >= max_results:
                break

            try:

                link = links.nth(i)

                href = await link.get_attribute(
                    "href"
                )

                if not href:
                    continue

                # Ignore undefined URLs
                if "undefined" in href.lower():
                    continue

                url = urljoin(
                    "https://neshan.org",
                    href,
                )

                text_content = await link.inner_text()

                text_content = text_content.strip()

                if not text_content:
                    continue

                # ------------------------------------------------
                # REMOVE DUPLICATES
                # ------------------------------------------------

                if any(
                    result["url"] == url
                    for result in results
                ):
                    continue

                # ------------------------------------------------
                # NAME
                # ------------------------------------------------

                lines = [
                    line.strip()
                    for line in text_content.splitlines()
                    if line.strip()
                ]

                name = (
                    lines[0]
                    if lines
                    else text_content
                )

                result = {
                    "name": name,
                    "url": url,
                    "text": text_content,
                }

                results.append(result)

                print(
                    f"{len(results)}. {name}"
                )

            except Exception as e:

                print(
                    f"Error reading result {i}: {e}"
                )

        # ----------------------------------------------------
        # SAVE JSON
        # ----------------------------------------------------

        output_file = (
            "neshan_results.json"
        )

        with open(
            output_file,
            "w",
            encoding="utf-8",
        ) as file:

            json.dump(
                results,
                file,
                ensure_ascii=False,
                indent=2,
            )

        # ----------------------------------------------------
        # RESULTS
        # ----------------------------------------------------

        print()
        print("=" * 60)
        print("RESULTS")
        print("=" * 60)

        if not results:

            print(
                "No place results found."
            )

        else:

            for index, result in enumerate(
                results,
                start=1,
            ):

                print()
                print(
                    f"Result {index}"
                )

                print("-" * 40)

                print(
                    f"Name: {result['name']}"
                )

                print(
                    f"URL:  {result['url']}"
                )

                print(
                    f"Text: {result['text']}"
                )

        print()
        print("=" * 60)

        print(
            f"Saved {len(results)} results "
            f"to {output_file}"
        )

        print("=" * 60)

        # ----------------------------------------------------
        # KEEP BROWSER OPEN
        # ----------------------------------------------------

        input(
            "\nPress ENTER to close Chrome..."
        )

        await browser.close()

        return results


# ============================================================
# MAIN
# ============================================================

async def main():

    print()
    print("=" * 60)
    print("          NESHAN PLACE SEARCH")
    print("=" * 60)
    print()

    query = input(
        "Enter Neshan search: "
    ).strip()

    if not query:

        print(
            "Search query cannot be empty."
        )

        return

    await search_neshan(
        query=query,
        max_results=MAX_RESULTS,
    )


# ============================================================
# START
# ============================================================

if __name__ == "__main__":

    asyncio.run(main())