import { z } from "zod";

/*
 * Zod schemas for the Laravel API (`backend/routes/api.php`). Every response
 * is `{data, meta}`; failures are `{error: {code, message, fields}}`. Shapes
 * mirror the controllers/resources named in each comment. Unknown keys are
 * stripped; optional/nullable mirrors what the backend may omit.
 */

export const money = z.object({ amount: z.string(), currency: z.string() });
export type Money = z.infer<typeof money>;

const nullableMoney = money.nullable();

// CountryResource
export const country = z.object({
  code: z.string(),
  iso2: z.string(),
  name: z.string(),
  currency: z.object({ code: z.string(), decimals: z.number() }),
  timezone: z.string(),
  tax: z.object({ name: z.string().nullable(), rate: z.string() }),
  phone_code: z.string().nullable(),
  markets: z.array(z.string()),
  default_locale: z.string(),
});
export type CountryInfo = z.infer<typeof country>;

// ProductMedia::toPublicArray
export const productImage = z.object({
  id: z.number(),
  url: z.string(),
  alt: z.string().nullable().optional(),
  alt_en: z.string().nullable().optional(),
  alt_ar: z.string().nullable().optional(),
  variant_sku: z.string().nullable().optional(),
  width: z.number().nullable().optional(),
  height: z.number().nullable().optional(),
  sort_order: z.number().optional(),
});
export type ProductImageData = z.infer<typeof productImage>;

export const rating = z.object({ average: z.union([z.string(), z.number()]).nullable(), count: z.number() });
export type Rating = z.infer<typeof rating>;

// CategoryResource (roots with one level of children); `id` since round 2.
export type Category = { id?: number; slug: string; name: string; image: string | null; children?: Category[] };
export const category: z.ZodType<Category> = z.lazy(() =>
  z.object({
    id: z.number().optional(),
    slug: z.string(),
    name: z.string(),
    image: z.string().nullable(),
    children: z.array(category).optional(),
  }),
);

// ProductCardResource + SponsoredRanker extras (CatalogController@products)
export const productCard = z.object({
  listing_id: z.number().nullable().optional(),
  slug: z.string(),
  title: z.string(),
  brand: z.string().nullable().optional(),
  category: z.string().nullable().optional(),
  price: nullableMoney,
  fulfilment_mode: z.string().nullable().optional(),
  image: productImage.nullable().optional(),
  rating: rating.nullable().optional(),
  sponsored: z.boolean().optional().default(false),
  label: z.string().nullable().optional(),
  ad_token: z.string().nullable().optional(),
  /** Merchandising badges (wave 5a, QC-057): bestseller · new · top_rated. */
  badges: z.array(z.object({ key: z.string(), label: z.string() })).catch([]).optional(),
});
export type ProductCard = z.infer<typeof productCard>;

// WishlistController@index
export const wishlistCard = productCard.extend({ in_stock: z.boolean().optional(), saved_at: z.string().nullable().optional() });
export type WishlistCard = z.infer<typeof wishlistCard>;

// ProductSearch::facets
export const facets = z.object({
  brands: z.array(z.object({ slug: z.string(), name: z.string(), count: z.number() })),
  sellers: z.array(z.object({ slug: z.string().nullable(), name: z.string().nullable(), rating: z.union([z.string(), z.number()]).nullable().optional(), count: z.number() })),
  price: z.object({ min: nullableMoney, max: nullableMoney }),
  ratings: z.array(z.object({ min: z.number(), count: z.number() })),
  attributes: z.array(z.object({ key: z.string(), values: z.array(z.object({ value: z.string(), count: z.number() })) })),
  /** QC-027: Fulfilled by (fbm / fbn), Condition (new / refurbished) and the Deals count. */
  fulfilment: z.array(z.object({ value: z.string(), count: z.number() })).default([]),
  conditions: z.array(z.object({ value: z.string(), count: z.number() })).default([]),
  deals: z.number().default(0),
});
export type Facets = z.infer<typeof facets>;

export const brand = z.object({ slug: z.string(), name: z.string(), logo: z.string().nullable(), product_count: z.number() });
export type Brand = z.infer<typeof brand>;

