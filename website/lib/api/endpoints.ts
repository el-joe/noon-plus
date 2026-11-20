import { z } from "zod";
import { get, request, type Query } from "./client";
import * as s from "./schemas";

/** One function per backend endpoint the storefront uses (routes/api.php). */

const ok = z.unknown();

// Catalogue
export const getCategories = (market: string) => get(market, "categories", z.array(s.category));

export type ProductFilters = {
  q?: string;
  category?: string;
  brand?: string;
  min_price?: string;
  max_price?: string;
  seller?: string;
  rating?: string;
  /** QC-027: `fbm,fbn`, `new,refurbished`, `1`. */
  fulfilment?: string;
  condition?: string;
  deals?: string;
  /** Category page rail: `hour` | `tomorrow` | `two_days`, `A,B`, `1`, `1`. */
  arrives?: string;
  grade?: string;
  price_drop?: string;
  new_arrivals?: string;
  sort?: "recommended" | "price_asc" | "price_desc" | "newest" | "rating" | "bestselling";
  page?: number;
  per_page?: number;
  /** `attr[key]=v1,v2` variant option filters. */
  attr?: Record<string, string>;
};
export function filterQuery(filters: ProductFilters): Query {
  const { attr, ...rest } = filters;
  const query: Query = { ...rest };
  for (const [key, value] of Object.entries(attr ?? {})) if (value) query[`attr[${key}]`] = value;
  return query;
}
export const getProducts = (market: string, filters: ProductFilters, headers?: Record<string, string>) =>
  get(market, "products", z.array(s.productCard), filterQuery(filters), { headers });
export const getFacets = (market: string, filters: ProductFilters) => get(market, "products/facets", s.facets, filterQuery({ ...filters, page: undefined, sort: undefined }));
export const getBrands = (market: string, query: { category?: string; q?: string } = {}) => get(market, "brands", z.array(s.brand), query);
export const getBrand = (market: string, slug: string) => get(market, `brands/${encodeURIComponent(slug)}`, s.brandDetail);
export const getCategoryTree = (market: string) => get(market, "categories/tree", z.array(s.categoryNode));
export const getCategoryMenu = (market: string) => get(market, "catalog/menu", z.array(s.menuCategory));
export const getCategory = (market: string, slug: string) => get(market, `categories/${encodeURIComponent(slug)}`, s.categoryDetail);
export const getSizeGuide = (market: string, slug: string, query: { unit?: string; height?: string; height_unit?: string } = {}) =>
  get(market, `products/${encodeURIComponent(slug)}/size-guide`, s.sizeGuide, query);

// Reviews & Q&A
export type ReviewQuery = { sort?: "recent" | "helpful" | "rating_desc" | "rating_asc"; rating?: number; with_photos?: number; page?: number; per_page?: number };
export const getProductReviews = (market: string, slug: string, query: ReviewQuery = {}) => get(market, `products/${encodeURIComponent(slug)}/reviews`, s.reviewPage, query);
export const createReview = (market: string, form: FormData) => request(market, "reviews", s.review, { method: "POST", body: form });
export const voteHelpful = (market: string, id: number, helpful: boolean) =>
  request(market, `reviews/${id}/helpful`, z.object({ id: z.number(), helpful_count: z.number(), voted_helpful: z.boolean() }), { method: helpful ? "POST" : "DELETE" });
export const REVIEW_REPORT_REASONS = ["spam", "offensive", "fake", "irrelevant", "personal_data", "other"] as const;
export type ReviewReportReason = (typeof REVIEW_REPORT_REASONS)[number];
export const reportReview = (market: string, id: number, reason: ReviewReportReason, note?: string) =>
  request(market, `reviews/${id}/report`, ok, { method: "POST", body: { reason, note: note || null } });
export const getMyReviews = (market: string, page = 1) => get(market, "me/reviews", z.array(s.review), { page });
export const getReviewableItems = (market: string) => get(market, "me/reviewable-items", z.array(s.reviewableItem));
export const getQuestions = (market: string, slug: string, page = 1) => get(market, `products/${encodeURIComponent(slug)}/questions`, z.array(s.question), { page });
export const askQuestion = (market: string, slug: string, body: string) => request(market, `products/${encodeURIComponent(slug)}/questions`, s.question, { method: "POST", body: { body } });

// Address book
export type AddressBookInput = {
  label?: string | null;
  name: string;
  phone: string;
  city: string;
  area: string;
  street: string;
  building?: string | null;
  apartment?: string | null;
  landmark?: string | null;
  is_default?: boolean;
};
export const getAddresses = (market: string) => get(market, "addresses", z.array(s.address));
export const createAddress = (market: string, body: AddressBookInput) => request(market, "addresses", s.address, { method: "POST", body });
export const updateAddress = (market: string, id: number, body: Partial<AddressBookInput>) => request(market, `addresses/${id}`, s.address, { method: "PATCH", body });
export const deleteAddress = (market: string, id: number) => request(market, `addresses/${id}`, ok, { method: "DELETE" });
export const makeDefaultAddress = (market: string, id: number) => request(market, `addresses/${id}/default`, s.address, { method: "POST" });
export const getDeliveryAreas = (market: string) => get(market, "delivery-areas", z.array(s.deliveryCity));

