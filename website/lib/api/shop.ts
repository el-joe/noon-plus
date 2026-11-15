import { z } from "zod";
import { get, request } from "./client";
import { flashSale, money, payment, productCard } from "./schemas";

/*
 * Shopping extras (WP9 part B): flash sales / deals hub, gift cards and
 * vouchers, Nawy One membership, referral landing. Shapes mirror
 * FlashSaleController, GiftCardController, MembershipController and
 * Campaigns\ReferralController.
 */

// FlashSaleController@index / @show (by id or slug `"{name}-{id}"`)
export const getFlashSales = (market: string) => get(market, "flash-sales", z.array(flashSale));
export const getFlashSaleEvent = (market: string, idOrSlug: number | string) => get(market, `flash-sales/${encodeURIComponent(String(idOrSlug))}`, flashSale);

// GiftCardController
export const giftCard = z.object({
  number: z.string(),
  masked_code: z.string(),
  status: z.string(),
  amount: money.nullable(),
  recipient_email: z.string().nullable(),
  recipient_name: z.string().nullable(),
  send_at: z.string().nullable(),
  delivered_at: z.string().nullable(),
  expires_at: z.string(),
  design: z.string().nullable().optional(),
});
export type GiftCard = z.infer<typeof giftCard>;

export const giftCardDesign = z.object({ key: z.string(), name: z.string() });
export type GiftCardDesign = z.infer<typeof giftCardDesign>;
export const getGiftCardDesigns = (market: string) => get(market, "gift-cards/designs", z.array(giftCardDesign));

export const getGiftCards = (market: string) => get(market, "gift-cards", z.array(giftCard));

export type GiftCardInput = {
  amount: string;
  payment_method: string;
  recipient_email: string;
  recipient_name?: string | null;
  message?: string | null;
  send_at?: string | null;
  design?: string;
};
export const buyGiftCard = (market: string, body: GiftCardInput) =>
  request(market, "gift-cards", giftCard.extend({ payment: payment }), { method: "POST", body });
export const redeemGiftCard = (market: string, code: string) =>
  request(market, "gift-cards/redeem", z.object({ redeemed: money.nullable(), masked_code: z.string() }), { method: "POST", body: { code } });
export const redeemVoucher = (market: string, code: string) =>
  request(market, "vouchers/redeem", z.object({ promotional: money.nullable(), expires_at: z.string() }), { method: "POST", body: { code } });

// MembershipController
export const membershipPlan = z.object({
  code: z.string(),
  name: z.string(),
  interval: z.string(),
  price: money.nullable(),
  trial_days: z.number().nullable(),
  free_shipping: z.boolean(),
});
export type MembershipPlan = z.infer<typeof membershipPlan>;

export const membership = z.object({
  member: z.boolean(),
  status: z.string().nullable(),
  plan: z.string().nullable(),
  current_period_end: z.string().nullable(),
  auto_renew: z.boolean().nullable(),
  benefits: z.object({ free_shipping: z.boolean() }),
});
export type Membership = z.infer<typeof membership>;

export const getMembershipPlans = (market: string) => get(market, "membership/plans", z.array(membershipPlan));
export const getMembership = (market: string) => get(market, "membership", membership);
export const subscribeMembership = (market: string, plan: string, paymentMethod: string) =>
  request(market, "membership", membership.extend({ payment: payment.nullable() }), { method: "POST", body: { plan, payment_method: paymentMethod } });
export const cancelMembership = (market: string) => request(market, "membership/cancel", membership, { method: "POST" });

// Campaigns\ReferralController@show (records the click with the visitor token added by the proxy)
export const referral = z.object({
  status: z.string(),
  tracked: z.boolean(),
  creator: z.object({ name: z.string().nullable(), slug: z.string().nullable() }),
  campaign: z.object({ title: z.string().nullable() }),
  /** Product card + `product_slug` (round 2). */
  destination: productCard.extend({ listing_id: z.number(), product_slug: z.string() }),
  /** The creator's other live picks: cards + `product_slug`, `referral_code`. */
  picks: z.array(productCard.extend({ product_slug: z.string().nullable().optional(), referral_code: z.string().nullable().optional() })).default([]),
  share: z.object({ code: z.string(), url: z.string(), qr_payload: z.string() }),
});
export type Referral = z.infer<typeof referral>;
export const getReferral = (market: string, code: string) => get(market, `referrals/${encodeURIComponent(code)}`, referral);
