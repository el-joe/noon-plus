"use client";

import { Component, type ReactNode } from "react";

/** Isolates a page-builder block: if it throws, the block disappears and the page keeps rendering. */
export class BlockBoundary extends Component<{ type: string; children: ReactNode }, { failed: boolean }> {
  state = { failed: false };

  static getDerivedStateFromError() {
    return { failed: true };
  }

  componentDidCatch(error: unknown) {
    if (process.env.NODE_ENV !== "production") console.error(`[page-builder] block "${this.props.type}" failed`, error);
  }

  render() {
    return this.state.failed ? null : this.props.children;
  }
}
