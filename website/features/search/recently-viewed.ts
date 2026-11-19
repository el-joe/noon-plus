"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useRef } from "react";
import type { ProductCardData } from "@/components/product/product-card";
import * as discovery from "@/lib/api/discovery";
import type { RecentCard } from "@/lib/api/discovery";
import { cardFromApi } from "@/lib/api/mappers";
import { useLocalStorage, useSession } from "@/lib/hooks";
import { useMarket } from "@/lib/market-context";

/** Device list key (per country DB); kept for guests and merged after sign-in. */
export const recentKey = (country: string) => `nawy.recent.${country}`;
const syncedKey = (country: string, user: number | string) => `nawy.recent.synced.${country}.${user}`;
const RECENT_MAX = 12;

export type RecentEntry = ProductCardData & { viewedAt?: string | null };

const recentQueryKey = (market: string) => ["recently-viewed", market] as const;

function readDevice(country: string): RecentEntry[] {
  try {
    return JSON.parse(window.localStorage.getItem(recentKey(country)) ?? "[]") as RecentEntry[];
  } catch {
    return [];
  }
}

function writeDevice(country: string, list: RecentEntry[]) {
  try {
    window.localStorage.setItem(recentKey(country), JSON.stringify(list.slice(0, RECENT_MAX)));
  } catch {
    // Storage disabled.
  }
}

function fromServer(card: RecentCard): RecentEntry {
  return { ...cardFromApi(card), viewedAt: card.viewed_at ?? null };
}

/**
 * Recently viewed (QC-043). Guests keep the list on this device. Signed in,
 * the device list is merged once into `me/recently-viewed` (newest view
 * wins on the server), then the account list is shown on every device.
 */
export function useRecentlyViewed() {
  const market = useMarket();
  const client = useQueryClient();
  const { user, isSignedIn } = useSession();
  const [device, setDevice] = useLocalStorage<RecentEntry[]>(recentKey(market.country.code), []);
  const syncing = useRef(false);

  const server = useQuery({
    queryKey: recentQueryKey(market.slug),
    enabled: isSignedIn,
    staleTime: 60_000,
    queryFn: async () => {
      const userId = user?.id ?? user?.email ?? "me";
      const flag = syncedKey(market.country.code, userId);
      let alreadySynced = false;
      try {
        alreadySynced = window.localStorage.getItem(flag) === "1";
      } catch {
        // ignore
      }
      const local = readDevice(market.country.code).filter((p) => p.slug);
      if (!alreadySynced && local.length > 0 && !syncing.current) {
        syncing.current = true;
        try {
          const items = local.slice(0, 50).map((p) => ({ slug: p.slug!, viewed_at: p.viewedAt ?? null }));
          const merged = (await discovery.syncRecentlyViewed(market.slug, items)).data;
          window.localStorage.setItem(flag, "1");
          return merged.map(fromServer);
        } finally {
          syncing.current = false;
        }
      }
      try {
        window.localStorage.setItem(flag, "1");
      } catch {
        // ignore
      }
      return (await discovery.getRecentlyViewed(market.slug)).data.map(fromServer);
    },
  });

  const clear = useMutation({
    mutationFn: async (slug?: string) => {
      if (isSignedIn) await discovery.clearRecentlyViewed(market.slug, slug);
    },
    onMutate: (slug?: string) => {
      const next = slug ? readDevice(market.country.code).filter((p) => p.slug !== slug) : [];
      setDevice(next);
      client.setQueryData(recentQueryKey(market.slug), (old: RecentEntry[] | undefined) => (slug ? old?.filter((p) => p.slug !== slug) : []));
    },
    onSettled: () => client.invalidateQueries({ queryKey: recentQueryKey(market.slug) }),
  });

  const items = isSignedIn ? (server.data ?? device) : device;
  return { items, clear: (slug?: string) => clear.mutate(slug), isSignedIn };
}

/** Record a product view (PDP): this device always; the account too when signed in. */
export function useRecordView(entry: RecentEntry | null) {
  const market = useMarket();
  const client = useQueryClient();
  const { isSignedIn } = useSession();
  const slug = entry?.slug ?? null;
  const latest = useRef(entry);
  latest.current = entry;

  useEffect(() => {
    const current = latest.current;
    if (!slug || !current) return;
    const viewedAt = new Date().toISOString();
    const list = readDevice(market.country.code);
    writeDevice(market.country.code, [{ ...current, viewedAt }, ...list.filter((p) => p.slug !== slug)]);
  }, [market.country.code, slug]);

  useEffect(() => {
    if (!slug || !isSignedIn) return;
    void discovery
      .syncRecentlyViewed(market.slug, [{ slug, viewed_at: new Date().toISOString() }])
      .then((r) => client.setQueryData(recentQueryKey(market.slug), r.data.map(fromServer)))
      .catch(() => undefined);
  }, [client, isSignedIn, market.slug, slug]);
}