// Wishlist
export const getWishlist = (market: string) => get(market, "wishlist", z.array(s.wishlistCard), { per_page: 100 });
export const addToWishlist = (market: string, product: string) => request(market, "wishlist", ok, { method: "POST", body: { product } });
export const removeFromWishlist = (market: string, product: string) => request(market, `wishlist/${encodeURIComponent(product)}`, ok, { method: "DELETE" });

// Newsletter (double opt-in)
const newsletterStatus = z.object({ status: z.string(), message: z.string().optional(), email: z.string().optional() });
export const subscribeNewsletter = (market: string, email: string, source?: string) =>
  request(market, "newsletter/subscribe", newsletterStatus, { method: "POST", body: { email, source } });
export const confirmNewsletter = (market: string, token: string) => request(market, "newsletter/confirm", newsletterStatus, { method: "POST", body: { token } });
export const unsubscribeNewsletter = (market: string, token: string) => request(market, "newsletter/unsubscribe", newsletterStatus, { method: "POST", body: { token } });
export const getProduct = (market: string, slug: string) => get(market, `products/${encodeURIComponent(slug)}`, s.productDetail);

// Content
/** A published page-builder page; `preview` (signed admin link) serves the current draft instead. */
export const getPage = (market: string, slug: string, preview?: string | null) =>
  get(market, `pages/${encodeURIComponent(slug)}`, s.cmsPage, preview ? { preview } : undefined);
export const getFooter = (market: string) => get(market, "footer", s.footer);
export const getFlashSale = (market: string, id: number | string) => get(market, `flash-sales/${encodeURIComponent(String(id))}`, s.flashSale);
export const getAdPlacements = (market: string, placement: string, query: { category?: string; with?: "products" } = {}) =>
  get(market, `ads/placements/${encodeURIComponent(placement)}`, z.array(s.adPlacement), query);
export const recordAdClick = (market: string, token: string) => request(market, "ads/click", ok, { method: "POST", body: { token } });
export const getContract = (market: string, version: number) => get(market, `contracts/${version}`, s.contractText);
export const acceptContract = (market: string, version: number, fullName: string) =>
  request(market, `contracts/${version}/accept`, ok, { method: "POST", body: { full_name: fullName, accept: true } });

// Auth (email code, ADR 5)
export const sendEmailCode = (market: string, email: string) => request(market, "auth/email-code/send", ok, { method: "POST", body: { email } });
export const verifyEmailCode = (market: string, email: string, code: string, deviceName: string) =>
  request(market, "auth/email-code/verify", s.signIn, { method: "POST", body: { email, code, device_name: deviceName } });
export const getMe = (market: string) => get(market, "me", s.me);
// Data-migration plan §5.2: an old Nawy account with a phone only signs in once by SMS code, then adds an email.
export const sendPhoneCode = (market: string, phone: string) => request(market, "auth/phone-code/send", ok, { method: "POST", body: { phone } });
export const verifyPhoneCode = (market: string, phone: string, code: string, deviceName: string) =>
  request(market, "auth/phone-code/verify", s.signIn, { method: "POST", body: { phone, code, device_name: deviceName } });

// D9: optional password, Google / Apple (QA fixes wave 3)
export type SocialProvider = "google" | "apple";
export const SOCIAL_PROVIDERS: SocialProvider[] = ["google", "apple"];
export const passwordLogin = (market: string, email: string, password: string, deviceName: string) =>
  request(market, "auth/password/login", s.signIn, { method: "POST", body: { email, password, device_name: deviceName } });
export const forgotPassword = (market: string, email: string) => request(market, "auth/password/forgot", z.object({ sent: z.boolean() }), { method: "POST", body: { email } });
export type PasswordResetInput = { email: string; token?: string; code?: string; password: string; password_confirmation: string; device_name?: string };
export const resetPassword = (market: string, body: PasswordResetInput) => request(market, "auth/password/reset", s.passwordReset, { method: "POST", body });
export const changePassword = (market: string, body: { current_password?: string; password: string; password_confirmation: string }) =>
  request(market, "me/password", z.object({ has_password: z.boolean() }), { method: "POST", body });
export const socialRedirectUrl = (market: string, provider: SocialProvider, returnTo?: string) =>
  get(market, `auth/social/${provider}/redirect`, z.object({ url: z.string() }), { return_to: returnTo });
export const exchangeSocialTicket = (market: string, ticket: string, deviceName: string) =>
  request(market, "auth/social/exchange", s.signIn, { method: "POST", body: { ticket, device_name: deviceName } });
export const unlinkSocialAccount = (market: string, provider: SocialProvider) => request(market, `me/social-accounts/${provider}`, ok, { method: "DELETE" });
export const logout = (market: string) => request(market, "auth/logout", ok, { method: "POST" });

