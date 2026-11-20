"use client";

import { useEffect } from "react";

/**
 * Admin page-builder preview bridge. Active only inside the editor's preview
 * iframe (`?preview=` and a parent window): hovering a block draws a dashed
 * outline, clicking selects it in the editor (`pb:select` to the parent,
 * links are not followed), and the editor can select / scroll from its side
 * (`pb:select`, `pb:scrollTo`). Reports scroll so a refreshed preview keeps
 * its place (`pb:ready`, `pb:scroll`). Renders nothing.
 */
export function PreviewBridge() {
  useEffect(() => {
    if (window.parent === window || !new URLSearchParams(window.location.search).has("preview")) return;
    const post = (message: Record<string, unknown>) => window.parent.postMessage({ source: "nawy-preview", ...message }, "*");

    const box = (element: Element): DOMRect | null => {
      const rects = [...element.children].map((child) => child.getBoundingClientRect()).filter((r) => r.width > 0 && r.height > 0);
      if (rects.length === 0) return null;
      const left = Math.min(...rects.map((r) => r.left));
      const top = Math.min(...rects.map((r) => r.top));
      return new DOMRect(left, top, Math.max(...rects.map((r) => r.right)) - left, Math.max(...rects.map((r) => r.bottom)) - top);
    };
    const overlay = (style: string) => {
      const el = document.createElement("div");
      el.setAttribute("aria-hidden", "true");
      el.style.cssText = `position:fixed;pointer-events:none;z-index:2147483646;display:none;border-radius:10px;${style}`;
      document.body.append(el);
      return el;
    };
    const hover = overlay("outline:1px dashed #e9a20b;outline-offset:4px;");
    const selected = overlay("outline:2px solid #f9bb25;outline-offset:6px;");
    const tag = document.createElement("span");
    tag.style.cssText = "position:absolute;top:-30px;inset-inline-start:-6px;background:#f9bb25;color:#0d1526;font:600 12px/1 Inter,sans-serif;padding:6px 8px;border-radius:6px;white-space:nowrap;";
    selected.append(tag);
    let hovered: Element | null = null;
    let current: Element | null = null;

    const place = (el: HTMLElement, target: Element | null) => {
      const rect = target ? box(target) : null;
      if (!rect) {
        el.style.display = "none";
        return;
      }
      Object.assign(el.style, { display: "block", left: `${rect.left}px`, top: `${rect.top}px`, width: `${rect.width}px`, height: `${rect.height}px` });
    };
    const repaint = () => {
      place(hover, hovered && hovered !== current ? hovered : null);
      place(selected, current);
    };
    const blockOf = (target: EventTarget | null) => (target instanceof Element ? target.closest("[data-pb-block]") : null);

    const onOver = (e: MouseEvent) => {
      hovered = blockOf(e.target);
      repaint();
    };
    const onClick = (e: MouseEvent) => {
      const block = blockOf(e.target);
      if (!block) return;
      e.preventDefault();
      e.stopPropagation();
      current = block;
      repaint();
      post({ type: "pb:select", id: block.getAttribute("data-pb-block") });
    };
    let scrollTimer: number | undefined;
    const onScroll = () => {
      repaint();
      window.clearTimeout(scrollTimer);
      scrollTimer = window.setTimeout(() => post({ type: "pb:scroll", y: window.scrollY }), 150);
    };
    const onMessage = (e: MessageEvent) => {
      const data = e.data as { source?: string; type?: string; id?: string; y?: number; label?: string; scroll?: boolean } | null;
      if (!data || data.source !== "nawy-editor") return;
      if (data.type === "pb:select") {
        current = data.id ? document.querySelector(`[data-pb-block="${CSS.escape(data.id)}"]`) : null;
        tag.textContent = data.label ?? "";
        tag.style.display = data.label ? "block" : "none";
        if (current && data.scroll) {
          const rect = box(current);
          if (rect && (rect.top < 0 || rect.bottom > window.innerHeight)) window.scrollBy({ top: rect.top - 80, behavior: "smooth" });
        }
        repaint();
      } else if (data.type === "pb:scrollTo" && typeof data.y === "number") {
        window.scrollTo({ top: data.y });
      }
    };

    document.addEventListener("mouseover", onOver);
    document.addEventListener("click", onClick, true);
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", repaint);
    window.addEventListener("message", onMessage);
    post({ type: "pb:ready", y: window.scrollY });
    return () => {
      document.removeEventListener("mouseover", onOver);
      document.removeEventListener("click", onClick, true);
      window.removeEventListener("scroll", onScroll);
      window.removeEventListener("resize", repaint);
      window.removeEventListener("message", onMessage);
      hover.remove();
      selected.remove();
    };
  }, []);
  return null;
}
