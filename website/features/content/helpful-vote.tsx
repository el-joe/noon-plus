"use client";

import { useMutation } from "@tanstack/react-query";
import clsx from "clsx";
import { ThumbsDown, ThumbsUp } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState } from "react";
import { voteHelpArticle, type HelpHub } from "@/lib/api/content";
import { errorMessage } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

/**
 * "Was this helpful?" (`POST help/{hub}/articles/{slug}/votes`): one vote per
 * signed-in customer, else per visitor token (added by the BFF proxy);
 * voting again switches the answer.
 */
export function HelpfulVote({ hub, slug, initial }: { hub: HelpHub; slug: string; initial?: { yes: number; no: number } }) {
  const t = useTranslations("content.help");
  const tc = useTranslations("common");
  const market = useMarket();
  const [counts, setCounts] = useState(initial ?? null);
  const [mine, setMine] = useState<boolean | null>(null);
  const vote = useMutation({
    mutationFn: async (helpful: boolean) => (await voteHelpArticle(market.slug, hub, slug, helpful)).data,
    onSuccess: (data, helpful) => {
      setCounts(data.helpful);
      setMine(data.my_vote ?? helpful);
    },
  });
  const total = counts ? counts.yes + counts.no : 0;
  const button = (helpful: boolean) => (
    <button
      type="button"
      onClick={() => vote.mutate(helpful)}
      disabled={vote.isPending}
      aria-pressed={mine === helpful}
      className={clsx("inline-flex h-8 items-center gap-1.5 rounded-md border px-3", mine === helpful ? "border-ink bg-ink text-white" : "border-line-strong")}
    >
      {helpful ? <ThumbsUp className="size-4" /> : <ThumbsDown className="size-4" />}
      {helpful ? t("yes") : t("no")}
    </button>
  );
  return (
    <div className="flex flex-col gap-2 border-t border-line pt-4 text-body-sm">
      <div className="flex flex-wrap items-center gap-3">
        <span>{t("wasHelpful")}</span>
        {button(true)}
        {button(false)}
      </div>
      {mine !== null ? <p className="text-success-text">{t("thanks")} <span className="text-secondary">{t("changeVote")}</span></p> : null}
      {counts && total > 0 ? <p className="text-secondary">{t("helpfulCount", { yes: counts.yes, total })}</p> : null}
      {vote.error ? <p className="text-danger-text">{errorMessage(vote.error, tc("error"))}</p> : null}
    </div>
  );
}
