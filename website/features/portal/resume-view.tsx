"use client";

import { useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import { Alert, Skeleton } from "@/components/ui/misc";
import { isApplicationType, resumeApplication } from "@/lib/api/portal";
import { useHref, useMarket } from "@/lib/market-context";

/** Resume link (`?number=&token=`): store the token for this browser, then open the wizard or the status page. */
export function ResumeView({ number, token }: { number: string; token: string }) {
  const t = useTranslations("portal");
  const market = useMarket();
  const href = useHref();
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (!number || !token) {
      setFailed(true);
      return;
    }
    resumeApplication(market.slug, number, token)
      .then(({ data }) => {
        const type = isApplicationType(data.type) ? data.type : "vendor";
        const target = data.editable ? `${href(`/join/${type}`)}?number=${encodeURIComponent(data.number)}` : `${href("/join/status")}?number=${encodeURIComponent(data.number)}`;
        window.location.replace(target);
      })
      .catch(() => setFailed(true));
    // eslint-disable-next-line react-hooks/exhaustive-deps -- run once per link
  }, [market.slug, number, token]);

  if (failed) {
    return (
      <Alert tone="danger">
        {t("resume.invalid")}{" "}
        <a href={href("/join")} className="font-semibold underline">
          {t("status.startApplication")}
        </a>
      </Alert>
    );
  }
  return (
    <div className="flex flex-col gap-3">
      <p className="text-body text-secondary">{t("resume.opening")}</p>
      <Skeleton className="h-64 w-full" />
    </div>
  );
}