// CatalogController@brand (round 2): `page` is a published page-builder page to render instead.
export const brandDetail = brand.extend({
  categories: z.array(z.object({ id: z.number(), slug: z.string(), name: z.string(), count: z.number() })).default([]),
  top_products: z.array(productCard).default([]),
  page: z.string().nullable(),
  products_url: z.string().nullable().optional(),
});
export type BrandDetail = z.infer<typeof brandDetail>;

// CatalogController@categoryTree
export type CategoryNode = { id?: number; slug: string; name: string; image: string | null; product_count: number; children: CategoryNode[] };
export const categoryNode: z.ZodType<CategoryNode> = z.lazy(() =>
  z.object({ id: z.number().optional(), slug: z.string(), name: z.string(), image: z.string().nullable(), product_count: z.number(), children: z.array(categoryNode) }),
);

const crumb = z.object({ slug: z.string(), name: z.string() });

// CatalogController@category
export const categoryDetail = z.object({
  slug: z.string(),
  name: z.string(),
  image: z.string().nullable(),
  product_count: z.number(),
  breadcrumbs: z.array(crumb),
  children: z.array(z.object({ slug: z.string(), name: z.string(), image: z.string().nullable(), product_count: z.number() })),
});
export type CategoryDetail = z.infer<typeof categoryDetail>;

export const pageMeta = z.object({
  total: z.number().optional(),
  page: z.number().optional(),
  last_page: z.number().optional(),
  current_page: z.number().optional(),
});

// DeliveryEstimate::toArray
export const deliveryEstimate = z.object({
  from: z.string(),
  by: z.string(),
  order_before: z.string().nullable().optional(),
});
export type DeliveryEstimate = z.infer<typeof deliveryEstimate>;

// OfferResource
export const offer = z.object({
  listing_id: z.number(),
  sku: z.string().nullable(),
  options: z.record(z.string(), z.unknown()).nullable().or(z.array(z.unknown())).optional(),
  seller: z.object({ slug: z.string().nullable(), name: z.string().nullable(), rating: z.union([z.string(), z.number()]).nullable(), rating_count: z.number().optional() }),
  price: nullableMoney,
  was_price: nullableMoney,
  flash_sale: z
    .object({ event: z.string(), ends_at: z.string(), remaining: z.number().nullable(), max_per_customer: z.number().nullable() })
    .nullable(),
  delivery: deliveryEstimate.nullable(),
  fulfilment: z.string().nullable(),
  fulfilled_by_nawy: z.boolean().optional(),
  condition: z.string().nullable(),
  in_stock: z.number(),
});
export type Offer = z.infer<typeof offer>;

export const warrantyPlan = z.object({ plan_id: z.number(), name: z.string(), months: z.number(), price: nullableMoney });
export type WarrantyPlan = z.infer<typeof warrantyPlan>;

const measurementField = z.object({
  key: z.string(),
  label: z.string().nullable(),
  unit: z.string().nullable(),
  min: z.union([z.number(), z.string()]).nullable(),
  max: z.union([z.number(), z.string()]).nullable(),
  required: z.boolean(),
});

// CatalogController@product
export const productDetail = z.object({
  slug: z.string(),
  title: z.string(),
  brand: z.string().nullable(),
  brand_slug: z.string().nullable().optional(),
  category: category.nullable(),
  breadcrumbs: z.array(crumb).default([]),
  description: z.string().nullable().optional(),
  images: z.array(productImage).default([]),
  rating: rating.nullable().optional(),
  highlights: z.array(z.unknown()).default([]),
  specifications: z.union([z.record(z.string(), z.unknown()), z.array(z.unknown())]).default([]),
  fulfilment_mode: z.string().nullable(),
  lead_days: z.number().nullable(),
  made_to_order: z.boolean(),
  measurement_fields: z.array(measurementField).catch([]).default([]),
  has_size_guide: z.boolean(),
  buy_box: offer.nullable(),
  other_offers: z.array(offer),
  from_price: nullableMoney,
  warranty_plans: z.array(warrantyPlan),
  /** Wave 5a (QC-057): model number and merchandising badges. */
  model_number: z.string().nullable().optional(),
  badges: z.array(z.object({ key: z.string(), label: z.string() })).catch([]).optional(),
});
export type ProductDetail = z.infer<typeof productDetail>;
export type MeasurementField = z.infer<typeof measurementField>;

