import { NextResponse, type NextRequest } from "next/server";
import { parseMarket } from "@/lib/markets";
import { TOKEN_COOKIE, secureCookies, isCrossSite } from "@/lib/session";
import { GUEST_BOOKINGS_COOKIE, bookingTokenCookie } from "@/features/travel/booking-cookies";

/**
 * Same-origin handler for travel booking requests (MASTER_PLAN D8: no
 * payment, guests allowed). The API gives a guest a token in the
 * `X-Booking-Token` response header when the request is created; this
 * handler moves it into an httpOnly cookie
 * (`nawy_booking_{number}`) and sends it back as `X-Booking-Token` on later
 * reads, submit, cancel and change requests, so page JS never holds it.
 * A readable list cookie keeps the numbers (no secrets) for "My bookings".
 *
 *   POST {market}/departures/{id}/bookings
 *   GET  {market}/bookings/{number}
 *   POST {market}/bookings/{number}/{submit|cancel|modifications}
 *   POST {market}/bookings/{number}/access  {token}  — remember a guest booking
 */

const API_ROOT = (process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1").replace(/\/$/, "");
const KEEP = 60 * 60 * 24 * 365;
const NUMBER = /^[A-Za-z0-9-]{3,40}$/;

type Ctx = { params: Promise<{ path: string[] }> };

function fail(status: number, code: string, message: string) {
  return NextResponse.json({ error: { code, message, fields: {} } }, { status });
}

function remember(response: NextResponse, request: NextRequest, countryCode: string, number: string, token: string) {
  const secure = secureCookies(request);
  response.cookies.set(bookingTokenCookie(number), token, { httpOnly: true, sameSite: "lax", secure, path: "/", maxAge: KEEP });
  const name = GUEST_BOOKINGS_COOKIE(countryCode);
  const list = (request.cookies.get(name)?.value ?? "").split(",").filter((n) => NUMBER.test(n) && n !== number);
  response.cookies.set(name, [number, ...list].slice(0, 20).join(","), { sameSite: "lax", secure, path: "/", maxAge: KEEP });
}

async function handle(request: NextRequest, { params }: Ctx): Promise<Response> {
  const { path } = await params;
  const market = parseMarket(path[0]);
  const rest = path.slice(1);
  if (!market) return fail(404, "not_found", "Unknown endpoint.");

  const method = request.method;
  if (method !== "GET") {
    if (isCrossSite(request)) return fail(403, "forbidden", "Cross-site request refused.");
  }

  const isCreate = method === "POST" && rest.length === 3 && rest[0] === "departures" && /^\d+$/.test(rest[1]) && rest[2] === "bookings";
  const number = rest[0] === "bookings" && NUMBER.test(rest[1] ?? "") ? rest[1] : null;
  const action = number ? (rest[2] ?? null) : null;
  const allowed =
    isCreate ||
    (number && rest.length === 2 && method === "GET") ||
    (number && rest.length === 3 && method === "POST" && ["submit", "cancel", "modifications", "access"].includes(action ?? ""));
  if (!allowed) return fail(404, "not_found", "Unknown endpoint.");

  const headers: Record<string, string> = { Accept: "application/json", "User-Agent": request.headers.get("user-agent") ?? "nawy-web" };
  const auth = request.cookies.get(TOKEN_COOKIE)?.value;
  if (auth) headers.Authorization = `Bearer ${auth}`;
  const forwardedFor = request.headers.get("x-forwarded-for");
  if (forwardedFor) headers["X-Forwarded-For"] = forwardedFor;

  let body: string | undefined;
  let claimedToken: string | null = null;
  if (action === "access") {
    // Validate the token by reading the booking with it.
    const json = (await request.json().catch(() => null)) as { token?: unknown } | null;
    claimedToken = typeof json?.token === "string" ? json.token.trim().slice(0, 120) : "";
    if (!claimedToken) return fail(404, "not_found", "Booking not found.");
    headers["X-Booking-Token"] = claimedToken;
  } else {
    const stored = number ? request.cookies.get(bookingTokenCookie(number))?.value : undefined;
    if (stored) headers["X-Booking-Token"] = stored;
    if (method === "POST") {
      body = await request.text();
      headers["Content-Type"] = "application/json";
    }
  }

  const upstreamPath = action === "access" ? `bookings/${number}` : rest.join("/");
  let upstream: Response;
  try {
    upstream = await fetch(`${API_ROOT}/${market.slug}/travel/${upstreamPath.split("/").map(encodeURIComponent).join("/")}`, {
      method: action === "access" ? "GET" : method,
      headers,
      body,
      cache: "no-store",
      redirect: "manual",
    });
  } catch {
    return fail(502, "api_unreachable", "The store is not reachable right now.");
  }

  const text = await upstream.text();
  let out = text;
  let issued: { number: string; token: string } | null = null;

  if (upstream.ok && isCreate) {
    // The guest token comes back in the `X-Booking-Token` response header (round 2);
    // `data.access_token` is still in the body: strip it so page JS never sees it.
    try {
      const json = JSON.parse(text) as { data?: { number?: string; access_token?: string } };
      const token = upstream.headers.get("x-booking-token") || json.data?.access_token || null;
      if (json.data?.number && token) issued = { number: json.data.number, token };
      if (json.data) delete json.data.access_token;
      out = JSON.stringify(json);
    } catch {
      // Not JSON: nothing to remember.
    }
  }

  const response = new NextResponse(out, { status: upstream.status });
  response.headers.set("Content-Type", upstream.headers.get("content-type") ?? "application/json");
  response.headers.set("Cache-Control", "no-store");
  if (issued) remember(response, request, market.country.code, issued.number, issued.token);
  if (upstream.ok && action === "access" && number && claimedToken) remember(response, request, market.country.code, number, claimedToken);
  return response;
}

export const GET = handle;
export const POST = handle;
