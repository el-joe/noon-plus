/**
 * Test accounts created by backend/database/seeders/E2eSeeder.php (test values
 * only; the seeder refuses production). Panel users have email 2FA turned on.
 */
export const PASSWORD = "E2e-Passw0rd!";

export const CUSTOMER = { email: "e2e-customer@nawy.test", password: PASSWORD };

export type PanelKey = "admin" | "partner" | "travel-agency" | "carrier" | "delivery" | "marketer";

export const PANELS: Record<PanelKey, { email: string; label: string }> = {
  admin: { email: "e2e-admin@nawy.test", label: "Admin" },
  partner: { email: "e2e-seller@nawy.test", label: "Seller Center" },
  "travel-agency": { email: "e2e-travel@nawy.test", label: "Travel Partner" },
  carrier: { email: "e2e-carrier@nawy.test", label: "Shipping" },
  delivery: { email: "e2e-driver@nawy.test", label: "Delivery" },
  marketer: { email: "e2e-marketer@nawy.test", label: "Creator Studio" },
};

export const BASE_DOMAIN = process.env.E2E_BASE_DOMAIN ?? "nawy.test";
export const API_PORT = process.env.E2E_API_PORT ?? "8000";

export const panelUrl = (panel: PanelKey, path = "") => `http://${panel}.${BASE_DOMAIN}:${API_PORT}/${path.replace(/^\//, "")}`;
export const panelState = (panel: PanelKey) => `e2e/.auth/${panel}.json`;
export const CUSTOMER_STATE = "e2e/.auth/customer.json";
