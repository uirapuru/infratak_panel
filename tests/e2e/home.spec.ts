import { test, expect } from '@playwright/test';

test('landing page is reachable and renders key sections', async ({ page }) => {
  await page.goto('/');

  await expect(page.locator('header.hero')).toBeVisible();
  await expect(page.locator('section#offer')).toBeVisible();
  await expect(page.locator('section#contact a[href="mailto:kontakt@infratak.pl"]')).toBeVisible();
});
