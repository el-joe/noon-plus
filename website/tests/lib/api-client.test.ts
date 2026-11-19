import { afterEach, describe, expect, it, vi } from "vitest";
import { z } from "zod";
import { ApiError, buildUrl, fieldErrors, get, isSessionEnded, orNull, request } from "@/lib/api/client";

function respond(status: number, body: unknown, headers: Record<string, string> = {}) {
  const text = body === undefined ? "" : typeof body === "string" ? body : JSON.stringify(body);
  return vi.fn(async () => new Response(text, { status, headers: { "Content-Type": "application/json", ...headers } }));
}

async function caught(promise: Promise<unknown>): Promise<ApiError> {
  try {
    await promise;
  } catch (error) {
    if (error instanceof ApiError) return error;
    throw error;
  }
  throw new Error("expected the call to fail");
}

afterEach(() => {
  vi.unstubAllGlobals();
  document.documentElement.lang = "";
});

describe("buildUrl", () => {
  it("uses the same-origin BFF proxy in the browser", () => {
    expect(buildUrl("uae-en", "/cart")).toBe("/api/nawy/uae-en/cart");
  });

  it("omits a null market", () => {
    expect(buildUrl(null, "countries")).toBe("/api/nawy/countries");
  });

  it("drops empty query values and keeps false / 0", () => {
    expect(buildUrl("uae-en", "products", { q: "tv", page: 0, deals: false, brand: "", x: null, y: undefined })).toBe("/api/nawy/uae-en/products?q=tv&page=0&deals=false");
  });
});

