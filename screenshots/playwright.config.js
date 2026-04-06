const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '.',
  timeout: 60000,
  use: {
    baseURL: 'http://localhost:8080',
    viewport: { width: 1440, height: 900 },
    screenshot: 'off',
  },
});
