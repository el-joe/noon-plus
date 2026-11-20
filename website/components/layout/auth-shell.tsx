import { Globe, Truck, Wallet } from "lucide-react";
import type { ReactNode } from "react";
import { Container } from "@/components/ui/misc";
import { getT } from "@/lib/i18n";
import type { Locale } from "@/lib/markets";

/**
 * Sign-in / password / add-email frame (Figma Login 28:2, AR 84:2) inside the
 * normal storefront header and footer: a rounded two-column card in the page
 * container. The dark marketing panel sits at the start side (right in RTL)
 * from `lg` up and is hidden on smaller screens, where the form takes the
 * full width.
 */
export function AuthShell({ locale, children }: { locale: Locale; children: ReactNode }) {
  const t = getT(locale);
  const points = [
    { icon: Truck, title: t("auth.heroDelivery"), hint: t("auth.heroDeliveryHint") },
    { icon: Wallet, title: t("auth.heroWallet"), hint: t("auth.heroWalletHint") },
    { icon: Globe, title: t("auth.heroCountries"), hint: t("auth.heroCountriesHint") },
  ];
  return (
    <Container className="py-6 md:py-10">
      <div data-auth-shell className="grid min-w-0 lg:min-h-[600px] lg:overflow-hidden lg:rounded-2xl lg:border lg:border-line lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
        <section className="hidden min-w-0 flex-col justify-end gap-6 bg-ink p-10 text-white lg:flex xl:p-12">
          <h2 className="text-[34px] font-bold leading-tight text-balance">{t("auth.heroTitle")}</h2>
          <ul className="flex flex-col gap-4">
            {points.map(({ icon: Icon, title, hint }) => (
              <li key={title} className="flex items-center gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-white/10 text-brand">
                  <Icon className="size-5" />
                </span>
                <span className="min-w-0">
                  <span className="block text-body font-medium">{title}</span>
                  <span className="text-body-sm opacity-70">{hint}</span>
                </span>
              </li>
            ))}
          </ul>
        </section>
        <section className="flex min-w-0 items-center justify-center lg:p-10">{children}</section>
      </div>
    </Container>
  );
}
