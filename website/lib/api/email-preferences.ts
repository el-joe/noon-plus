import { z } from "zod";
import { get, request } from "./client";

/*
 * Email system: the customer's email preferences (`me/email-preferences`)
 * and the one-click unsubscribe token endpoints (`email/unsubscribe/{token}`,
 * no market, no sign-in — the token is signed by the API).
 */

export const emailCategory = z.object({
  topic: z.string(),
  label: z.string(),
  description: z.string(),
  enabled: z.boolean(),
  locked: z.boolean(),
});
export type EmailCategory = z.infer<typeof emailCategory>;

const preferences = z.object({ categories: z.array(emailCategory) });

export const getEmailPreferences = (market: string) => get(market, "me/email-preferences", preferences);
export const saveEmailPreferences = (market: string, items: { topic: string; enabled: boolean }[]) =>
  request(market, "me/email-preferences", preferences, { method: "PUT", body: { preferences: items } });

export const unsubscribeInfo = z.object({
  topic: z.string(),
  label: z.string(),
  email: z.string().nullable(),
  subscribed: z.boolean(),
});
export type UnsubscribeInfo = z.infer<typeof unsubscribeInfo>;

const tokenPath = (token: string) => `email/unsubscribe/${encodeURIComponent(token)}`;
export const getUnsubscribe = (token: string) => request(null, tokenPath(token), unsubscribeInfo);
export const unsubscribe = (token: string) => request(null, tokenPath(token), unsubscribeInfo, { method: "POST" });
export const resubscribe = (token: string) => request(null, tokenPath(token), unsubscribeInfo, { method: "DELETE" });
