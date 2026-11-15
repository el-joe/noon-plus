"use client";

import { ServerCrash } from "lucide-react";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { Container, EmptyState } from "@/components/ui/misc";

/** Error 500 (Figma 71:11072). */
export default function ErrorPage({ retry }: { error: Error & { digest?: string }; retry: () => void }) {
  const t = useTranslations("common");
  return (
    <Container className="py-16">
      <EmptyState icon={<ServerCrash className="size-12" />} title={t("errorTitle")} body={t("unreachable")} action={<Button onClick={() => retry()}>{t("retry")}</Button>} />
    </Container>
  );
}
