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
  sort?: "recommended" | "price_asc" | "price_desc" | "newest" | "rating";
  page?: number;
  per_page?: number;
  /** `attr[key]=v1,v2` variant option filters. */
  attr?: Record<string, string>;
};
function filterQuery(filters: ProductFilters): Query {
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
export const getCategory = (market: string, slug: string) => get(market, `categories/${encodeURIComponent(slug)}`, s.categoryDetail);
export const getSizeGuide = (market: string, slug: string, query: { unit?: string; height?: string; height_unit?: string } = {}) =>
  get(market, `products/${encodeURIComponent(slug)}/size-guide`, s.sizeGuide, query);

// Reviews & Q&A
export type ReviewQuery = { sort?: "recent" | "helpful" | "rating_desc" | "rating_asc"; rating?: number; with_photos?: number; page?: number; per_page?: number };
export const getProductReviews = (market: string, slug: string, query: ReviewQuery = {}) => get(market, `products/${encodeURIComponent(slug)}/reviews`, s.reviewPage, query);
export const createReview = (market: string, form: FormData) => request(market, "reviews", s.review, { method: "POST", body: form });
export const voteHelpful = (market: string, id: number, helpful: boolean) =>
  request(market, `reviews/${id}/helpful`, z.object({ id: z.number(), helpful_count: z.number(), voted_helpful: z.boolean() }), { method: helpful ? "POST" : "DELETE" });
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
export const getPage = (market: string, slug: string) => get(market, `pages/${encodeURIComponent(slug)}`, s.cmsPage);
export const getFooter = (market: string) => get(market, "footer", s.footer);
export const getFlashSale = (market: string, id: number) => get(market, `flash-sales/${id}`, s.flashSale);
export const getAdPlacements = (market: string, placement: string) => get(market, `ads/placements/${encodeURIComponent(placement)}`, z.array(s.adPlacement));
export const recordAdClick = (market: string, token: string) => request(market, "ads/click", ok, { method: "POST", body: { token } });
export const getContract = (market: string, version: number) => get(market, `contracts/${version}`, s.contractText);
export const acceptContract = (market: string, version: number, fullName: string) =>
  request(market, `contracts/${version}/accept`, ok, { method: "POST", body: { full_name: fullName, accept: true } });

// Auth (email code, ADR 5)
export const sendEmailCode = (market: string, email: string) => request(market, "auth/email-code/send", ok, { method: "POST", body: { email } });
export const verifyEmailCode = (market: string, email: string, code: string, deviceName: string) =>
  request(market, "auth/email-code/verify", s.signIn, { method: "POST", body: { email, code, device_name: deviceName } });
export const getMe = (market: string) => get(market, "me", s.me);
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
export const placeOrder = (market: string, body: { payment_method: string; address?: AddressInput; address_id?: number; referral_code?: string }) =>
  request(market, "orders", s.order, { method: "POST", body });
export const getOrders = (market: string, page = 1) => get(market, "orders", z.array(s.order), { page });
export const getOrder = (market: string, number: string) => get(market, `orders/${encodeURIComponent(number)}`, s.order);
export const verifyPayment = (market: string, number: string) => request(market, `orders/${encodeURIComponent(number)}/payments/verify`, s.order, { method: "POST" });
export const retryPayment = (market: string, number: string, paymentMethod: string) =>
  request(market, `orders/${encodeURIComponent(number)}/payments`, s.order, { method: "POST", body: { payment_method: paymentMethod } });
export const cancelShipment = (market: string, number: string, shipment: string, reason?: string) =>
  request(market, `orders/${encodeURIComponent(number)}/shipments/${encodeURIComponent(shipment)}/cancel`, s.order, { method: "POST", body: { reason } });

// Non-order payments (gift cards, Nawy One, ad packages): payer only.
export const getPaymentByReference = (market: string, reference: string) => get(market, `payments/${encodeURIComponent(reference)}`, s.paymentStatus);
export const verifyPaymentByReference = (market: string, reference: string) =>
  request(market, `payments/${encodeURIComponent(reference)}/verify`, s.paymentStatus, { method: "POST" });

// Returns
export const getReturns = (market: string) => get(market, "returns", z.array(s.returnRequest));
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
