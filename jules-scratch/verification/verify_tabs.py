from playwright.sync_api import sync_playwright

def run(playwright):
    browser = playwright.chromium.launch()
    page = browser.new_page()
    page.goto("http://localhost/makerspace-dashboard")

    # Click the "Governance" tab
    page.click('a[href="/makerspace-dashboard/governance"]')

    # Wait for the content to load
    page.wait_for_selector('.makerspace-dashboard-section')

    page.screenshot(path="jules-scratch/verification/verification.png")
    browser.close()

with sync_playwright() as playwright:
    run(playwright)
