const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
const path = require('path');
const fs = require('fs');

puppeteer.use(StealthPlugin());

process.stderr.write("automate.cjs started, args: " + JSON.stringify(process.argv.slice(2)) + "\n");

const args = process.argv.slice(2);
if (!args.length) {
    console.log(JSON.stringify({ success: false, error: "No input provided" }));
    process.exit(1);
}

let userData;
try {
    userData = JSON.parse(args[0]);
} catch (_) {
    try {
        userData = JSON.parse(fs.readFileSync(args[0], 'utf8'));
    } catch (e) {
        console.log(JSON.stringify({ success: false, error: "Cannot parse input: " + e.message }));
        process.exit(1);
    }
}

process.stderr.write("userData loaded: " + JSON.stringify({
    email: userData.email,
    firstName: userData.firstName,
    referredBy: userData.referredBy
}) + "\n");

(async () => {
    let browser;
    let page;

    try {
        // Try to find Chromium executable path
        let executablePath = process.env.PUPPETEER_EXECUTABLE_PATH || null;
        if (!executablePath) {
            const possiblePaths = [
                '/usr/bin/chromium',
                '/usr/bin/chromium-browser',
                '/usr/bin/google-chrome',
                '/usr/bin/google-chrome-stable',
                process.execPath.replace('node', 'chromium') // fallback
            ];
            for (const p of possiblePaths) {
                if (fs.existsSync(p)) {
                    executablePath = p;
                    break;
                }
            }
        }

        browser = await puppeteer.launch({
            headless: 'new',
            executablePath: executablePath,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--no-zygote',
                '--single-process',
                '--no-first-run',
                '--no-default-browser-check',
                '--disable-extensions',
                '--disable-breakpad',
                '--disable-crash-reporter',
                '--ignore-certificate-errors',
                '--ignore-ssl-errors',
                '--disable-web-security',
                '--disable-features=IsolateOrigins,site-per-process',
                '--user-data-dir=/tmp/puppeteer_' + process.pid,
            ]
        });

        process.stderr.write("Browser launched successfully\n");

        page = await browser.newPage();
        page.setDefaultTimeout(30000);
        page.setDefaultNavigationTimeout(60000);

        await page.setUserAgent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        );

        process.stderr.write("Navigating to registration page...\n");
        await page.goto('https://app.elevatewellworldwide.com/register', {
            waitUntil: 'networkidle2',
            timeout: 60000
        });
        process.stderr.write("Page loaded\n");

        // Fill form fields
        await page.type('#fname', userData.firstName);
        await page.type('#lname', userData.lastName);
        await page.select('select[name="gender"]', userData.gender);
        await page.type('#mobile', userData.mobile);
        await page.type('#email', userData.email);
        await page.type('#pwd', userData.password);
        await page.type('#pwd2', userData.password);
        process.stderr.write("Form fields filled\n");

        // Sponsor validation
        await page.$eval('#sponsor', el => el.value = '');
        await page.type('#sponsor', userData.referredBy);
        await page.click('#validate_sponsor');
        process.stderr.write("Sponsor validate clicked\n");

        // Wait for sponsor validation result using MutationObserver or polling
        let validated = false;
        let validationResult = null;

        for (let i = 0; i < 30; i++) {
            await page.waitForTimeout(500);
            validationResult = await page.evaluate(() => {
                const ok = document.querySelector('.validate_ok_2');
                const wrong = document.querySelector('.validate_wrong_2');
                if (ok && !ok.classList.contains('hideThis')) return 'success';
                if (wrong && !wrong.classList.contains('hideThis')) return 'failed';
                return false;
            });
            if (validationResult !== false) {
                validated = true;
                break;
            }
        }

        if (!validated || validationResult !== 'success') {
            // Take screenshot for debugging
            const ssPath = '/tmp/sponsor_fail_' + Date.now() + '.png';
            await page.screenshot({ path: ssPath, fullPage: true });
            process.stderr.write("Sponsor fail screenshot: " + ssPath + "\n");

            // Also log the HTML around sponsor field
            const sponsorHtml = await page.evaluate(() => {
                const sponsorInput = document.querySelector('#sponsor');
                const validateBtn = document.querySelector('#validate_sponsor');
                const okDiv = document.querySelector('.validate_ok_2');
                const wrongDiv = document.querySelector('.validate_wrong_2');
                return {
                    sponsorValue: sponsorInput ? sponsorInput.value : null,
                    validateBtnExists: !!validateBtn,
                    okDivVisible: okDiv && !okDiv.classList.contains('hideThis'),
                    wrongDivVisible: wrongDiv && !wrongDiv.classList.contains('hideThis'),
                    okDivHtml: okDiv ? okDiv.outerHTML : null,
                };
            });
            process.stderr.write("Sponsor debug: " + JSON.stringify(sponsorHtml) + "\n");

            throw new Error("Sponsor validation " + (validationResult || 'timeout') + " — referredBy: " + userData.referredBy);
        }

        process.stderr.write("Sponsor validated successfully\n");

        await page.click('#check_agree');

        // Wait for button to be enabled, then force-enable as fallback
        await page.waitForFunction(
            () => {
                const btn = document.querySelector('#btnRegister');
                return btn && !btn.disabled;
            },
            { timeout: 5000 }
        ).catch(async () => {
            process.stderr.write("Button still disabled, force-enabling\n");
            await page.evaluate(() => {
                const btn = document.querySelector('#btnRegister');
                if (btn) btn.disabled = false;
            });
        });

        process.stderr.write("Submitting form...\n");

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 60000 }),
            page.click('#btnRegister'),
        ]);

        const finalUrl = page.url();
        process.stderr.write("Final URL: " + finalUrl + "\n");

        if (finalUrl.includes('/register')) {
            // Extract error from page
            const errorMsg = await page.evaluate(() => {
                for (const sel of ['.alert-danger', '.alert-warning', '.text-danger', '.toast-message', '.error-message']) {
                    const el = document.querySelector(sel);
                    if (el && el.innerText && el.innerText.trim()) return el.innerText.trim();
                }
                // Also check for any visible error-like elements
                const errorElements = Array.from(document.querySelectorAll('[class*="error"], [class*="danger"], [class*="alert"]'));
                for (const el of errorElements) {
                    if (el.innerText && el.innerText.trim() && el.offsetParent !== null) {
                        return el.innerText.trim();
                    }
                }
                return 'Still on register page - no specific error message';
            });
            throw new Error(errorMsg);
        }

        console.log(JSON.stringify({ success: true, finalUrl }));

    } catch (e) {
        process.stderr.write("ERROR: " + e.message + "\n");

        if (page) {
            try {
                const screenshotPath = '/tmp/error_' + Date.now() + '.png';
                await page.screenshot({ path: screenshotPath, fullPage: true });
                process.stderr.write("Screenshot saved: " + screenshotPath + "\n");
            } catch (_) {
                process.stderr.write("Could not take screenshot\n");
            }
        }

        console.log(JSON.stringify({ success: false, error: e.message }));
    } finally {
        if (browser) {
            await browser.close().catch(() => {});
        }

        // Cleanup user data dir
        try {
            fs.rmSync('/tmp/puppeteer_' + process.pid, { recursive: true, force: true });
        } catch (_) {}
    }
})();
