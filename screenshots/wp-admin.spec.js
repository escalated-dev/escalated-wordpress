const { test } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const resultsDir = path.join(__dirname, 'results');

test.beforeAll(() => {
  if (!fs.existsSync(resultsDir)) {
    fs.mkdirSync(resultsDir, { recursive: true });
  }
});

/**
 * Navigate to a wp-admin page, wait for it to load, and take a screenshot.
 * Uses .wrap as the primary selector (standard WordPress admin wrapper),
 * but falls back to #wpbody if .wrap is not found.
 */
async function screenshotPage(page, url, filename) {
  await page.goto(url);
  try {
    await page.waitForSelector('.wrap', { timeout: 8000 });
  } catch {
    // Fallback: wait for the admin body to load
    await page.waitForSelector('#wpbody', { timeout: 5000 }).catch(() => {});
  }
  // Small delay for CSS/JS to settle
  await page.waitForTimeout(500);
  await page.screenshot({ path: path.join(resultsDir, filename), fullPage: true });
}

test.describe('Escalated WP-Admin Screenshots', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'admin');
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**');
  });

  test('Ticket List', async ({ page }) => {
    await screenshotPage(page, '/wp-admin/admin.php?page=escalated', 'ticket-list.png');
  });

  test('Ticket Detail', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=escalated');
    try {
      await page.waitForSelector('.wrap', { timeout: 8000 });
    } catch {
      await page.waitForSelector('#wpbody', { timeout: 5000 }).catch(() => {});
    }
    const ticketLink = page.locator('table.wp-list-table tbody tr:first-child a').first();
    if (await ticketLink.count() > 0) {
      await ticketLink.click();
      await page.waitForTimeout(1000);
      await page.screenshot({ path: path.join(resultsDir, 'ticket-detail.png'), fullPage: true });
    }
  });

  test('Departments', async ({ page }) => {
    await screenshotPage(page, '/wp-admin/admin.php?page=escalated-departments', 'departments.png');
  });

  test('SLA Policies', async ({ page }) => {
    await screenshotPage(page, '/wp-admin/admin.php?page=escalated-sla-policies', 'sla-policies.png');
  });

  test('Automations', async ({ page }) => {
    await screenshotPage(page, '/wp-admin/admin.php?page=escalated-automations', 'automations.png');
  });

  test('Tags', async ({ page }) => {
    await screenshotPage(page, '/wp-admin/admin.php?page=escalated-tags', 'tags.png');
  });

  test('Canned Responses', async ({ page }) => {
    await screenshotPage(page, '/wp-admin/admin.php?page=escalated-canned-responses', 'canned-responses.png');
  });

  test('Macros', async ({ page }) => {
    await screenshotPage(page, '/wp-admin/admin.php?page=escalated-macros', 'macros.png');
  });

  test('Reports', async ({ page }) => {
    await screenshotPage(page, '/wp-admin/admin.php?page=escalated-reports', 'reports.png');
  });

  test('Settings', async ({ page }) => {
    await screenshotPage(page, '/wp-admin/admin.php?page=escalated-settings', 'settings.png');
  });

  test('API Tokens', async ({ page }) => {
    await screenshotPage(page, '/wp-admin/admin.php?page=escalated-api-tokens', 'api-tokens.png');
  });
});
