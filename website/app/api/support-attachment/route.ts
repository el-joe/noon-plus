import { NextResponse, type NextRequest } from "next/server";
import { parseMarket } from "@/lib/markets";
import { TOKEN_COOKIE } from "@/lib/session";

/**
 * `GET /api/support-attachment?u={signed url}` — opens a support ticket
 * attachment (QC-007). The API answers with signed links that expire after
 * 30 minutes; this handler accepts only links to the API's own
 * `/{market}/support/attachments/{message}/{index}` path that carry a
 * signature, and streams the file back from the same origin.
 */

const API_ROOT = (process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1").replace(/\/$/, "");
const ALLOWED_ORIGINS = new Set(
  [process.env.API_URL, process.env.NEXT_PUBLIC_API_URL, API_ROOT]
    .filter((v): v is string => !!v)
    .map((v) => {
      try {
        return new URL(v).origin;
      } catch {
        return "";
      }
    })
    .filter(Boolean),
);
const API_PATH = new URL(API_ROOT).pathname.replace(/\/$/, "");
const ATTACHMENT = /^\/([a-z]{3}-[a-z]{2})\/support\/attachments\/\d+\/\d+$/;

function fail(status: number, message: string) {
  return NextResponse.json({ error: { code: status === 403 ? "forbidden" : "not_found", message, fields: {} } }, { status });
}

export async function GET(request: NextRequest): Promise<Response> {
  const raw = request.nextUrl.searchParams.get("u");
  if (!raw) return fail(404, "Unknown attachment.");
  let url: URL;
  try {
    url = new URL(raw);
  } catch {
    return fail(404, "Unknown attachment.");
  }
  const rest = url.pathname.startsWith(`${API_PATH}/`) ? url.pathname.slice(API_PATH.length) : "";
  const match = ATTACHMENT.exec(rest);
  if (!ALLOWED_ORIGINS.has(url.origin) || !match || !parseMarket(match[1]) || !url.searchParams.has("signature")) {
    return fail(404, "Unknown attachment.");
  }

  const token = request.cookies.get(TOKEN_COOKIE)?.value;
  let upstream: Response;
  try {
    upstream = await fetch(url.toString(), {
      headers: { Accept: "*/*", ...(token ? { Authorization: `Bearer ${token}` } : {}) },
      redirect: "manual",
      cache: "no-store",
    });
  } catch {
    return fail(404, "The store is not reachable right now.");
  }
  if (!upstream.ok) return fail(upstream.status === 403 ? 403 : 404, upstream.status === 403 ? "This link has expired. Reload the ticket to get a new one." : "Unknown attachment.");

  const headers = new Headers({ "Cache-Control": "private, no-store", "X-Content-Type-Options": "nosniff" });
  for (const name of ["content-type", "content-length", "content-disposition"]) {
    const value = upstream.headers.get(name);
    if (value) headers.set(name, value);
  }
  return new NextResponse(upstream.body, { status: 200, headers });
}
