import { existsSync, mkdirSync } from "node:fs";
import os from "node:os";
import path from "node:path";
import { test as base } from "../../../../support/fixtures";
import { panelState, panelUrl } from "../../../../support/accounts";
import { panelSignIn } from "./vendor";

export { expect, expectClean } from "../../../../support/fixtures";

/**
 * The e2e seller with a session of its own per worker. Laravel flashes
 * ("Order … was cancelled") live in the session, so two workers sharing the
 * saved `.auth/partner.json` session would eat each other's flash messages.
 * The saved file is only read, never rewritten.
 */
export const test = base.extend<object, { sellerState: string }>({
  sellerState: [
    async ({ browser }, use, workerInfo) => {
      // Kept between runs (the e2e seller may sign in only 30 times an hour); checked and renewed when expired.
      const dir = path.join(os.tmpdir(), "nawy-e2e-vendor");
      mkdirSync(dir, { recursive: true });
      const file = path.join(dir, `seller-worker-${workerInfo.workerIndex}.json`);
      const context = await browser.newContext({ storageState: existsSync(file) ? file : { cookies: [], origins: [] } });
      const page = await context.newPage();
      await page.goto(panelUrl("partner"));
      if (/\/login/.test(page.url())) await panelSignIn(page, "partner");
      await context.storageState({ path: file });
      await context.close();
      await use(file);
    },
    { scope: "worker" },
  ],
  storageState: async ({ sellerState }, use) => use(sellerState),
});

export const SAVED_PARTNER_STATE = panelState("partner");
