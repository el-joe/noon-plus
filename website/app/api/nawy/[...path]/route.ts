import { NextResponse, type NextRequest } from "next/server";
import { parseMarket } from "@/lib/markets";
import { AUTH_HINT_COOKIE, TOKEN_COOKIE, VISITOR_COOKIE, cartCookie, cartHintCookie, secureCookies, isCrossSite } from "@/lib/session";

/**
 * Backend-for-frontend proxy: the browser calls `/api/nawy/{market}/…` on the
 * same origin and this handler forwards to `${NEXT_PUBLIC_API_URL}/{market}/…`.
 *
 * - Adds `Authorization: Bearer` from the httpOnly `nawy_token` cookie.
 * - Adds/stores the guest `X-Cart-Token` per country (CartController).
 * - On every sign-in (email code, password, password reset, social ticket
 *   exchange), moves the Sanctum token into the cookie and strips it from the
 *   JSON the page sees; on cart merge, drops the guest cart.
 * - Passes `Retry-After` / `Content-Language` through; drops the session on
 *   401 and on 403 `account_suspended` / `wrong_token`.
 * - Rejects cross-site writes (Origin check) on top of SameSite=Lax cookies.
 */

const API_ROOT = (process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1").replace(/\/$/, "");
const YEAR = 60 * 60 * 24 * 365;
/** Endpoints whose success answer carries a Sanctum token (D9 sign-in). */
const SIGN_IN_ENDPOINTS = new Set(["auth/email-code/verify", "auth/phone-code/verify", "auth/password/login", "auth/password/reset", "auth/social/exchange"]);
/** 403 codes that mean this browser's session is no longer usable. */
const SESSION_ENDING_CODES = new Set(["account_suspended", "wrong_token"]);

type Ctx = { params: Promise<{ path: string[] }> };

async function handle(request: NextRequest, { params }: Ctx): Promise<Response> {
  const { path } = await params;
  const market = parseMarket(path[0]);
  if (!market || path.length < 2 || path.some((p) => p === ".." || p === ".")) {
    return NextResponse.json({ error: { code: "not_found", message: "Unknown endpoint.", fields: {} } }, { status: 404 });
  }

  if (request.method !== "GET" && request.method !== "HEAD") {
    if (isCrossSite(request)) {
      return NextResponse.json({ error: { code: "forbidden", message: "Cross-site request refused.", fields: {} } }, { status: 403 });
    }
  }

  const countryCode = market.country.code;
  const token = request.cookies.get(TOKEN_COOKIE)?.value;
  const cartToken = request.cookies.get(cartCookie(countryCode))?.value;
  let visitor = request.cookies.get(VISITOR_COOKIE)?.value;
  const newVisitor = !visitor;
  if (!visitor) visitor = crypto.randomUUID().replace(/-/g, "");

  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Visitor-Token": visitor,
    "User-Agent": request.headers.get("user-agent") ?? "nawy-web",
  };
  const contentType = request.headers.get("content-type");
  if (contentType) headers["Content-Type"] = contentType;
  if (token) headers.Authorization = `Bearer ${token}`;
  if (cartToken) headers["X-Cart-Token"] = cartToken;
  const idempotencyKey = request.headers.get("idempotency-key");
  if (idempotencyKey) headers["Idempotency-Key"] = idempotencyKey;
  const forwardedFor = request.headers.get("x-forwarded-for");
  if (forwardedFor) headers["X-Forwarded-For"] = forwardedFor;

  const target = `${API_ROOT}/${path.map(encodeURIComponent).join("/")}${request.nextUrl.search}`;
  let upstream: Response;
  try {
    upstream = await fetch(target, {
      method: request.method,
      headers,
      body: request.method === "GET" || request.method === "HEAD" ? undefined : await request.arrayBuffer(),
      redirect: "manual",
      cache: "no-store",
    });
  } catch {
    return NextResponse.json({ error: { code: "api_unreachable", message: "The store is not reachable right now.", fields: {} } }, { status: 502 });
  }

  const endpoint = path.slice(1).join("/");
  let body: BodyInit | null = await upstream.arrayBuffer();
  let issuedToken: string | null = null;

  if (upstream.ok && SIGN_IN_ENDPOINTS.has(endpoint) && request.method === "POST") {
    try {
      const json = JSON.parse(new TextDecoder().decode(body as ArrayBuffer)) as { data?: { token?: string } };
      issuedToken = json.data?.token ?? null;
      if (json.data) delete json.data.token;
      body = JSON.stringify(json);
    } catch {
      // Leave the body untouched; the client will report the failure.
    }
  }

  let sessionEnded = upstream.status === 401;
  if (upstream.status === 403 && token) {
    try {
      const json = JSON.parse(new TextDecoder().decode(body as ArrayBuffer)) as { error?: { code?: string } };
      sessionEnded = SESSION_ENDING_CODES.has(json.error?.code ?? "");
    } catch {
      // Not an API envelope: keep the session.
    }
  }

  const response = new NextResponse(body, { status: upstream.status });
  const secure = secureCookies(request);
  response.headers.set("Content-Type", upstream.headers.get("content-type") ?? "application/json");
  response.headers.set("Cache-Control", "no-store");
  for (const name of ["retry-after", "content-language", "x-ratelimit-limit", "x-ratelimit-remaining"]) {
    const value = upstream.headers.get(name);
    if (value) response.headers.set(name, value);
  }

  if (newVisitor) {
    response.cookies.set(VISITOR_COOKIE, visitor, { httpOnly: true, sameSite: "lax", secure, path: "/", maxAge: YEAR });
  }

  if (issuedToken) {
    response.cookies.set(TOKEN_COOKIE, issuedToken, { httpOnly: true, sameSite: "lax", secure, path: "/", maxAge: 60 * 60 * 24 * 90 });
    response.cookies.set(AUTH_HINT_COOKIE, "1", { sameSite: "lax", secure, path: "/", maxAge: 60 * 60 * 24 * 90 });
  }

  const returnedCartToken = upstream.headers.get("x-cart-token");
  if (returnedCartToken && !token && returnedCartToken !== cartToken) {
    response.cookies.set(cartCookie(countryCode), returnedCartToken, { httpOnly: true, sameSite: "lax", secure, path: "/", maxAge: 60 * 60 * 24 * 30 });
    response.cookies.set(cartHintCookie(countryCode), "1", { sameSite: "lax", secure, path: "/", maxAge: 60 * 60 * 24 * 30 });
  }

  // Guest cart (G-W9): a read with an unknown/expired token returns an empty
  // cart and no token (the token is issued on the first write) — forget the
  // stale cookie so the header stops asking for it.
  if (upstream.ok && !token && cartToken && endpoint === "cart" && request.method === "GET" && !returnedCartToken) {
    response.cookies.delete(cartCookie(countryCode));
    response.cookies.delete(cartHintCookie(countryCode));
  }

  if (upstream.ok && endpoint === "cart/merge") {
    response.cookies.delete(cartCookie(countryCode));
    response.cookies.delete(cartHintCookie(countryCode));
  }

  if (sessionEnded && token) {
    response.cookies.delete(TOKEN_COOKIE);
    response.cookies.delete(AUTH_HINT_COOKIE);
  }

  return response;
}

export const GET = handle;
export const POST = handle;
export const PUT = handle;
export const PATCH = handle;
export const DELETE = handle;