// CatalogController@sizeGuide
export const sizeGuide = z.object({
  id: z.number(),
  name: z.string().nullable(),
  type: z.string(),
  unit: z.string(),
  /** Plain row keys. */
  columns: z.array(z.unknown()),
  /** Labels and units per column (round 2); `is_label` marks the size-name column. */
  column_defs: z.array(z.object({ key: z.string(), label: z.string().nullable(), unit: z.string().nullable().optional(), is_label: z.boolean().default(false) })).default([]),
  rows: z.array(z.record(z.string(), z.unknown())),
  notes: z.string().nullable(),
  recommendation: z.object({ height_cm: z.number(), length_in: z.number(), sleeve_in: z.number().nullable() }).optional(),
});
export type SizeGuide = z.infer<typeof sizeGuide>;

// ReviewResource
export const review = z.object({
  id: z.number(),
  rating: z.number(),
  title: z.string().nullable(),
  body: z.string().nullable(),
  author: z.string().nullable(),
  verified_purchase: z.boolean(),
  photos: z.array(z.object({ id: z.number(), url: z.string() })),
  helpful_count: z.number(),
  voted_helpful: z.boolean(),
  seller: z.string().nullable().optional(),
  /** QC-012: the store's reply, once Nawy published it. */
  seller_reply: z.object({ body: z.string(), created_at: z.string().nullable() }).nullable().optional(),
  created_at: z.string(),
  status: z.string().optional(),
  rejection_reason: z.string().nullable().optional(),
  product: z.object({ slug: z.string().nullable(), title: z.string().nullable() }).nullable().optional(),
  order_item_id: z.number().optional(),
});
export type Review = z.infer<typeof review>;

export const reviewSummary = z.object({
  average: z.union([z.string(), z.number()]).nullable(),
  count: z.number(),
  histogram: z.union([z.record(z.string(), z.number()), z.array(z.number())]),
});
export type ReviewSummary = z.infer<typeof reviewSummary>;

export const reviewPage = z.object({ summary: reviewSummary, reviews: z.array(review) });
export type ReviewPage = z.infer<typeof reviewPage>;

export const reviewableItem = z.object({
  order_item_id: z.number(),
  order_number: z.string(),
  shipment_number: z.string().nullable().optional(),
  title: z.string().nullable(),
  product: z.object({ slug: z.string(), title: z.string() }).nullable(),
  image: productImage.nullable(),
});
export type ReviewableItem = z.infer<typeof reviewableItem>;

// QuestionResource
export const question = z.object({
  id: z.number(),
  body: z.string(),
  author: z.string().nullable(),
  status: z.string(),
  created_at: z.string(),
  answers: z.array(z.object({ id: z.number(), body: z.string(), by: z.string(), author: z.string().nullable(), created_at: z.string() })),
});
export type Question = z.infer<typeof question>;

// AddressResource
export const address = z.object({
  id: z.number(),
  label: z.string().nullable(),
  name: z.string().nullable(),
  phone: z.string().nullable(),
  city: z.string().nullable(),
  area: z.string().nullable(),
  street: z.string().nullable(),
  building: z.string().nullable(),
  apartment: z.string().nullable(),
  landmark: z.string().nullable(),
  zone: z.object({ id: z.number(), name: z.string() }).nullable(),
  location: z.object({ latitude: z.number(), longitude: z.number() }).nullable(),
  is_default: z.boolean(),
  updated_at: z.string().nullable().optional(),
});
export type Address = z.infer<typeof address>;

// AddressController@deliveryAreas
export const deliveryCity = z.object({
  city: z.string(),
  zone: z.object({ id: z.number(), name: z.string() }).nullable(),
  areas: z.array(z.object({ name: z.string(), zone: z.object({ id: z.number(), name: z.string() }) })),
});
export type DeliveryCity = z.infer<typeof deliveryCity>;

// CartPresenter
const cartLine = z.object({
  listing_id: z.number(),
  sku: z.string().nullable(),
  title: z.string(),
  product_slug: z.string().nullable().optional(),
  image: productImage.nullable().optional(),
  measurements: z.record(z.string(), z.unknown()).nullable().or(z.array(z.unknown())).optional(),
  measurement_note: z.string().nullable().optional(),
  /** Made-to-order fields (round 2); checkout refuses a line with `measurements_missing` (409 `measurements_required`). */
  measurement_fields: z.array(measurementField).catch([]).default([]),
  measurements_required: z.boolean().default(false),
  measurements_missing: z.boolean().default(false),
  quantity: z.number(),
  unit_price: money,
  regular_price: money,
  line_total: money,
  fulfilment_mode: z.string().nullable().optional(),
  flash_sale: z.object({ ends_at: z.string() }).nullable(),
  warranty: z
    .object({ plan_id: z.number(), name: z.string(), months: z.number(), unit_price: money, total: money })
    .nullable(),
  warranty_options: z.array(z.object({ plan_id: z.number(), name: z.string(), months: z.number(), price: money })),
});
export type CartLine = z.infer<typeof cartLine>;

