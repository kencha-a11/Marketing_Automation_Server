// automation/bot.js
const puppeteer = require('puppeteer');

// Parse the payload string sent directly from Laravel process command
const inputData = JSON.parse(process.argv[2]);

(async () => {
    let browser;
    try {
        browser = await puppeteer.launch({
            headless: true,
            // Critical flags to prevent server execution permission blocks
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
        });

        const page = await browser.newPage();

        // Open the target third-party network site registration layout
        await page.goto('https://app.elevatewellworldwide.com/register', {
            waitUntil: 'networkidle2',
            timeout: 60000
        });

        // Fill standard identity form elements
        await page.type('input[placeholder="First Name"]', inputData.firstName);
        await page.type('input[placeholder="Last Name"]', inputData.lastName);
        await page.type('input[placeholder="Mobile"]', inputData.mobile);
        await page.type('input[placeholder="Email"]', inputData.email);

        // Target specific dual password fields safely using indexed references
        const passwordInputs = await page.$$('input[type="password"]');
        if (passwordInputs.length >= 2) {
            await passwordInputs[0].type(inputData.password);
            await passwordInputs[1].type(inputData.password);
        }

        // Select Gender
        await page.select('select', inputData.gender);

        // Handle structural verification tracking referrals code validation blocks
        if (inputData.referredBy) {
            await page.type('input[placeholder="Email address"]', inputData.referredBy);
            // Dynamic check for clicking the verification/validation trigger element
            const validateButton = await page.$('button[type="button"]');
            if (validateButton) {
                await validateButton.click();
                await new Promise(r => setTimeout(r, 2000)); // Allow API lookup time safely
            }
        }

        // Force check terms and privacy checkbox wrapper layout components
        await page.click('input[type="checkbox"]');

        // Submit form execution layout and wait for successful landing routing
        await Promise.all([
            page.click('button[type="submit"]'),
            page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 60000 }),
        ]);

        // Output success feedback string straight back into PHP system buffer
        console.log(JSON.stringify({
            success: true,
            finalUrl: page.url()
        }));

    } catch (error) {
        // Output failure payload straight back into standard error logs
        console.log(JSON.stringify({
            success: false,
            error: error.message
        }));
    } finally {
        if (browser) await browser.close();
    }
})();
