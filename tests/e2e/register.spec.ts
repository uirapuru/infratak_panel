import { test, expect } from '@playwright/test';

test('registration shows friendly error when mail transport is unavailable', async ({ page }) => {
  await page.goto('/register');

  const email = `e2e-mail-fail-${Date.now()}@example.com`;

  await page.locator('input#email').fill(email);
  await page.locator('input#password').fill('StrongPass123!');
  await page.locator('button[type="submit"]').click();

  await expect(page).toHaveURL(/\/register$/);
  await expect(page.locator('.alert-danger')).toContainText('Nie udalo sie wyslac e-maila weryfikacyjnego');
});
