import { act, fireEvent, screen, waitFor, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { CategoryNav } from "@/components/layout/mega-menu";
import { renderWithProviders } from "../helpers/render";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), prefetch: vi.fn() }), usePathname: () => "/uae-en" }));
vi.mock("@/lib/api/endpoints", () => ({
  getCategories: vi.fn(async () => ({
    data: [
      { id: 1, slug: "electronics", name: "Electronics", children: [{ id: 2, slug: "mobiles", name: "Mobiles", children: [] }] },
      { id: 9, slug: "books", name: "Books", children: [] },
    ],
    meta: {},
  })),
  getCategoryMenu: vi.fn(async () => ({
    data: [
      {
        id: 1,
        slug: "electronics",
        name: "Electronics",
        path: "/electronics",
        image: "http://localhost:8000/storage/products/demo/product-smartphone.png",
        product_count: 12,
        children: [
          {
            id: 2,
            slug: "mobiles",
            name: "Mobiles",
            path: "/electronics/mobiles",
            image: null,
            product_count: 8,
            children: [
              { id: 3, slug: "smartphones", name: "Smartphones", path: "/electronics/smartphones", product_count: 6 },
              { id: 4, slug: "feature-phones", name: "Feature phones", path: "/electronics/feature-phones", product_count: 2 },
            ],
          },
          { id: 5, slug: "audio", name: "Audio", path: "/electronics/audio", image: null, product_count: 4, children: [{ id: 6, slug: "earbuds", name: "Earbuds", path: "/electronics/earbuds", product_count: 4 }] },
        ],
        brands: [
          { slug: "soundmax", name: "SoundMax", logo: "http://localhost:8000/storage/brands/soundmax.png", path: "/electronics?brand=soundmax", product_count: 7 },
          { slug: "voltix", name: "Voltix", logo: null, path: "/electronics?brand=voltix", product_count: 3 },
        ],
      },
    ],
    meta: {},
  })),
}));

const trigger = (slug: string) => document.querySelector<HTMLAnchorElement>(`[data-menu-trigger="${slug}"]`)!;

async function renderNav(market = "uae-en") {
  const utils = renderWithProviders(<CategoryNav />, { market });
  await screen.findAllByRole("link", { name: "Electronics" });
  // The menu tree loads next to the nav categories; wait until the trigger knows it has a flyout.
  await waitFor(() => expect(trigger("electronics").getAttribute("aria-haspopup")).toBe("true"));
  return utils;
}

