import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { BlockBoundary } from "@/features/page-builder/block-boundary";
import { BLOCKS, PageZone, renderBlock } from "@/features/page-builder/render";
import type { BlockContext } from "@/features/page-builder/settings";
import type { Block } from "@/lib/api/schemas";
import { market } from "../helpers/render";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), prefetch: vi.fn() }),
  usePathname: () => "/uae-en",
  useSearchParams: () => new URLSearchParams(),
}));

const ctx = (slug = "uae-en"): BlockContext => ({ market: market(slug), categories: [] });
const html = (settings: Record<string, unknown>, id?: number): Block => ({ type: "html", settings, id });

afterEach(() => {
  delete BLOCKS.__throws;
});

describe("page-builder renderer", () => {
  it("renders a known block", () => {
    const { container } = render(<PageZone blocks={[html({ html: "<p>Hello</p>" })]} ctx={ctx()} />);
    expect(container.querySelector(".prose-cms")!.innerHTML).toBe("<p>Hello</p>");
  });

  it("skips unknown block types and keeps the rest of the zone", () => {
    const blocks: Block[] = [{ type: "hologram_banner", settings: { title: "Future" } }, html({ html: "<p>Still here</p>" }, 2)];
    render(<PageZone blocks={blocks} ctx={ctx()} />);
    expect(screen.queryByText("Future")).toBeNull();
    expect(screen.getByText("Still here")).toBeTruthy();
  });

  it("returns null from renderBlock for an unknown type", () => {
    expect(renderBlock({ type: "nope", settings: {} }, ctx(), 0)).toBeNull();
  });

  it("renders nothing for an empty or missing zone", () => {
    expect(render(<PageZone blocks={[]} ctx={ctx()} />).container.innerHTML).toBe("");
    expect(render(<PageZone blocks={undefined} ctx={ctx()} />).container.innerHTML).toBe("");
  });

  it("sanitises admin HTML", () => {
    const { container } = render(<PageZone blocks={[html({ html: '<p onclick="x()">Hi</p><script>alert(1)</script>' })]} ctx={ctx()} />);
    expect(container.querySelector(".prose-cms")!.innerHTML).toBe("<p>Hi</p>");
  });

  it("uses the localised setting for the market's language", () => {
    render(<PageZone blocks={[html({ html_ar: "<p>مرحبا</p>", html: "<p>Hello</p>", title_ar: "عنوان" })]} ctx={ctx("uae-ar")} />);
    expect(screen.getByText("مرحبا")).toBeTruthy();
    expect(screen.getByRole("heading", { level: 2 }).textContent).toBe("عنوان");
  });

  it("isolates a block that throws so the page keeps rendering", () => {
    const error = vi.spyOn(console, "error").mockImplementation(() => undefined);
    BLOCKS.__throws = () => {
      throw new Error("bad block");
    };
    render(<PageZone blocks={[{ type: "__throws", settings: {} }, html({ html: "<p>After</p>" }, 2)]} ctx={ctx()} />);
    expect(screen.getByText("After")).toBeTruthy();
    expect(error).toHaveBeenCalled();
  });

  it("BlockBoundary renders its children when nothing fails", () => {
    render(
      <BlockBoundary type="x">
        <p>ok</p>
      </BlockBoundary>,
    );
    expect(screen.getByText("ok")).toBeTruthy();
  });
});
