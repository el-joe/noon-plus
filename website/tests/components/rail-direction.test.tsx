import { screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import useEmblaCarousel from "embla-carousel-react";
import { Rail } from "@/components/ui/carousel";
import { renderWithProviders } from "../helpers/render";

const emblaApi = {
  canScrollPrev: () => true,
  canScrollNext: () => true,
  selectedScrollSnap: () => 0,
  scrollSnapList: () => [0, 1],
  on() {
    return emblaApi;
  },
  off() {
    return emblaApi;
  },
  scrollPrev: vi.fn(),
  scrollNext: vi.fn(),
  scrollTo: vi.fn(),
};
vi.mock("embla-carousel-react", () => ({ default: vi.fn(() => [() => undefined, emblaApi]) }));

const icon = (name: string) => screen.getByRole("button", { name }).querySelector("svg")!.getAttribute("class") ?? "";

describe("Rail direction (RTL markets)", () => {
  it("passes ltr to embla and points Previous left in English", () => {
    renderWithProviders(
      <Rail>
        <div>a</div>
      </Rail>,
      { market: "uae-en" },
    );
    expect(vi.mocked(useEmblaCarousel).mock.lastCall?.[0]).toMatchObject({ direction: "ltr" });
    expect(icon("Previous")).toContain("chevron-left");
    expect(icon("Next")).toContain("chevron-right");
  });

  it("passes rtl to embla and mirrors the arrows in Arabic", () => {
    renderWithProviders(
      <Rail>
        <div>a</div>
      </Rail>,
      { market: "ksa-ar" },
    );
    expect(vi.mocked(useEmblaCarousel).mock.lastCall?.[0]).toMatchObject({ direction: "rtl" });
    expect(screen.getByRole("button", { name: "السابق" })).toBeTruthy();
    expect(icon("السابق")).toContain("chevron-right");
    expect(icon("التالي")).toContain("chevron-left");
  });
});