describe("request", () => {
  const schema = z.object({ id: z.number() });

  it("returns data and object meta from a success envelope", async () => {
    vi.stubGlobal("fetch", respond(200, { data: { id: 1, extra: "stripped" }, meta: { total: 3 } }));
    const result = await get("uae-en", "thing", schema);
    expect(result).toEqual({ data: { id: 1 }, meta: { total: 3 } });
  });

  it("normalises a missing or array meta to {}", async () => {
    vi.stubGlobal("fetch", respond(200, { data: { id: 1 }, meta: [] }));
    expect((await get("uae-en", "thing", schema)).meta).toEqual({});
    vi.stubGlobal("fetch", respond(200, { data: { id: 2 } }));
    expect((await get("uae-en", "thing", schema)).meta).toEqual({});
  });

  it("sends JSON bodies with method and headers", async () => {
    const fetchMock = respond(200, { data: { id: 1 } });
    vi.stubGlobal("fetch", fetchMock);
    await request("uae-en", "cart/items", schema, { method: "POST", body: { qty: 2 }, headers: { "X-Test": "1" } });
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe("/api/nawy/uae-en/cart/items");
    expect(init.method).toBe("POST");
    expect(init.body).toBe('{"qty":2}');
    const headers = init.headers as Record<string, string>;
    expect(headers["Content-Type"]).toBe("application/json");
    expect(headers["X-Test"]).toBe("1");
    expect(headers["Accept-Language"]).toBeUndefined();
  });

  it("sends Accept-Language from <html lang> for market-less calls", async () => {
    const fetchMock = respond(200, { data: { id: 1 } });
    vi.stubGlobal("fetch", fetchMock);
    document.documentElement.lang = "ar";
    await get(null, "countries", schema);
    const init = (fetchMock.mock.calls[0] as unknown as [string, RequestInit])[1];
    expect((init.headers as Record<string, string>)["Accept-Language"]).toBe("ar");
  });

  it("does not set Content-Type for FormData", async () => {
    const fetchMock = respond(200, { data: { id: 1 } });
    vi.stubGlobal("fetch", fetchMock);
    const form = new FormData();
    form.set("file", "x");
    await request("uae-en", "upload", schema, { method: "POST", body: form });
    const init = (fetchMock.mock.calls[0] as unknown as [string, RequestInit])[1];
    expect(init.body).toBe(form);
    expect((init.headers as Record<string, string>)["Content-Type"]).toBeUndefined();
  });

  it("parses the API error envelope with fields and extra details", async () => {
    vi.stubGlobal("fetch", respond(422, { error: { code: "coupon_invalid", message: "Coupon expired", fields: { code: ["Expired"] }, min: 50 } }));
    const error = await caught(get("uae-en", "cart", schema));
    expect(error.status).toBe(422);
    expect(error.code).toBe("coupon_invalid");
    expect(error.message).toBe("Coupon expired");
    expect(error.fields).toEqual({ code: ["Expired"] });
    expect(error.details).toEqual({ min: 50 });
  });

  it("treats an array `fields` (empty PHP array) as no fields", async () => {
    vi.stubGlobal("fetch", respond(409, { error: { code: "conflict", message: "No", fields: [] } }));
    const error = await caught(get("uae-en", "cart", schema));
    expect(error.fields).toEqual({});
  });

  it.each([
    [401, "unauthenticated"],
    [404, "not_found"],
    [422, "validation_failed"],
    [500, "http_error"],
  ])("maps Laravel's own %i response to %s", async (status, code) => {
    vi.stubGlobal("fetch", respond(status, { message: "Laravel says no", errors: { email: ["Taken"] } }));
    const error = await caught(get("uae-en", "x", schema));
    expect(error.status).toBe(status);
    expect(error.code).toBe(code);
    expect(error.message).toBe("Laravel says no");
    expect(error.fields).toEqual({ email: ["Taken"] });
  });

  it("reads Retry-After seconds on 429", async () => {
    vi.stubGlobal("fetch", respond(429, { message: "Too Many Attempts." }, { "Retry-After": "42" }));
    const error = await caught(get("uae-en", "x", schema));
    expect(error.code).toBe("too_many_requests");
    expect(error.retryAfter).toBe(42);
  });

  it("ignores Retry-After on other statuses", async () => {
    vi.stubGlobal("fetch", respond(503, null, { "Retry-After": "42" }));
    const error = await caught(get("uae-en", "x", schema));
    expect(error.retryAfter).toBeNull();
    expect(error.code).toBe("http_error");
  });

  it("falls back to the status text / code for a non-JSON error body", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => new Response("<html>oops</html>", { status: 502, statusText: "Bad Gateway" })));
    const error = await caught(get("uae-en", "x", schema));
    expect(error.message).toBe("Bad Gateway");
  });

  it("wraps network failures as network_error (status 0)", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => Promise.reject(new TypeError("Failed to fetch"))));
    const error = await caught(get("uae-en", "x", schema));
    expect(error.status).toBe(0);
    expect(error.code).toBe("network_error");
    expect(error.message).toBe("Failed to fetch");
  });

  it("rejects a success response that does not match the schema", async () => {
    vi.spyOn(console, "error").mockImplementation(() => undefined);
    vi.stubGlobal("fetch", respond(200, { data: { id: "not a number" } }));
    const error = await caught(get("uae-en", "x", schema));
    expect(error.code).toBe("invalid_response");
    expect(error.status).toBe(200);
  });
});

describe("error helpers", () => {
  it("isSessionEnded: any 401, and 403 only for session codes", () => {
    expect(isSessionEnded(new ApiError(401, "whatever", "m"))).toBe(true);
    expect(isSessionEnded(new ApiError(403, "account_suspended", "m"))).toBe(true);
    expect(isSessionEnded(new ApiError(403, "wrong_token", "m"))).toBe(true);
    expect(isSessionEnded(new ApiError(403, "forbidden", "m"))).toBe(false);
    expect(isSessionEnded(new ApiError(500, "unauthenticated", "m"))).toBe(false);
    expect(isSessionEnded(new Error("x"))).toBe(false);
  });

  it("orNull turns a 404 into null and rethrows others", async () => {
    expect(await orNull(Promise.reject(new ApiError(404, "not_found", "m")))).toBeNull();
    expect(await orNull(Promise.resolve(5))).toBe(5);
    await expect(orNull(Promise.reject(new ApiError(500, "http_error", "m")))).rejects.toBeInstanceOf(ApiError);
  });

  it("fieldErrors keeps the first message per field", () => {
    const error = new ApiError(422, "validation_failed", "m", { email: ["Taken", "Invalid"], name: [] });
    expect(fieldErrors(error)).toEqual({ email: "Taken" });
    expect(fieldErrors(new Error("x"))).toEqual({});
  });
});
