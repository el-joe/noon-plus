import { fileURLToPath } from "node:url";
import { defineConfig } from "vitest/config";

// Unit and component tests (WPT). Next's own guide adds @vitejs/plugin-react and
// vite-tsconfig-paths; oxc's automatic JSX runtime (Vite 8) and one alias cover both.
export default defineConfig({
  oxc: { jsx: { runtime: "automatic" } },
  resolve: { alias: { "@": fileURLToPath(new URL("./", import.meta.url)) } },
  test: {
    environment: "jsdom",
    include: ["tests/**/*.test.{ts,tsx}"],
    setupFiles: ["tests/setup.ts"],
    restoreMocks: true,
  },
});
