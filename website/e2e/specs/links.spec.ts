import { readdirSync, readFileSync } from "node:fs";
import path from "node:path";
import { test, expect } from "@playwright/test";
import { GENERATED } from "../support/global-setup";
import { CUSTOMER_STATE } from "../support/accounts";

/**
 * Broken internal links: the union of every href the pages project saw, each
 * requested once (GET, redirects followed) with the signed-in session so
 * account links resolve. 404 and 5xx are failures.
 */
test.use({ storageState: CUSTOMER_STATE });

test("no broken internal links", async ({ request }) => {
  test.setTimeout(20 * 60_000);
  const dir = path.join(GENERATED, "links");
  const sources = new Map<string, string>();
  for (const file of readdirSync(dir)) {
    const { from, links } = JSON.parse(readFileSync(path.join(dir, file), "utf8")) as { from: string; links: string[] };
    for (const link of links) if (!sources.has(link)) sources.set(link, from);
  }
  const urls = [...sources.keys()].filter((u) => !/\/(api|_next)\//.test(u) && !/[?&](logout|sign-?out)/.test(u));
  const broken: string[] = [];
  const queue = [...urls];
  await Promise.all(
    Array.from({ length: 6 }, async () => {
      for (let url = queue.shift(); url; url = queue.shift()) {
        const res = await request.get(url, { maxRedirects: 5, timeout: 60_000 }).catch((e: Error) => ({ status: () => 0, e }));
        const status = res.status();
        if (status === 0 || status === 404 || status >= 500) broken.push(`${status} ${url} (linked from ${sources.get(url)})`);
      }
    }),
  );
  test.info().annotations.push({ type: "links checked", description: String(urls.length) });
  expect(broken, `${broken.length} broken of ${urls.length} links`).toEqual([]);
});