const shippingOption = z.object({ method: z.string(), fee: money, delivery: deliveryEstimate });

const cartShipment = z.object({
  seller: z.string().nullable(),
  items: z.array(cartLine),
  subtotal: money,
  discount: money,
  shipping: money,
  shipping_cost: money,
  shipping_subsidy: money,
  free_shipping_threshold_applied: z.boolean(),
  method: z.string(),
  delivery: deliveryEstimate,
  shipping_options: z.array(shippingOption),
  warranty: money,
  total: money,
});
export type CartShipment = z.infer<typeof cartShipment>;

export const cart = z.object({
  destination: z.object({ city: z.string().nullable(), area: z.string().nullable() }),
  shipments: z.array(cartShipment),
  coupons: z.array(z.object({ code: z.string(), scope: z.string().nullable(), discount: money, free_shipping: z.boolean() })),
  totals: z.object({
    subtotal: money,
    discount: money,
    shipping: money,
    shipping_subsidy: money,
    cod_fee: money,
    warranty: money,
    total: money,
    tax_included: money,
    tax_rate: z.union([z.string(), z.number()]).nullable(),
  }),
  warnings: z.array(z.unknown()),
  hold_minutes: z.number(),
  /** G-A5: "spend X more for free delivery" (null when the market has no threshold). */
  free_delivery: z
    .object({ threshold: money, remaining: money, progress_percent: z.number(), qualified: z.boolean() })
    .nullable()
    .optional()
    .catch(null),
});
export type Cart = z.infer<typeof cart>;
export type FreeDelivery = NonNullable<Cart["free_delivery"]>;

// PaymentMethodController@index
export const paymentMethod = z.object({
  code: z.string(),
  type: z.string(),
  name: z.string(),
  description: z.string().nullable(),
  recommended: z.boolean(),
  fee: nullableMoney,
});
export type PaymentMethod = z.infer<typeof paymentMethod>;

// PaymentResource
export const payment = z.object({
  reference: z.string(),
  method: z.string(),
  status: z.string(),
  amount: nullableMoney,
  refunded: nullableMoney,
  expires_at: z.string().nullable(),
  failure: z.object({ code: z.string(), message: z.string().nullable() }).nullable(),
  next_action: z.object({ type: z.string(), url: z.string().optional() }),
});

// PaymentController@show / @verifyPayment (non-order payments, G-W31)
export const PAYMENT_PURPOSES = ["order", "gift_card", "membership_invoice", "classified_package", "travel_promotion"] as const;
export type PaymentPurpose = (typeof PAYMENT_PURPOSES)[number];
export const paymentStatus = z.object({
  payment,
  purpose: z.string(),
  subject: z
    .looseObject({
      number: z.string().nullable().optional(),
      status: z.string().nullable().optional(),
      masked_code: z.string().nullable().optional(),
      amount: nullableMoney.optional(),
      recipient_email: z.string().nullable().optional(),
      membership: z.object({ status: z.string().nullable(), plan: z.string().nullable() }).nullable().optional(),
      applied_until: z.string().nullable().optional(),
      payment_status: z.string().nullable().optional(),
      /** D14 `travel_promotion`: the paid promotion window. */
      starts_at: z.string().nullable().optional(),
      ends_at: z.string().nullable().optional(),
    })
    .nullable(),
});
export type PaymentStatus = z.infer<typeof paymentStatus>;

