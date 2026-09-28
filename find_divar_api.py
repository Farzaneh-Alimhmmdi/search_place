from playwright.sync_api import sync_playwright
import json


URL = "https://divar.ir/s/isfahan/rent-temporary?q=ویلا"


with sync_playwright() as p:
    browser = p.chromium.launch(
        channel="chrome",
        headless=False
    )

    page = browser.new_page()

    def request_handler(request):
        resource_type = request.resource_type

        if resource_type in ["xhr", "fetch"]:
            print("\n" + "=" * 100)
            print("REQUEST")
            print("=" * 100)

            print("METHOD :", request.method)
            print("URL    :", request.url)
            print("TYPE   :", resource_type)

            if request.post_data:
                print("\nPOST DATA:")
                print(request.post_data)

            print("\nHEADERS:")
            for key, value in request.headers.items():
                if key.lower() in [
                    "authorization",
                    "content-type",
                    "accept",
                    "origin",
                    "referer",
                    "user-agent"
                ]:
                    print(f"{key}: {value}")

    def response_handler(response):
        request = response.request

        if request.resource_type in ["xhr", "fetch"]:
            print("\n" + "-" * 100)
            print("RESPONSE")
            print("-" * 100)

            print("STATUS :", response.status)
            print("URL    :", response.url)

            try:
                body = response.text()

                # Don't print enormous responses
                print("\nBODY:")
                print(body[:5000])

            except Exception as e:
                print("Could not read response:", e)

    page.on("request", request_handler)
    page.on("response", response_handler)

    print("Opening Divar...")
    
    page.goto(
        URL,
        wait_until="domcontentloaded",
        timeout=60000
    )

    page.wait_for_timeout(5000)

    print("\n\n" + "#" * 100)
    print("INITIAL PAGE LOADED")
    print("#" * 100)

    input("\nPress ENTER to scroll...\n")

    # Scroll several times
    for i in range(10):

        print(f"\n\nSCROLL {i + 1}")

        page.mouse.wheel(0, 5000)

        page.wait_for_timeout(3000)

    input("\nPress ENTER to close browser...\n")

    browser.close()