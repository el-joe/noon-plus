import { describe, expect, it } from "vitest";
import { retryMethods } from "@/features/checkout/payment-result";

describe("retryMethods", () => {
  it("drops cash on delivery, which the API refuses after the order is placed", () => {
    const methods = [{ code: "stripe" }, { code: "paytabs" }, { code: "cod" }, { code: "wallet" }];
    expect(retryMethods(methods).map((m) => m.code)).toEqual(["stripe", "paytabs", "wallet"]);
  });
});
