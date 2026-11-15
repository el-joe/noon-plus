import { z } from "zod";
import { get, request } from "./client";
import { me } from "./schemas";

/*
 * Account round 2 (G-W29): profile (AccountController), notification centre
 * (NotificationController) and signed-contract history
 * (ContractApiController@signed). All need the customer's session (BFF proxy).
 */

export type MarketingChannel = "email" | "sms" | "push" | "whatsapp";
export const MARKETING_CHANNELS: MarketingChannel[] = ["email", "sms", "push", "whatsapp"];

export type ProfileInput = { name?: string; locale?: "en" | "ar"; marketing?: Partial<Record<MarketingChannel, boolean>> };
export const updateProfile = (market: string, body: ProfileInput) => request(market, "me", me, { method: "PATCH", body });
export const uploadAvatar = (market: string, file: File) => {
  const form = new FormData();
  form.append("avatar", file);
  return request(market, "me/avatar", me, { method: "POST", body: form });
};
export const removeAvatar = (market: string) => request(market, "me/avatar", me, { method: "DELETE" });
export const requestEmailChange = (market: string, email: string) =>
  request(market, "me/email", z.object({ sent: z.boolean(), email: z.string() }), { method: "POST", body: { email } });
export const confirmEmailChange = (market: string, email: string, code: string) => request(market, "me/email/verify", me, { method: "POST", body: { email, code } });

// NotificationController
export const notification = z.object({
  id: z.number(),
  type: z.string(),
  title: z.string().nullable(),
  body: z.string().nullable(),
  /** Storefront path without the market (`/account/orders/{n}`), or null. */
  link: z.string().nullable(),
  data: z.unknown().optional(),
  read: z.boolean(),
  read_at: z.string().nullable(),
  created_at: z.string().nullable(),
});
export type Notification = z.infer<typeof notification>;

export const getNotifications = (market: string, query: { unread?: 1; page?: number; per_page?: number } = {}) => get(market, "me/notifications", z.array(notification), query);
export const getUnreadCount = (market: string) => get(market, "me/notifications/unread-count", z.object({ unread: z.number() }));
export const markNotificationRead = (market: string, id: number) =>
  request(market, `me/notifications/${id}/read`, notification.extend({ unread: z.number() }), { method: "POST" });
export const markAllNotificationsRead = (market: string, ids?: number[]) =>
  request(market, "me/notifications/read-all", z.object({ marked: z.number(), unread: z.number() }), { method: "POST", body: ids ? { ids } : {} });

/**
 * Storefront link of a notification, prefixed with the market. Only same-site
 * paths are followed; viewing / reserve notifications open the requests tab.
 */
export function notificationHref(n: Pick<Notification, "link" | "type">, marketSlug: string): string | null {
  const link = n.link;
  if (!link || !link.startsWith("/") || link.startsWith("//")) return null;
  const path = (n.type.startsWith("viewing.") || n.type.startsWith("reserve.")) && link === "/account/inquiries" ? "/account/inquiries?tab=requests" : link;
  return `/${marketSlug}${path}`;
}

// ContractApiController@signed
export const signedContract = z.object({
  id: z.number(),
  kind: z.string().nullable(),
  title: z.string().nullable(),
  version_id: z.number().nullable(),
  version: z.union([z.number(), z.string()]).nullable(),
  status: z.string(),
  method: z.string().nullable().optional(),
  purpose: z.string().nullable().optional(),
  subject: z.unknown().optional(),
  full_name: z.string().nullable().optional(),
  locale: z.string().nullable().optional(),
  sha256: z.string().nullable().optional(),
  signed_at: z.string().nullable(),
  superseded_at: z.string().nullable().optional(),
  url: z.string().nullable().optional(),
});
export type SignedContract = z.infer<typeof signedContract>;
export const getSignedContracts = (market: string, page = 1) => get(market, "me/contracts/signed", z.array(signedContract), { page });
