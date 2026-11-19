import { z } from "zod";
import { get, request } from "./client";

/*
 * Wave 5a/5b: customer support tickets (QC-007, lifecycle §9.3) and the
 * followed stores list (QC-008). All need the customer's session (BFF proxy).
 */

const option = z.object({ value: z.string(), label: z.string() });

export const supportOptions = z.object({
  categories: z.array(option),
  priorities: z.array(option.or(z.string())),
  max_attachments: z.number(),
  attachment_types: z.array(z.string()),
  attachment_max_kb: z.number(),
});
export type SupportOptions = z.infer<typeof supportOptions>;

export const SUPPORT_STATUSES = ["open", "in_progress", "waiting_customer", "resolved", "closed"] as const;

const attachment = z.object({ name: z.string(), mime: z.string().nullable().optional(), size: z.number().nullable().optional(), url: z.string() });
export type SupportAttachment = z.infer<typeof attachment>;

export const ticketMessage = z.object({
  id: z.number(),
  from: z.string(),
  mine: z.boolean(),
  body: z.string(),
  attachments: z.array(attachment).default([]),
  created_at: z.string().nullable(),
});
export type TicketMessage = z.infer<typeof ticketMessage>;

export const ticket = z.object({
  number: z.string(),
  category: z.string(),
  category_label: z.string().nullable().optional(),
  subject: z.string(),
  priority: z.string(),
  status: z.string(),
  status_label: z.string().nullable().optional(),
  order: z.string().nullable().optional(),
  shipment: z.string().nullable().optional(),
  assigned: z.boolean().optional(),
  reply_expected_by: z.string().nullable().optional(),
  first_responded_at: z.string().nullable().optional(),
  resolved_at: z.string().nullable().optional(),
  closed_at: z.string().nullable().optional(),
  last_message_at: z.string().nullable().optional(),
  can_reply: z.boolean(),
  can_reopen: z.boolean(),
  reopen_until: z.string().nullable().optional(),
  can_rate: z.boolean(),
  rating: z.object({ score: z.number(), comment: z.string().nullable().optional(), rated_at: z.string().nullable().optional() }).nullable().optional(),
  created_at: z.string().nullable(),
  messages: z.array(ticketMessage).optional(),
  history: z.array(z.object({ from: z.string().nullable(), to: z.string(), by: z.string().nullable(), at: z.string().nullable() })).optional(),
});
export type Ticket = z.infer<typeof ticket>;

export const getSupportOptions = (market: string) => get(market, "support/categories", supportOptions);
export const getTickets = (market: string, query: { status?: string; page?: number; per_page?: number } = {}) => get(market, "support/tickets", z.array(ticket), query);
export const getTicket = (market: string, number: string) => get(market, `support/tickets/${encodeURIComponent(number)}`, ticket);
/** multipart: category, subject, message, priority?, order?, shipment?, attachments[] */
export const createTicket = (market: string, form: FormData) => request(market, "support/tickets", ticket, { method: "POST", body: form });
/** multipart: body, attachments[] */
export const replyToTicket = (market: string, number: string, form: FormData) =>
  request(market, `support/tickets/${encodeURIComponent(number)}/messages`, z.unknown(), { method: "POST", body: form });
export const reopenTicket = (market: string, number: string, message?: string) =>
  request(market, `support/tickets/${encodeURIComponent(number)}/reopen`, z.unknown(), { method: "POST", body: { message: message || undefined } });
export const rateTicket = (market: string, number: string, rating: number, comment?: string) =>
  request(market, `support/tickets/${encodeURIComponent(number)}/rating`, z.unknown(), { method: "POST", body: { rating, comment: comment || undefined } });

/**
 * Attachment links are signed API URLs (30 minutes). The browser opens them
 * through the same-origin proxy `app/api/support-attachment/route.ts`.
 */
export function attachmentHref(url: string): string {
  return `/api/support-attachment?u=${encodeURIComponent(url)}`;
}

// Followed stores (QC-008)
export const followedSeller = z.object({
  slug: z.string(),
  name: z.string(),
  logo: z.string().nullable().optional(),
  rating: z.object({ average: z.union([z.number(), z.string()]).nullable(), count: z.number() }).nullable().optional(),
  followed_at: z.string().nullable().optional(),
});
export type FollowedSeller = z.infer<typeof followedSeller>;
export const getFollowedSellers = (market: string, page = 1) => get(market, "me/followed-sellers", z.array(followedSeller), { page, per_page: 24 });
export const unfollowSeller = (market: string, slug: string) =>
  request(market, `sellers/${encodeURIComponent(slug)}/follow`, z.object({ following: z.boolean(), followers: z.number() }), { method: "DELETE" });