// OrderResource
export const order = z.object({
  number: z.string(),
  status: z.string(),
  payment_method: z.string().nullable(),
  payment_status: z.string().nullable(),
  payment_due_at: z.string().nullable(),
  payment: payment.nullable(),
  placed_at: z.string(),
  totals: z.object({
    subtotal: nullableMoney,
    discount: nullableMoney,
    shipping: nullableMoney,
    cod_fee: nullableMoney,
    warranty: nullableMoney,
    total: nullableMoney,
    tax_included: nullableMoney,
  }),
  shipments: z.array(
    z.object({
      number: z.string(),
      status: z.string(),
      seller: z.string().nullable(),
      /** Owner decision 2026-11-19: "nawy" when the parcel ships from the Nawy (FBN) warehouse. */
      fulfilled_by: z.string().nullable().optional(),
      total: nullableMoney,
      shipping: nullableMoney,
      method: z.string().nullable(),
      delivery: z.object({ from: z.string().nullable(), by: z.string().nullable() }),
      delivery_code: z.string().nullable(),
      /** Wave 5a (QC-026): when it was delivered, the last day to start a return, and the buttons to show. */
      delivered_at: z.string().nullable().optional(),
      return_until: z.string().nullable().optional(),
      actions: z
        .object({
          track: z.boolean(),
          invoice: z.boolean(),
          cancel: z.boolean(),
          return: z.boolean(),
          review: z.boolean(),
          buy_again: z.boolean(),
          dispute: z.boolean(),
        })
        .partial()
        .optional(),
      items: z.array(
        z.object({
          id: z.number(),
          listing_id: z.number().nullable().optional(),
          sku: z.string().nullable(),
          title: z.string(),
          product_slug: z.string().nullable().optional(),
          image: productImage.nullable().optional(),
          quantity: z.number(),
          unit_price: nullableMoney,
          line_total: nullableMoney,
          warranty_total: nullableMoney,
        }),
      ),
    }),
  ),
  shipping_address: z
    .object({
      name: z.string().nullable().optional(),
      phone: z.string().nullable().optional(),
      city: z.string().nullable().optional(),
      area: z.string().nullable().optional(),
      street: z.string().nullable().optional(),
      building: z.string().nullable().optional(),
    })
    .nullable(),
  /** Wave 5a: the printable tax invoice (`orders/{n}/invoice`) can be opened. */
  invoice_available: z.boolean().optional(),
});
export type Order = z.infer<typeof order>;

// ReturnResource
export const returnRequest = z.object({
  number: z.string(),
  shipment: z.string().nullable(),
  status: z.string(),
  reason: z.string(),
  comment: z.string().nullable(),
  method: z.string(),
  refund_destination: z.string(),
  rejection_reason: z.string().nullable(),
  photos: z.array(z.string()),
  items: z.array(
    z.object({
      order_item_id: z.number(),
      title: z.string().nullable(),
      quantity: z.number(),
      paid: nullableMoney,
      outcome: z.string().nullable(),
      refund: nullableMoney,
    }),
  ),
  refund: z.object({ number: z.string(), status: z.string(), destination: z.string(), amount: nullableMoney }).nullable(),
  created_at: z.string(),
  timeline: z.array(z.object({ status: z.string(), at: z.string() })),
});
export type ReturnRequest = z.infer<typeof returnRequest>;

// WalletController
export const wallet = z.object({
  currency: z.string(),
  available: nullableMoney,
  spendable: nullableMoney,
  promotional: nullableMoney,
  frozen: nullableMoney,
  promotional_expiring: z.array(z.object({ amount: nullableMoney, expires_at: z.string(), source: z.string().nullable() })),
});
export type Wallet = z.infer<typeof wallet>;

export const walletEntry = z.object({
  id: z.number(),
  type: z.string().nullable(),
  bucket: z.string().nullable(),
  direction: z.enum(["in", "out"]),
  amount: nullableMoney,
  balance_after: nullableMoney,
  description: z.string().nullable(),
  at: z.string(),
});
export type WalletEntry = z.infer<typeof walletEntry>;

// SecurityController
export const device = z.object({
  id: z.number(),
  name: z.string(),
  last_used_at: z.string().nullable(),
  created_at: z.string().nullable(),
  is_current: z.boolean(),
});
export type Device = z.infer<typeof device>;

export const loginEvent = z.object({
  channel: z.string().nullable(),
  /** D9: email_code | password | google | apple. */
  method: z.string().nullable().optional(),
  outcome: z.string().nullable(),
  ip_address: z.string().nullable(),
  user_agent: z.string().nullable(),
  created_at: z.string().nullable(),
});
export type LoginEvent = z.infer<typeof loginEvent>;

