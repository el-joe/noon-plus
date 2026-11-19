"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Check, Plus } from "lucide-react";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { followSeller, getSeller } from "@/lib/api/sellers";
import { useLoginRedirect, useSession } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

/**
 * Follow a product store (QC-008): the server-rendered page is the guest view,
 * so a signed-in customer's `following` is read again through the BFF.
 * `POST` / `DELETE sellers/{slug}/follow` answer `{following, followers}`.
 */
export function SellerFollowButton({ slug, initialFollowers }: { slug: string; initialFollowers: number }) {
  const t = useTranslations("storefront.seller");
  const market = useMarket();
  const client = useQueryClient();
  const toLogin = useLoginRedirect();
  const { isSignedIn } = useSession();
  const key = ["seller-follow", market.slug, slug] as const;
  const state = useQuery({
    queryKey: key,
    enabled: isSignedIn,
    staleTime: 60_000,
    queryFn: async () => {
      const { data } = await getSeller(market.slug, slug);
      return { following: data.following, followers: data.followers };
    },
  });
  const following = state.data?.following ?? false;
  const followers = state.data?.followers ?? initialFollowers;
  const toggle = useMutation({
    mutationFn: async () => (await followSeller(market.slug, slug, !following)).data,
    onSuccess: (data) => {
      client.setQueryData(key, data);
      client.invalidateQueries({ queryKey: ["followed-sellers", market.slug] });
    },
  });

  return (
    <div className="flex flex-wrap items-center gap-3">
      <span className="text-body-sm text-secondary">{t("followers", { count: followers })}</span>
      <Button
        variant={following ? "outline" : "primary"}
        disabled={toggle.isPending || (isSignedIn && state.isLoading)}
        aria-pressed={following}
        onClick={() => (isSignedIn ? toggle.mutate() : toLogin())}
      >
        {following ? <Check className="size-4" /> : <Plus className="size-4" />}
        {following ? t("following") : t("follow")}
      </Button>
      {toggle.isError ? <span className="text-body-sm text-danger-text">{t("followError")}</span> : null}
    </div>
  );
}
