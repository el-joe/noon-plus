import { z } from "zod";
import { ApiError, get, request, type Envelope, type Query } from "./client";
import { money } from "./schemas";

/*
 * Travel API (backend Travel/Http/Controllers/Api/TravelController).
 * Booking requests take no payment (MASTER_PLAN D8): guests and customers
 * send their contact details; the agency confirms and is paid directly.
 *
 * Booking calls from the browser go through the same-origin handler
 * `/api/travel-booking/*`, which keeps each guest booking's access token in
 * an httpOnly cookie and sends it back as `X-Booking-Token`.
 */

// TravelController@card
export const travelPackageCard = z.object({
  slug: z.string(),
  title: z.string().nullable(),
  agency: z.string().nullable(),
  trip_type: z.string().nullable(),
  destination: z.string().nullable(),
  nights: z.number().nullable(),
  /** Lowest price per person of upcoming departures (round 2: `{amount, currency}`). */
  from_price: money.nullable(),
  cover_url: z.string().nullable().optional(),
  image: z.object({ url: z.string(), alt: z.string().nullable().optional() }).nullable().optional(),
  status: z.string(),
  /**
   * D14: a paid promotion. Promoted trips come first (server order, never
   * re-sorted) with the server-localized `label` ("Sponsored" / "مُموَّل").
   */
  sponsored: z.boolean().catch(false).default(false),
  label: z.string().nullable().optional().catch(null),
});
export type TravelPackageCard = z.infer<typeof travelPackageCard>;

const textList = z.array(z.unknown()).or(z.record(z.string(), z.unknown())).nullable().default([]);

export const travelDeparture = z.object({
  id: z.number(),
  departs_on: z.string(),
  returns_on: z.string(),
  seats_left: z.number(),
  status: z.string(),
  rooms: z.array(z.object({ type: z.string(), price_per_person: money })),
  /** QC-060: price per child (the departure's `child` room), or null when children cannot be booked. */
  child_price_per_person: money.nullable().optional().catch(null),
});
export type TravelDeparture = z.infer<typeof travelDeparture>;

// TravelController@show
export const travelPackage = travelPackageCard.extend({
  description: z.string().nullable(),
  itinerary: textList,
  inclusions: textList,
  exclusions: textList,
  cancellation_policy: textList,
  departures: z.array(travelDeparture),
  /** QC-060: 1+2 gallery and the stats line. */
  gallery: z.array(z.object({ url: z.string(), alt: z.string().nullable().optional() })).catch([]).default([]),
  stats: z
    .object({
      rating: z.object({ average: z.union([z.number(), z.string()]).nullable(), count: z.number() }).or(z.number()).nullable().optional().catch(null),
      travellers: z.number().default(0),
      bookings: z.number().default(0),
      agency_trips: z.number().default(0),
    })
    .nullable()
    .optional()
    .catch(null),
  /** D14: up to 4 similar trips, promoted first (server order). */
  related: z.array(travelPackageCard).catch([]).default([]),
});
export type TravelPackage = z.infer<typeof travelPackage>;

// TravelController@present
export const travelBooking = z.object({
  number: z.string(),
  status: z.string(),
  agency: z.string().nullable(),
  package: z.object({ slug: z.string(), title: z.string().nullable() }),
  departs_on: z.string(),
  returns_on: z.string(),
  /** QA-037: room line money is `{amount, currency}`. */
  rooms: z.array(z.object({ room_type: z.string(), travellers: z.number(), unit_price: money.nullable().optional().catch(null), line_total: money.nullable().optional().catch(null) })).nullable().default([]),
  seats: z.number(),
  /** QC-060: the adults / children split (children travel on the `child` room line). */
  adults: z.number().optional(),
  children: z.number().optional(),
  quoted_total: money,
  payment: z.string(),
  contact: z.object({ name: z.string().nullable(), email: z.string().nullable(), phone: z.string().nullable() }),
  travellers: z.array(z.string().nullable()).nullable(),
  hold_expires_at: z.string().nullable(),
  confirmation_number: z.string().nullable(),
  decision_reason: z.string().nullable(),
});
export type TravelBooking = z.infer<typeof travelBooking>;

export const BOOKING_STATUSES = ["held", "requested", "confirmed", "completed", "rejected", "cancelled", "hold_expired"] as const;
export const MODIFICATION_TYPES = ["change_departure", "name_correction", "special_request", "other"] as const;

