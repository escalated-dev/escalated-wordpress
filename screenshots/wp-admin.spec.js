const { test } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const resultsDir = path.join(__dirname, 'results');

test.beforeAll(() => {
  if (!fs.existsSync(resultsDir)) {
    fs.mkdirSync(resultsDir, { recursive: true });
  }
});

test.describe('Escalated WP-Admin Screenshots', () => {
  test.beforeEach(async ({ page }) => {
    // Login to WordPress admin
    await page.goto('/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'admin');
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**');
  });

  test('Ticket List', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=escalated');
    await page.waitForSelector('.wrap', { timeout: 10000 });
    await page.screenshot({ path: path.join(resultsDir, 'ticket-list.png'), fullPage: true });
  });

  test('Ticket Detail', async ({ page }) => {
    // Navigate to tickets, click first one if exists
    await page.goto('/wp-admin/admin.php?page=escalated');
    await page.waitForSelector('.wrap', { timeout: 10000 });
    const ticketLink = page.locator('table.wp-list-table tbody tr:first-child a').first();
    if (await ticketLink.count() > 0) {
      await ticketLink.click();
      await page.waitForSelector('.wrap', { timeout: 10000 });
      await page.screenshot({ path: path.join(resultsDir, 'ticket-detail.png'), fullPage: true });
    }
  });

  test('Departments', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=escalated-departments');
    await page.waitForSelector('.wrap', { timeout: 10000 });
    await page.screenshot({ path: path.join(resultsDir, 'departments.png'), fullPage: true });
  });

  test('SLA Policies', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=escalated-sla-policies');
    await page.waitForSelector('.wrap', { timeout: 10000 });
    await page.screenshot({ path: path.join(resultsDir, 'sla-policies.png'), fullPage: true });
  });

  test('Automations', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=escalated-automations');
    await page.waitForSelector('.wrap', { timeout: 10000 });
    await page.screenshot({ path: path.join(resultsDir, 'automations.png'), fullPage: true });
  });

  test('Tags', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=escalated-tags');
    await page.waitForSelector('.wrap', { timeout: 10000 });
    await page.screenshot({ path: path.join(resultsDir, 'tags.png'), fullPage: true });
  });

  test('Canned Responses', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=escalated-canned-responses');
    await page.waitForSelector('.wrap', { timeout: 10000 });
    await page.screenshot({ path: path.join(resultsDir, 'canned-responses.png'), fullPage: true });
  });

  test('Macros', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=escalated-macros');
    await page.waitForSelector('.wrap', { timeout: 10000 });
    await page.screenshot({ path: path.join(resultsDir, 'macros.png'), fullPage: true });
  });

  test('Reports', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=escalated-reports');
    await page.waitForSelector('.wrap', { timeout: 10000 });
    await page.screenshot({ path: path.join(resultsDir, 'reports.png'), fullPage: true });
  });

  test('Settings', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=escalated-settings');
    await page.waitForSelector('.wrap', { timeout: 10000 });
    await page.screenshot({ path: path.join(resultsDir, 'settings.png'), fullPage: true });
  });

  test('API Tokens', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=escalated-api-tokens');
    await page.waitForSelector('.wrap', { timeout: 10000 });
    await page.screenshot({ path: path.join(resultsDir, 'api-tokens.png'), fullPage: true });
  });
});
