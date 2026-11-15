import type { enMessages } from "./lib/i18n";
import type { Locale } from "./lib/markets";

declare module "next-intl" {
  interface AppConfig {
    Locale: Locale;
    Messages: typeof enMessages;
  }
}
