import { Globe, Truck, Wallet } from "lucide-react";
import type { Metadata } from "next";
import { Suspense } from "react";
import { Skeleton } from "@/components/ui/misc";
import { SignInForm } from "@/features/auth/sign-in-form";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/login">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("header.signIn"), robots: { index: false } };
}

/** `/login` — email + 6-digit code (Figma Login 28:2, AR 84:2, OTP 28:107). */
export default async function LoginPage({ params }: PageProps<"/[market]/login">) {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  const points = [
    { icon: Truck, title: t("auth.heroDelivery"), hint: t("auth.heroDeliveryHint") },
    { icon: Wallet, title: t("auth.heroWallet"), hint: t("auth.heroWalletHint") },
    { icon: Globe, title: t("auth.heroCountries"), hint: t("auth.heroCountriesHint") },
  ];
  return (
    <div className="grid min-h-[640px] lg:grid-cols-[minmax(0,5fr)_minmax(0,9fr)]">
      <section className="hidden flex-col justify-end gap-6 bg-ink p-10 text-white lg:flex">
        <h2 className="text-[34px] font-bold leading-tight">{t("auth.heroTitle")}</h2>
        <ul className="flex flex-col gap-4">
          {points.map(({ icon: Icon, title, hint }) => (
            <li key={title} className="flex items-center gap-3">
              <span className="flex size-10 items-center justify-center rounded-full bg-white/10 text-brand">
                <Icon className="size-5" />
              </span>
              <span>
                <span className="block text-body font-medium">{title}</span>
                <span className="text-body-sm opacity-70">{hint}</span>
              </span>
            </li>
          ))}
        </ul>
      </section>
      <section className="flex items-center justify-center p-4 py-10">
        <Suspense fallback={<Skeleton className="h-96 w-full max-w-md" />}>
          <SignInForm />
        </Suspense>
      </section>
    </div>
  );
}
