import { z } from "zod";
import { get, request } from "./client";

/*
 * QA-C05 / G-A6: the customer's saved cards (`/me/cards`). The API only ever
 * returns gateway tokens summarised as brand, last 4 and expiry — the website
 * never collects or shows a card number.
 */

export const savedCard = z.object({
  id: z.number(),
  gateway: z.string().nullable().optional(),
  brand: z.string().nullable().optional(),
  last4: z.string(),
  exp_month: z.number().nullable().optional(),
  exp_year: z.number().nullable().optional(),
  expired: z.boolean().default(false),
  is_default: z.boolean().default(false),
});
export type SavedCard = z.infer<typeof savedCard>;

export const getSavedCards = (market: string) => get(market, "me/cards", z.array(savedCard));
export const makeDefaultCard = (market: string, id: number) => request(market, `me/cards/${id}/default`, savedCard, { method: "POST" });
export const deleteSavedCard = (market: string, id: number) => request(market, `me/cards/${id}`, z.object({ removed: z.boolean() }), { method: "DELETE" });

const BRANDS: Record<string, string> = {
  visa: "Visa",
  mastercard: "Mastercard",
  amex: "American Express",
  american_express: "American Express",
  mada: "mada",
  meeza: "Meeza",
  discover: "Discover",
  jcb: "JCB",
  unionpay: "UnionPay",
  diners: "Diners Club",
};

/** Display name for a gateway brand code (`visa` → "Visa"); null when the gateway sent none. */
export function cardBrandName(brand: string | null | undefined): string | null {
  if (!brand) return null;
  const key = brand.toLowerCase().replace(/[\s-]+/g, "_");
  return BRANDS[key] ?? brand.charAt(0).toUpperCase() + brand.slice(1);
}

/** "MM/YY" expiry, or null when the gateway reported none. */
export function cardExpiry(card: Pick<SavedCard, "exp_month" | "exp_year">): string | null {
  if (!card.exp_month || !card.exp_year) return null;
  return `${String(card.exp_month).padStart(2, "0")}/${String(card.exp_year % 100).padStart(2, "0")}`;
}
