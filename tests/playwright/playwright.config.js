const { defineConfig } = require('@playwright/test');

// Pure API testing — uses Playwright's `request` fixture, so no browser
// binaries are required (`npx playwright install` is unnecessary).
// Normalize to a single trailing slash so relative paths ("api/v1/...") resolve
// correctly whether the app is at the host root or under a sub-path
// (e.g. http://host/foo/bagisto/public).
const BASE_URL = (process.env.API_BASE_URL || 'http://127.0.0.1:8000').replace(/\/+$/, '') + '/';

module.exports = defineConfig({
    testDir: './tests',
    fullyParallel: true,
    forbidOnly: !! process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',

    use: {
        baseURL: BASE_URL,
        extraHTTPHeaders: {
            Accept: 'application/json',
        },
        ignoreHTTPSErrors: true,
    },
});
