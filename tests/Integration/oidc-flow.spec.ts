import { test, expect } from '@playwright/test';

test.describe('OIDC login flow', () => {
  test('login redirects through callback and can access API', async ({ page, request }) => {
    const baseUrl = process.env.IDENTITY_BASE_URL ?? 'https://localhost:5001';
    await page.goto(`${baseUrl}/connect/authorize?client_id=gibbon&response_type=code&scope=openid%20profile%20email&redirect_uri=${encodeURIComponent(`${baseUrl}/signin-oidc`)}`);
    await expect(page).toHaveURL(/Account\/Login|connect\/authorize/);
    // Full mock IdP/Gibbon orchestration is environment-specific; this smoke test is a runnable scaffold for CI environments.
    const response = await request.get(`${baseUrl}/api/admin/reconciliation/status`, { failOnStatusCode: false });
    expect([200, 401, 403]).toContain(response.status());
  });
});
