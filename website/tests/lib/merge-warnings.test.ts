import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { MERGE_WARNINGS_KEY, stashMergeWarnings, takeMergeWarnings } from "@/lib/merge-warnings";

describe("merge warnings", () => {
  beforeEach(() => window.sessionStorage.clear());
  afterEach(() => window.sessionStorage.clear());

  it("stashes warnings and takes them exactly once", () => {
    const warnings = [{ listing_id: 7, code: "stock", message: "Only 2 left", requested: 5, merged: 2 }];
    stashMergeWarnings({ merge_warnings: warnings });
    expect(window.sessionStorage.getItem(MERGE_WARNINGS_KEY)).not.toBeNull();
    expect(takeMergeWarnings()).toEqual(warnings);
    expect(window.sessionStorage.getItem(MERGE_WARNINGS_KEY)).toBeNull();
    expect(takeMergeWarnings()).toEqual([]);
  });

  it("does not stash empty or missing warnings", () => {
    stashMergeWarnings({});
    stashMergeWarnings({ merge_warnings: [] });
    stashMergeWarnings({ merge_warnings: "nope" });
    expect(window.sessionStorage.getItem(MERGE_WARNINGS_KEY)).toBeNull();
  });

  it("drops non-object entries", () => {
    window.sessionStorage.setItem(MERGE_WARNINGS_KEY, JSON.stringify([{ code: "x" }, null, 3, "s"]));
    expect(takeMergeWarnings()).toEqual([{ code: "x" }]);
  });

  it("returns [] for corrupt or non-array storage", () => {
    window.sessionStorage.setItem(MERGE_WARNINGS_KEY, "{not json");
    expect(takeMergeWarnings()).toEqual([]);
    window.sessionStorage.setItem(MERGE_WARNINGS_KEY, JSON.stringify({ code: "x" }));
    expect(takeMergeWarnings()).toEqual([]);
  });

  it("survives disabled storage", () => {
    vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
      throw new Error("QuotaExceeded");
    });
    vi.spyOn(Storage.prototype, "getItem").mockImplementation(() => {
      throw new Error("SecurityError");
    });
    expect(() => stashMergeWarnings({ merge_warnings: [{ code: "x" }] })).not.toThrow();
    expect(takeMergeWarnings()).toEqual([]);
  });
});
