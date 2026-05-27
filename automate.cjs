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
    // Try as raw JSON first
    userData = JSON.parse(args[0]);
} catch (_) {
    // Try as file path
    try {
        userData = JSON.parse(fs.readFileSync(args[0], 'utf8'));
    } catch (e) {
        console.log(JSON.stringify({ success: false, error: "Cannot parse input: " + e.message }));
        process.exit(1);
    }
}

process.stderr.write("userData loaded: " + userData.email + "\n");

(async () => {
    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    const page = await browser.newPage();

    try {
        page.setDefaultTimeout(30000);
        page.setDefaultNavigationTimeout(60000);

        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        await page.goto('https://app.elevatewellworldwide.com/register', { waitUntil: 'networkidle2' });
        process.stderr.write("Page loaded\n");

        await page.type('#fname', userData.firstName);
        await page.type('#lname', userData.lastName);
        await page.select('select[name="gender"]', userData.gender);
        await page.type('#mobile', userData.mobile);
        await page.type('#email', userData.email);
        await page.type('#pwd', userData.password);
        await page.type('#pwd2', userData.password);

        await page.$eval('#sponsor', el => el.value = '');
        await page.type('#sponsor', userData.referredBy);
        await page.click('#validate_sponsor');
        process.stderr.write("Sponsor validate clicked\n");

        let validated = false;
        for (let i = 0; i < 20; i++) {
            await new Promise(r => setTimeout(r, 500));
            validated = await page.evaluate(() => {
                const ok = document.querySelector('.validate_ok_2');
                const wrong = document.querySelector('.validate_wrong_2');
                if (ok && !ok.classList.contains('hideThis')) return 'success';
                if (wrong && !wrong.classList.contains('hideThis')) return 'failed';
                return false;
            });
            process.stderr.write("Validation check " + i + ": " + validated + "\n");
            if (validated) break;
        }

        if (validated !== 'success') {
            throw new Error("Sponsor validation " + (validated || 'timeout'));
        }

        await page.click('#check_agree');
        await page.evaluate(() => {
            const btn = document.querySelector('#btnRegister');
            if (btn) btn.disabled = false;
        });

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 60000 }),
            page.click('#btnRegister'),
        ]);

        const finalUrl = page.url();
        if (finalUrl.includes('/register')) {
            throw new Error('Still on registration page after submit');
        }

        console.log(JSON.stringify({ success: true, finalUrl }));

    } catch (e) {
        try {
            const screenshotPath = path.join(__dirname, 'error_' + Date.now() + '.png');
            await page.screenshot({ path: screenshotPath, fullPage: true });
            process.stderr.write("Screenshot: " + screenshotPath + "\n");
        } catch (_) {}

        console.log(JSON.stringify({ success: false, error: e.message }));
    } finally {
        await browser.close();
    }
})();