// Cart (guest via X-Cart-Token, added by the proxy)
export const getCart = (market: string, paymentMethod?: string) => get(market, "cart", s.cart, { payment_method: paymentMethod });
export const setCartItem = (market: string, listingId: number, quantity: number) =>
  request(market, "cart/items", s.cart, { method: "PUT", body: { listing_id: listingId, quantity } });
export const removeCartItem = (market: string, listingId: number) => request(market, `cart/items/${listingId}`, s.cart, { method: "DELETE" });
export const setCartWarranty = (market: string, listingId: number, planId: number | null) =>
  request(market, `cart/items/${listingId}/warranty`, s.cart, { method: "PUT", body: { warranty_plan_id: planId } });
export const setCartShipping = (market: string, body: { city?: string | null; area?: string | null; method?: string | null }) =>
  request(market, "cart/shipping", s.cart, { method: "PUT", body });
export const applyCoupon = (market: string, code: string) => request(market, "cart/coupons", s.cart, { method: "POST", body: { code } });
export const removeCoupon = (market: string, code: string) => request(market, `cart/coupons/${encodeURIComponent(code)}`, s.cart, { method: "DELETE" });
export const setCartMeasurements = (market: string, listingId: number, measurements: Record<string, string | number>, note?: string | null) =>
  request(market, `cart/items/${listingId}/measurements`, s.cart, { method: "PUT", body: { measurements, note: note || null } });
export const mergeCart = (market: string) => request(market, "cart/merge", s.cart, { method: "POST" });

// Checkout & orders
export const getPaymentMethods = (market: string, amount: string) => get(market, "checkout/payment-methods", z.array(s.paymentMethod), { amount });
export type AddressInput = { name: string; phone?: string; city: string; area: string; street: string; building?: string };
/** `idempotencyKey` (QC-044): the same key on a retry returns the first answer instead of placing a second order. */
export const placeOrder = (market: string, body: { payment_method: string; address?: AddressInput; address_id?: number; referral_code?: string }, idempotencyKey?: string) =>
  request(market, "orders", s.order, { method: "POST", body, headers: idempotencyKey ? { "Idempotency-Key": idempotencyKey } : {} });
export type OrderTab = "all" | "in_progress" | "delivered" | "cancelled" | "returns";
export const ORDER_TABS: OrderTab[] = ["all", "in_progress", "delivered", "cancelled", "returns"];
export const getOrders = (market: string, page = 1, query: { tab?: OrderTab; q?: string; per_page?: number } = {}) =>
  get(market, "orders", z.array(s.order), { page, tab: query.tab === "all" ? undefined : query.tab, q: query.q, per_page: query.per_page });
export const getCancelReasons = (market: string) => get(market, "orders/cancel-reasons", z.array(z.object({ code: z.string(), label: z.string(), needs_note: z.boolean() })));
export const getOrder = (market: string, number: string) => get(market, `orders/${encodeURIComponent(number)}`, s.order);
export const verifyPayment = (market: string, number: string) => request(market, `orders/${encodeURIComponent(number)}/payments/verify`, s.order, { method: "POST" });
export const retryPayment = (market: string, number: string, paymentMethod: string) =>
  request(market, `orders/${encodeURIComponent(number)}/payments`, s.order, { method: "POST", body: { payment_method: paymentMethod } });
/** QA-041: `reason` is a code from `orders/cancel-reasons`; `other` needs a `note` (≤255). */
export const cancelShipment = (market: string, number: string, shipment: string, reason?: string, note?: string) =>
  request(market, `orders/${encodeURIComponent(number)}/shipments/${encodeURIComponent(shipment)}/cancel`, s.order, { method: "POST", body: { reason, note: note || undefined } });

// Non-order payments (gift cards, Nawy One, ad packages): payer only.
export const getPaymentByReference = (market: string, reference: string) => get(market, `payments/${encodeURIComponent(reference)}`, s.paymentStatus);
export const verifyPaymentByReference = (market: string, reference: string) =>
  request(market, `payments/${encodeURIComponent(reference)}/verify`, s.paymentStatus, { method: "POST" });

// Returns
export const getReturns = (market: string, page = 1) => get(market, "returns", z.array(s.returnRequest), { page });
export const getReturn = (market: string, number: string) => get(market, `returns/${encodeURIComponent(number)}`, s.returnRequest);
export const createReturn = (market: string, form: FormData) => request(market, "returns", s.returnRequest, { method: "POST", body: form });
export const cancelReturn = (market: string, number: string) => request(market, `returns/${encodeURIComponent(number)}/cancel`, s.returnRequest, { method: "POST" });

// Wallet / Nawy Credits
export const getWallet = (market: string) => get(market, "wallet", s.wallet);
export const getWalletTransactions = (market: string, page = 1) => get(market, "wallet/transactions", z.array(s.walletEntry), { page });

// Security
export const getDevices = (market: string) => get(market, "me/devices", z.array(s.device));
export const revokeDevice = (market: string, id: number) => request(market, `me/devices/${id}`, ok, { method: "DELETE" });
export const getLoginHistory = (market: string) => get(market, "me/login-history", z.array(s.loginEvent));
