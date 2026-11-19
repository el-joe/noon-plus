import { NextResponse, type NextRequest } from "next/server";
import { parseMarket } from "@/lib/markets";
import { secureCookies, isCrossSite } from "@/lib/session";

/**
 * Same-origin proxy for the portal registration wizards
 * (`/api/v1/{market}/partner-applications/*`, WP8).
 *
 * The API returns the guest access token once, in the `X-Application-Token`
 * response header (and `meta.access_token`), when the draft is created;
 * later calls send it back as `X-Application-Token`. This
 * handler keeps the tokens of the visitor's applications in one httpOnly
 * cookie per country (`nawy_app_{country}` = {number: token}) and strips the
 * token from the JSON the page sees, so page JS never holds it.
 *
 * Extra local actions (not forwarded):
 * - `GET  {market}/mine`            → the application numbers this browser holds.
 * - `POST {market}/resume`          → {number, token} from a resume link: checked
 *                                      against the API, then stored in the cookie.
 * - `DELETE {market}/forget/{number}` → drop one application from the cookie.
 */

const API_ROOT = (process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1").replace(/\/$/, "");
const MAX_APPLICATIONS = 8;
const NUMBER = /^[A-Za-z0-9-]{3,40}$/;

type Ctx = { params: Promise<{ path: string[] }> };
type Tokens = Record<string, string>;

const cookieName = (country: string) => `nawy_app_${country}`;
const json = (body: unknown, status = 200) => NextResponse.json(body, { status, headers: { "Cache-Control": "no-store" } });
const failure = (code: string, message: string, status: number) => json({ error: { code, message, fields: {} } }, status);

function readTokens(request: NextRequest, country: string): Tokens {
  const raw = request.cookies.get(cookieName(country))?.value;
  if (!raw) return {};
  try {
    const parsed = JSON.parse(Buffer.from(raw, "base64url").toString("utf8")) as unknown;
    if (!parsed || typeof parsed !== "object") return {};
    return Object.fromEntries(Object.entries(parsed as Record<string, unknown>).filter((e): e is [string, string] => typeof e[1] === "string" && NUMBER.test(e[0])));
  } catch {
    return {};
  }
}

function writeTokens(request: NextRequest, response: NextResponse, country: string, tokens: Tokens) {
  const secure = secureCookies(request);
  const entries = Object.entries(tokens).slice(-MAX_APPLICATIONS);
  if (entries.length === 0) {
    response.cookies.delete(cookieName(country));
    return;
  }
  const value = Buffer.from(JSON.stringify(Object.fromEntries(entries)), "utf8").toString("base64url");
  response.cookies.set(cookieName(country), value, { httpOnly: true, sameSite: "lax", secure, path: "/", maxAge: 60 * 60 * 24 * 60 });
}

function upstreamHeaders(request: NextRequest, token?: string): Record<string, string> {
  const headers: Record<string, string> = { Accept: "application/json", "User-Agent": request.headers.get("user-agent") ?? "nawy-web" };
  const contentType = request.headers.get("content-type");
  if (contentType) headers["Content-Type"] = contentType;
  const forwardedFor = request.headers.get("x-forwarded-for");
  if (forwardedFor) headers["X-Forwarded-For"] = forwardedFor;
  if (token) headers["X-Application-Token"] = token;
  return headers;
}

async function handle(request: NextRequest, { params }: Ctx): Promise<Response> {
  const { path } = await params;
  const market = parseMarket(path[0]);
  const rest = path.slice(1);
  if (!market || rest.some((p) => p === ".." || p === "." || p === "")) return failure("not_found", "Unknown endpoint.", 404);

  if (request.method !== "GET" && request.method !== "HEAD") {
    if (isCrossSite(request)) return failure("forbidden", "Cross-site request refused.", 403);
  }

  const country = market.country.code;
  const tokens = readTokens(request, country);
  const base = `${API_ROOT}/${market.slug}/partner-applications`;

  // Local actions.
  if (rest[0] === "mine" && request.method === "GET") {
    return json({ data: Object.keys(tokens).reverse(), meta: {} });
  }
  if (rest[0] === "forget" && rest[1] && request.method === "DELETE") {
    const next = { ...tokens };
    delete next[rest[1]];
    const response = json({ data: { forgotten: true }, meta: {} });
    writeTokens(request, response, country, next);
    return response;
  }
  if (rest[0] === "resume" && request.method === "POST") {
    const body = (await request.json().catch(() => null)) as { number?: unknown; token?: unknown } | null;
    const number = typeof body?.number === "string" ? body.number : "";
    const token = typeof body?.token === "string" ? body.token : "";
    if (!NUMBER.test(number) || token.length < 8 || token.length > 200) return failure("not_found", "This link is not valid.", 404);
    try {
      const check = await fetch(`${base}/${encodeURIComponent(number)}`, { headers: upstreamHeaders(request, token), cache: "no-store" });
      const response = new NextResponse(await check.arrayBuffer(), { status: check.status });
      response.headers.set("Content-Type", check.headers.get("content-type") ?? "application/json");
      response.headers.set("Cache-Control", "no-store");
      if (check.ok) {
        const next = { ...tokens };
        delete next[number];
        next[number] = token;
        writeTokens(request, response, country, next);
      }
      return response;
    } catch {
      return failure("api_unreachable", "The store is not reachable right now.", 502);
    }
  }

  // Forwarded calls: the first segment after the market is the application number (or a collection action).
  const collection = rest.length === 0 || rest[0] === "check-slug" || rest[0] === "resume-link";
  const token = collection ? undefined : tokens[rest[0]];
  const target = `${base}${rest.length ? `/${rest.map(encodeURIComponent).join("/")}` : ""}${request.nextUrl.search}`;

  let upstream: Response;
  try {
    upstream = await fetch(target, {
      method: request.method,
      headers: upstreamHeaders(request, token),
      body: request.method === "GET" || request.method === "HEAD" ? undefined : await request.arrayBuffer(),
      redirect: "manual",
      cache: "no-store",
    });
  } catch {
    return failure("api_unreachable", "The store is not reachable right now.", 502);
  }

  let body: BodyInit = await upstream.arrayBuffer();
  let issued: { number: string; token: string } | null = null;

  // Draft created: move the access token into the cookie.
  if (upstream.ok && rest.length === 0 && request.method === "POST") {
    try {
      const parsed = JSON.parse(new TextDecoder().decode(body as ArrayBuffer)) as { data?: { number?: string }; meta?: Record<string, unknown> };
      const headerToken = upstream.headers.get("x-application-token");
      const accessToken = headerToken || parsed.meta?.access_token;
      if (typeof accessToken === "string" && accessToken && parsed.data?.number) issued = { number: parsed.data.number, token: accessToken };
      if (parsed.meta) {
        delete parsed.meta.access_token;
        delete parsed.meta.token_header;
      }
      body = JSON.stringify(parsed);
    } catch {
      // Leave the body as is; the page reports the failure.
    }
  }

  const response = new NextResponse(body, { status: upstream.status });
  response.headers.set("Content-Type", upstream.headers.get("content-type") ?? "application/json");
  response.headers.set("Cache-Control", "no-store");
  const retryAfter = upstream.headers.get("retry-after");
  if (retryAfter) response.headers.set("Retry-After", retryAfter);

  if (issued) {
    writeTokens(request, response, country, { ...tokens, [issued.number]: issued.token });
  } else if (!collection && rest.length === 1 && request.method === "GET" && upstream.status === 404 && tokens[rest[0]]) {
    // Token rotated (a newer resume link) or application gone: forget it.
    const next = { ...tokens };
    delete next[rest[0]];
    writeTokens(request, response, country, next);
  }

  return response;
}

export const GET = handle;
export const POST = handle;
export const DELETE = handle;