export type TravelFilters = { destination?: string; trip_type?: string; month?: string; page?: number };

// Public reads (server components or the BFF proxy).
export const getTravelPackages = (market: string, filters: TravelFilters = {}) => get(market, "travel/packages", z.array(travelPackageCard), filters as Query);
export const getTravelPackage = (market: string, slug: string) => get(market, `travel/packages/${encodeURIComponent(slug)}`, travelPackage);
export const sendTravelInquiry = (market: string, slug: string, body: { name: string; email: string; phone: string; message: string }) =>
  request(market, `travel/packages/${encodeURIComponent(slug)}/inquiries`, z.object({ id: z.number(), status: z.string() }), { method: "POST", body });
/** Signed-in customer's own bookings (via the BFF proxy). */
export const getMyTravelBookings = (market: string) => get(market, "travel/bookings", z.array(travelBooking));

// Booking calls (browser only) through /api/travel-booking.
async function bookingCall<S extends z.ZodType>(market: string, path: string, schema: S, init: { method?: "GET" | "POST"; body?: unknown } = {}): Promise<Envelope<z.infer<S>>> {
  let response: Response;
  try {
    response = await fetch(`/api/travel-booking/${market}/${path}`, {
      method: init.method ?? "GET",
      cache: "no-store",
      headers: { Accept: "application/json", ...(init.body !== undefined ? { "Content-Type": "application/json" } : {}) },
      body: init.body === undefined ? undefined : JSON.stringify(init.body),
    });
  } catch (cause) {
    throw new ApiError(0, "network_error", cause instanceof Error ? cause.message : "Network error");
  }
  const json = (await response.json().catch(() => null)) as { data?: unknown; meta?: Record<string, unknown>; error?: { code?: string; message?: string; fields?: unknown } } | null;
  if (!response.ok) {
    const error = json?.error;
    const fields = error?.fields && !Array.isArray(error.fields) ? (error.fields as Record<string, string[]>) : {};
    throw new ApiError(response.status, error?.code ?? "http_error", error?.message ?? response.statusText, fields);
  }
  const parsed = schema.safeParse(json?.data);
  if (!parsed.success) throw new ApiError(response.status, "invalid_response", "The server returned an unexpected response.");
  return { data: parsed.data as z.infer<S>, meta: json?.meta ?? {} };
}

export type BookingRequestInput = {
  name: string;
  email: string;
  phone: string;
  rooms: { room_type: string; travellers: number }[];
  /** QC-060: children on the departure's child price (only when it has one). */
  children?: number;
  traveller_names?: string[];
  message?: string;
  ref?: string;
};

export const requestTravelBooking = (market: string, departureId: number, body: BookingRequestInput) =>
  bookingCall(market, `departures/${departureId}/bookings`, travelBooking, { method: "POST", body: { ...body, submit: true } });
export const getTravelBooking = (market: string, number: string) => bookingCall(market, `bookings/${encodeURIComponent(number)}`, travelBooking);
export const submitTravelBooking = (market: string, number: string) => bookingCall(market, `bookings/${encodeURIComponent(number)}/submit`, travelBooking, { method: "POST" });
export const cancelTravelBooking = (market: string, number: string, reason: string) =>
  bookingCall(market, `bookings/${encodeURIComponent(number)}/cancel`, travelBooking, { method: "POST", body: { reason } });
export const requestTravelModification = (market: string, number: string, type: string, details: Record<string, string>) =>
  bookingCall(market, `bookings/${encodeURIComponent(number)}/modifications`, z.object({ id: z.number(), status: z.string() }), { method: "POST", body: { type, details } });
/** "Resend my booking link": mails the status links of that email's bookings (always 202, nothing revealed). */
export const resendBookingLinks = (market: string, email: string) =>
  request(market, "travel/bookings/resend-link", z.object({ sent: z.boolean() }).loose(), { method: "POST", body: { email } });
/** Remember a guest booking on this device from its number + access token (email link / "find my booking"). */
export const claimTravelBooking = (market: string, number: string, token: string) =>
  bookingCall(market, `bookings/${encodeURIComponent(number)}/access`, travelBooking, { method: "POST", body: { token } });

/** The room type that prices a child (backend TravelController::CHILD_ROOM); it is not an adult room choice. */
export const CHILD_ROOM = "child";
