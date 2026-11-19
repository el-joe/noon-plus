import { readFileSync } from "node:fs";
import path from "node:path";
import { GENERATED } from "../../../support/global-setup";

type Ref = { id: number; number: string; order: string };

/** Records seeded by E2eSeeder (copied by global setup), plus the e2e store's product. */
export const fixtures = () => {
  const f = JSON.parse(readFileSync(path.join(GENERATED, "fixtures.json"), "utf8")) as {
    store_slug: string; active_listing_id: number; placed_sub_order: Ref; packed_sub_order: Ref; delivered_sub_order: Ref;
    referral_code: string; fbn: { warehouse_code: string; listing_id: number; available: number }; legacy_redirect: { from: string; to: string };
  };
  // Both e2e listings (16 seller-fulfilled, 19 FBN) are offers on this product; FBN wins the buy box.
  return { ...f, product_slug: "nova-x12-smartphone" };
};
