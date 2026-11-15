"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Check, Plus } from "lucide-react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import * as api from "@/lib/api/classifieds";
import { useSession } from "@/lib/hooks";
import { useHref, useMarket } from "@/lib/market-context";

/**
 * Follow a shop or a marketer (creator / broker): `GET follows?type=&slug=`
 * reads the state for signed-in customers, `POST` / `DELETE follows`
 * toggles it. Guests are sent to sign in.
 */
export function FollowButton({ slug, type = "shop", initialFollowers = null }: { slug: string; type?: api.FollowType; initialFollowers?: number | null }) {
  const t = useTranslations("classifieds.shop");
  const market = useMarket();
  const href = useHref();
  const router = useRouter();
  const client = useQueryClient();
  const { isSignedIn } = useSession();
  const key = ["follow", market.slug, type, slug] as const;
  const state = useQuery({
    queryKey: key,
    enabled: isSignedIn,
    staleTime: 60_000,
    queryFn: async () => (await api.getFollow(market.slug, slug, type)).data,
  });
  const following = state.data?.following ?? false;
  const followers = state.data?.followers ?? initialFollowers;
  const toggle = useMutation({
    mutationFn: async () => (await api.followShop(market.slug, slug, !following, type)).data,
    onSuccess: (data) => client.setQueryData(key, data),
  });

  return (
    <div className="flex items-center gap-2">
      {followers !== null ? <span className="text-body-sm text-secondary">{t("followers", { count: followers })}</span> : null}
      <Button
        variant={following ? "outline" : "primary"}
        disabled={toggle.isPending || (isSignedIn && state.isLoading)}
        aria-pressed={following}
        onClick={() => (isSignedIn ? toggle.mutate() : router.push(`${href("/login")}?next=${encodeURIComponent(window.location.pathname)}`))}
      >
        {following ? <Check className="size-4" /> : <Plus className="size-4" />}
        {following ? t("following") : t("follow")}
      </Button>
    </div>
  );
}
