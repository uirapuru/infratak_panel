import { test, expect, Page } from '@playwright/test';

// Unique suffix per test run so repeated runs don't collide on email/subdomain
const RUN_ID = Date.now();

function uniqueEmail(): string {
  return `e2e-order-${RUN_ID}@example.com`;
}

function uniqueSubdomain(): string {
  return `e2e-${RUN_ID}`;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

async function goToRegisterForm(page: Page, type = 'opentak'): Promise<void> {
  await page.goto(`/zamow/rejestracja?type=${type}`);
}

async function fillRegisterForm(
  page: Page,
  overrides: Partial<{
    firstName: string;
    lastName: string;
    phone: string;
    email: string;
    password: string;
    subdomain: string;
  }> = {}
): Promise<void> {
  const values = {
    firstName: 'Jan',
    lastName: 'Kowalski',
    phone: '+48 600 100 200',
    email: uniqueEmail(),
    password: 'Test1234!',
    subdomain: uniqueSubdomain(),
    ...overrides,
  };

  await page.locator('#firstName').fill(values.firstName);
  await page.locator('#lastName').fill(values.lastName);
  await page.locator('#phone').fill(values.phone);
  await page.locator('#email').fill(values.email);
  await page.locator('#password').fill(values.password);
  await page.locator('#subdomain').fill(values.subdomain);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test.describe('Wybór serwera (/zamow)', () => {
  test('strona wyboru serwera ładuje się i zawiera kartę OpenTAK', async ({ page }) => {
    await page.goto('/zamow');

    await expect(page).toHaveTitle(/Infratak/);
    await expect(page.locator('h1')).toContainText('Wybierz typ serwera');
    await expect(page.locator('label')).toContainText('OpenTAK Server z Boarding Portalem');
    await expect(page.locator('button[type="submit"]')).toContainText('Dalej');
  });

  test('kliknięcie Dalej przenosi do formularza rejestracyjnego', async ({ page }) => {
    await page.goto('/zamow');

    await page.locator('#type_opentak').check();
    await page.locator('button[type="submit"]').click();

    await expect(page).toHaveURL(/\/zamow\/rejestracja\?type=opentak/);
    await expect(page.locator('h1')).toContainText('Dane rejestracyjne');
  });
});

test.describe('Formularz rejestracyjny (/zamow/rejestracja)', () => {
  test('formularz zawiera wszystkie wymagane pola', async ({ page }) => {
    await goToRegisterForm(page);

    await expect(page.locator('#firstName')).toBeVisible();
    await expect(page.locator('#lastName')).toBeVisible();
    await expect(page.locator('#phone')).toBeVisible();
    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
    await expect(page.locator('#subdomain')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toContainText('Utwórz serwer');
  });

  test('preview domeny pojawia się po wpisaniu subdomeny', async ({ page }) => {
    await goToRegisterForm(page);

    await expect(page.locator('#domain-preview')).toBeHidden();

    await page.locator('#subdomain').fill('moj-serwer');

    await expect(page.locator('#domain-preview')).toBeVisible();
    await expect(page.locator('#domain-preview-text')).toHaveText('moj-serwer.infratak.com');
  });

  test('pole subdomeny akceptuje tylko dozwolone znaki (filtruje na bieżąco)', async ({ page }) => {
    await goToRegisterForm(page);

    // Type mixed-case with special chars — JS should strip them and lowercase
    await page.locator('#subdomain').pressSequentially('Mój Serwer!', { delay: 20 });

    const value = await page.locator('#subdomain').inputValue();
    expect(value).toMatch(/^[a-z0-9\-]*$/);
  });

  test('błędy walidacji: puste wymagane pola', async ({ page }) => {
    await goToRegisterForm(page);

    // Submit empty form
    await page.locator('button[type="submit"]').click();

    // Server-side validation; page should stay on register
    await expect(page).toHaveURL(/\/zamow\/rejestracja/);
    await expect(page.locator('.invalid-feedback').first()).toBeVisible();
  });

  test('błąd walidacji: hasło za krótkie', async ({ page }) => {
    await goToRegisterForm(page);
    await fillRegisterForm(page, { password: 'short' });
    await page.locator('button[type="submit"]').click();

    await expect(page).toHaveURL(/\/zamow\/rejestracja/);
    await expect(page.locator('#password ~ .invalid-feedback, #password + .form-text + .invalid-feedback'))
      .toContainText('co najmniej 8 znaków');
  });

  test('błąd walidacji: nieprawidłowy format subdomeny', async ({ page }) => {
    await goToRegisterForm(page);

    // Fill valid data for all other fields
    await fillRegisterForm(page, { subdomain: 'placeholder' });

    // Override subdomain with a value starting with hyphen, bypassing the frontend JS filter
    await page.locator('#subdomain').evaluate((el: HTMLInputElement) => {
      el.value = '-bad-start';
    });
    await page.locator('button[type="submit"]').click();

    await expect(page).toHaveURL(/\/zamow\/rejestracja/);
    await expect(page.locator('#subdomain ~ .invalid-feedback').first()).toBeVisible();
  });
});

test.describe('Szczęśliwa ścieżka: rejestracja i sukces', () => {
  test('pełny formularz → strona sukcesu z adresem serwera i danymi logowania', async ({ page }) => {
    const email     = uniqueEmail();
    const subdomain = uniqueSubdomain();

    await goToRegisterForm(page);
    await fillRegisterForm(page, { email, subdomain });
    await page.locator('button[type="submit"]').click();

    // Should redirect to success page
    await expect(page).toHaveURL(/\/zamow\/sukces/, { timeout: 10_000 });

    // Server domain visible
    await expect(page.locator('code').first()).toContainText(`${subdomain}.infratak.com`);

    // Portal domain visible
    await expect(page.locator('code').nth(1)).toContainText(`portal.${subdomain}.infratak.com`);

    // Login credentials: email visible in a <code> element
    await expect(page.locator(`code:has-text("${email}")`)).toBeVisible();

    // Password displayed (not empty)
    const pwCode = page.locator('#pwd-value');
    await expect(pwCode).not.toBeEmpty();

    // OTS password note visible
    await expect(page.locator('text=Hasło administratora OpenTAK')).toBeVisible();
  });

  test('po odświeżeniu strony sukcesu przekierowanie na /zamow (dane sesji jednorazowe)', async ({ page }) => {
    const email     = uniqueEmail();
    const subdomain = uniqueSubdomain();

    await goToRegisterForm(page);
    await fillRegisterForm(page, { email, subdomain });
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/zamow\/sukces/, { timeout: 10_000 });

    // Reload — session data already consumed
    await page.reload();
    await expect(page).toHaveURL(/\/zamow$/);
  });

  test('duplikat e-maila zwraca błąd walidacji', async ({ page }) => {
    const email     = uniqueEmail();
    const subdomain = uniqueSubdomain();

    // First registration
    await goToRegisterForm(page);
    await fillRegisterForm(page, { email, subdomain });
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/zamow\/sukces/, { timeout: 10_000 });

    // Second registration — same email, different subdomain
    await goToRegisterForm(page);
    await fillRegisterForm(page, {
      email,
      subdomain: `${subdomain}-2`,
    });
    await page.locator('button[type="submit"]').click();

    await expect(page).toHaveURL(/\/zamow\/rejestracja/);
    await expect(page.locator('#email ~ .invalid-feedback')).toContainText('już istnieje');
  });

  test('duplikat subdomeny zwraca błąd walidacji', async ({ page }) => {
    const email1    = `${uniqueEmail()}-a`;
    const email2    = `${uniqueEmail()}-b`;
    const subdomain = uniqueSubdomain();

    // First registration
    await goToRegisterForm(page);
    await fillRegisterForm(page, { email: email1, subdomain });
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/zamow\/sukces/, { timeout: 10_000 });

    // Second registration — different email, same subdomain
    await goToRegisterForm(page);
    await fillRegisterForm(page, { email: email2, subdomain });
    await page.locator('button[type="submit"]').click();

    await expect(page).toHaveURL(/\/zamow\/rejestracja/);
    await expect(page.locator('#subdomain').locator('~ .invalid-feedback, + .form-text + .invalid-feedback'))
      .toContainText('zajęta');
  });
});