// AccountController@show (`GET me`, round 2); sign-in returns the short form (id, name, email).
export const me = z.object({
  id: z.number(),
  name: z.string().nullable(),
  email: z.string(),
  email_verified: z.boolean().optional(),
  phone: z.string().nullable().optional(),
  locale: z.string().nullable().optional(),
  avatar_url: z.string().nullable().optional(),
  marketing: z.object({ email: z.boolean(), sms: z.boolean(), push: z.boolean(), whatsapp: z.boolean() }).partial().optional(),
  created_at: z.string().nullable().optional(),
  /** D9: the customer has chosen a password (sign-in by password possible). */
  has_password: z.boolean().optional(),
  /** An old Nawy phone-only account (placeholder email): it must add and verify an email before anything else. */
  requires_email: z.boolean().optional(),
  /** D9: linked providers, e.g. ["google"]. */
  social_accounts: z.array(z.string()).optional(),
});
export type Me = z.infer<typeof me>;

// Every sign-in answer (email code, password, reset, social exchange); the token is stripped by the BFF proxy.
export const signIn = z.object({ user: me });
// PasswordAuthController@reset: `user` only when signed in (device_name sent).
export const passwordReset = z.object({ reset: z.boolean(), user: me.optional() });

// Footer::get
export const footer = z.object({
  columns: z.array(z.object({ title: z.string(), links: z.array(z.object({ label: z.string(), url: z.string() })) })),
  social: z.union([z.record(z.string(), z.string()), z.array(z.unknown())]),
  copyright: z.string().nullable(),
});
export type Footer = z.infer<typeof footer>;

// ContentApiController@page (+ BlockResolver)
export const block = z.object({
  id: z.union([z.string(), z.number()]).nullable().optional(),
  type: z.string(),
  kind: z.string().optional(),
  settings: z
    .record(z.string(), z.unknown())
    .or(z.array(z.unknown()).transform((): Record<string, unknown> => ({})))
    .default({}),
  data: z.record(z.string(), z.unknown()).nullable().optional(),
});
export type Block = z.infer<typeof block>;

export const cmsPage = z.object({
  slug: z.string(),
  template: z.string(),
  title: z.string().nullable(),
  seo: z.object({ title: z.string().nullable(), description: z.string().nullable() }).optional(),
  published_at: z.string().nullable().optional(),
  zones: z
    .record(z.string(), z.array(block))
    .or(z.array(z.unknown()).transform((): Record<string, Block[]> => ({}))),
});
export type CmsPage = z.infer<typeof cmsPage>;

// FlashSaleEventResource
export const flashSale = z.object({
  id: z.number(),
  /** `"{name}-{id}"` (round 2); `flash-sales/{slug}` reads it. */
  slug: z.string().nullable().optional(),
  name: z.string(),
  state: z.string(),
  starts_at: z.string(),
  ends_at: z.string(),
  max_per_customer: z.number().nullable(),
  deals: z.array(
    z.object({
      listing_id: z.number(),
      /** Card without `listing_id` / `price` (round 2): slug, title, brand, image, rating. */
      product: z.object({ slug: z.string(), title: z.string(), brand: z.string().nullable().optional(), image: productImage.nullable().optional(), rating: rating.nullable().optional() }),
      price: nullableMoney,
      was_price: nullableMoney,
      sold_out: z.boolean(),
      claimed_percent: z.number(),
    }),
  ),
});
export type FlashSale = z.infer<typeof flashSale>;

// AdController@placements
export const adPlacement = z.object({
  slot: z.string(),
  image: z.string().nullable(),
  /** Video creatives (wave 3): `image` is null and `video` is the file URL. */
  video: z.string().nullable().optional(),
  creative_type: z.string().nullable().optional(),
  headline: z.string().nullable(),
  link_url: z.string().nullable(),
  sponsored: z.boolean(),
  label: z.string().nullable(),
});
export type AdPlacement = z.infer<typeof adPlacement>;

// Checkout 409 `customer_contract_required`
export const requiredContract = z.object({
  version_id: z.number(),
  kind: z.string(),
  title: z.string(),
  version: z.union([z.number(), z.string()]),
  url: z.string().optional(),
});
export type RequiredContract = z.infer<typeof requiredContract>;

export const contractText = z.object({
  id: z.number(),
  kind: z.string(),
  title: z.string(),
  version: z.union([z.number(), z.string()]),
  mode: z.string().nullable(),
  text: z.string().nullable(),
});