describe("CategoryNav", () => {
  it("closes the open mega menu when a nav-bar category is clicked (it navigates)", async () => {
    await renderNav();
    const navLink = trigger("electronics");
    fireEvent.click(screen.getByRole("button", { name: /All categories/ }));
    expect(screen.getByRole("button", { name: /All categories/ }).getAttribute("aria-expanded")).toBe("true");
    navLink.addEventListener("click", (e) => e.preventDefault());
    fireEvent.click(navLink);
    expect(screen.getByRole("button", { name: /All categories/ }).getAttribute("aria-expanded")).toBe("false");
  });

  it("opens the category flyout on hover after a short intent delay and closes it on mouse leave", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      const { container } = await renderNav();
      fireEvent.mouseEnter(trigger("electronics"));
      expect(screen.queryByTestId("mega-menu-panel")).toBeNull();
      await act(async () => vi.advanceTimersByTime(200));

      const panel = screen.getByTestId("mega-menu-panel");
      expect(panel.id).toBe("mega-menu-electronics");
      expect(trigger("electronics").getAttribute("aria-expanded")).toBe("true");
      expect(trigger("electronics").getAttribute("aria-controls")).toBe(panel.id);
      expect(panel.getAttribute("role")).toBe("region");
      expect(panel.getAttribute("aria-labelledby")).toBeTruthy();

      const columns = within(panel).getAllByTestId("mega-menu-column");
      expect(columns.map((c) => within(c).getByRole("heading").textContent)).toEqual(["Mobiles", "Audio"]);
      expect(within(columns[0]).getByRole("link", { name: "Smartphones" }).getAttribute("href")).toBe("/uae-en/electronics/smartphones");
      expect(within(panel).getAllByTestId("mega-menu-leaf")).toHaveLength(3);
      expect(within(panel).getByTestId("mega-menu-image").getAttribute("href")).toBe("/uae-en/electronics");
      expect(within(panel).getAllByTestId("mega-menu-brand").map((b) => b.getAttribute("href"))).toEqual(["/uae-en/electronics?brand=soundmax", "/uae-en/electronics?brand=voltix"]);

      // A category without a menu entry (no columns data) never opens a flyout.
      fireEvent.mouseEnter(trigger("books"));
      await act(async () => vi.advanceTimersByTime(200));
      expect(screen.getByTestId("mega-menu-panel").id).toBe("mega-menu-electronics");

      fireEvent.mouseLeave(container.firstElementChild!);
      await act(async () => vi.advanceTimersByTime(250));
      expect(screen.queryByTestId("mega-menu-panel")).toBeNull();
      expect(trigger("electronics").getAttribute("aria-expanded")).toBe("false");
    } finally {
      vi.useRealTimers();
    }
  });

  it("opens with ArrowDown (focus moves into the panel), and Escape closes it and returns focus to the nav item", async () => {
    await renderNav();
    const link = trigger("electronics");
    link.focus();
    fireEvent.keyDown(link, { key: "ArrowDown" });
    const panel = await screen.findByTestId("mega-menu-panel");
    await waitFor(() => expect(panel.contains(document.activeElement)).toBe(true));
    expect(document.activeElement?.getAttribute("href")).toBe("/uae-en/electronics");

    fireEvent.keyDown(document.activeElement!, { key: "Escape" });
    expect(screen.queryByTestId("mega-menu-panel")).toBeNull();
    expect(document.activeElement).toBe(link);
  });

  it("opens with Enter when closed (Enter on an open item navigates) and closes when a panel link is followed", async () => {
    await renderNav();
    const link = trigger("electronics");
    link.focus();
    fireEvent.keyDown(link, { key: "Enter" });
    const panel = await screen.findByTestId("mega-menu-panel");
    const leaf = within(panel).getByRole("link", { name: "Earbuds" });
    leaf.addEventListener("click", (e) => e.preventDefault());
    fireEvent.click(leaf);
    expect(screen.queryByTestId("mega-menu-panel")).toBeNull();
  });

  it("puts the category image on the far side: last in the row, in a right-to-left panel for Arabic", async () => {
    await renderNav("uae-ar");
    fireEvent.keyDown(trigger("electronics"), { key: "Enter" });
    const panel = await screen.findByTestId("mega-menu-panel");
    expect(panel.getAttribute("dir")).toBe("rtl");
    const row = within(panel).getByTestId("mega-menu-row");
    expect(row.lastElementChild).toBe(within(panel).getByTestId("mega-menu-image"));
    expect(within(panel).getByText("أفضل العلامات التجارية")).toBeTruthy();
  });

  it("shows the brand logo when there is one and a text tile otherwise (or when the logo fails)", async () => {
    await renderNav();
    fireEvent.keyDown(trigger("electronics"), { key: "Enter" });
    const brands = within(await screen.findByTestId("mega-menu-brands")).getAllByTestId("mega-menu-brand");
    const logo = brands[0].querySelector("img")!;
    expect(logo.getAttribute("src")).toBe("http://localhost:8000/storage/brands/soundmax.png");
    expect(within(brands[1]).getByTestId("brand-text-tile").textContent).toBe("Voltix");

    fireEvent.error(logo);
    expect(within(brands[0]).getByTestId("brand-text-tile").textContent).toBe("SoundMax");
  });

  it("offers the same tree in a mobile drawer: category → level-2 accordion → level-3 links, image and top brands", async () => {
    await renderNav();
    const opener = screen.getByRole("button", { name: "Browse categories" });
    fireEvent.click(opener);
    const drawer = screen.getByRole("dialog");
    fireEvent.click(within(drawer).getByRole("button", { name: "Electronics" }));
    expect(within(drawer).getByTestId("mega-drawer-image").getAttribute("href")).toBe("/uae-en/electronics");
    expect(within(drawer).getAllByTestId("mega-menu-brand")).toHaveLength(2);
    expect(within(drawer).queryByRole("link", { name: "Smartphones" })).toBeNull();

    const mobiles = within(drawer).getByRole("button", { name: "Mobiles" });
    fireEvent.click(mobiles);
    expect(mobiles.getAttribute("aria-expanded")).toBe("true");
    expect(within(drawer).getByRole("link", { name: "Smartphones" }).getAttribute("href")).toBe("/uae-en/electronics/smartphones");

    fireEvent.keyDown(document, { key: "Escape" });
    expect(screen.queryByRole("dialog")).toBeNull();
    expect(document.activeElement).toBe(opener);
  });
});
