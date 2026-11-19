"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import { ApiError, fieldErrors, isSessionEnded } from "./api/client";

export { fieldErrors };
import * as api from "./api/endpoints";
import type { Cart, WishlistCard } from "./api/schemas";
import { useMarket } from "./market-context";
import { AUTH_HINT_COOKIE, cartHintCookie, readBrowserCookie } from "./session";

/** True after hydration — gates reads of cookies / localStorage. */
export function useMounted(): boolean {
  const [mounted, setMounted] = useState(false);
  useEffect(() => setMounted(true), []);
  return mounted;
}

export const queryKeys = {
  me: ["me"] as const,
  cart: (market: string) => ["cart", market] as const,
  categories: (market: string) => ["categories", market] as const,
  wishlist: (market: string) => ["wishlist", market] as const,
  addresses: (market: string) => ["addresses", market] as const,
};

/** The signed-in customer, or null for guests. */
export function useSession() {
  const market = useMarket();
  const mounted = useMounted();
  const hinted = mounted && readBrowserCookie(AUTH_HINT_COOKIE) === "1";
  const query = useQuery({
    queryKey: queryKeys.me,
    enabled: hinted,
    retry: false,
    staleTime: 5 * 60_000,
    queryFn: async () => {
      try {
        return (await api.getMe(market.slug)).data;
      } catch (error) {
        // 401 (expired), 403 account_suspended / wrong_token: the BFF has
        // dropped the session cookies, so this browser is signed out.
        if (isSessionEnded(error)) return null;
        throw error;
      }
    },
  });
  return {
    user: hinted ? (query.data ?? null) : null,
    isLoading: !mounted || (hinted && query.isLoading),
    isSignedIn: hinted && !!query.data,
  };
}

/** The cart of this market (guest cart via the proxy's cart cookie). */
export function useCart(options: { force?: boolean; paymentMethod?: string } = {}) {
  const market = useMarket();
  const mounted = useMounted();
  const { isSignedIn } = useSession();
  const hasGuestCart = mounted && readBrowserCookie(cartHintCookie(market.country.code)) === "1";
  return useQuery({
    queryKey: [...queryKeys.cart(market.slug), options.paymentMethod ?? null],
    enabled: mounted && (options.force || isSignedIn || hasGuestCart),
    queryFn: async () => (await api.getCart(market.slug, options.paymentMethod)).data,
  });
}

export function cartItemCount(cart: Cart | undefined | null): number {
  return (cart?.shipments ?? []).reduce((sum, s) => sum + s.items.reduce((n, i) => n + i.quantity, 0), 0);
}

/** Wrap a cart-changing call so every cart view updates from its response. */
export function useCartMutation<V>(fn: (market: string, vars: V) => Promise<{ data: Cart }>) {
  const market = useMarket();
  const client = useQueryClient();
  return useMutation({
    mutationFn: async (vars: V) => (await fn(market.slug, vars)).data,
    onSuccess: (cart) => {
      client.setQueriesData({ queryKey: queryKeys.cart(market.slug) }, cart);
      client.invalidateQueries({ queryKey: queryKeys.cart(market.slug) });
    },
  });
}

/**
 * Send a guest to sign-in and back to the current page. Reads the location at
 * click time (no usePathname, which would suspend prerendered shells).
 */
export function useLoginRedirect() {
  const market = useMarket();
  const router = useRouter();
  return () => {
    const here = typeof window === "undefined" ? `/${market.slug}` : `${window.location.pathname}${window.location.search}`;
    router.push(`/${market.slug}/login?next=${encodeURIComponent(here)}`);
  };
}

/** The signed-in customer's wishlist (product cards); empty for guests. */
export function useWishlist() {
  const market = useMarket();
  const { isSignedIn } = useSession();
  return useQuery({
    queryKey: queryKeys.wishlist(market.slug),
    enabled: isSignedIn,
    staleTime: 60_000,
    queryFn: async () => (await api.getWishlist(market.slug)).data,
  });
}

/**
 * Heart toggle for one product. Guests are sent to sign-in (and back);
 * signed-in customers add/remove with an optimistic wishlist update.
 */
export function useWishlistToggle(slug: string | null | undefined) {
  const market = useMarket();
  const client = useQueryClient();
  const toLogin = useLoginRedirect();
  const { isSignedIn } = useSession();
  const { data } = useWishlist();
  const saved = !!slug && !!data?.some((p) => p.slug === slug);
  const mutation = useMutation({
    mutationFn: async (add: boolean) => {
      if (!slug) return;
      if (add) await api.addToWishlist(market.slug, slug);
      else await api.removeFromWishlist(market.slug, slug);
    },
    onMutate: (add: boolean) => {
      if (!add) {
        client.setQueryData(queryKeys.wishlist(market.slug), (old: WishlistCard[] | undefined) => old?.filter((p) => p.slug !== slug));
      }
    },
    onSettled: () => client.invalidateQueries({ queryKey: queryKeys.wishlist(market.slug) }),
  });
  const toggle = () => {
    if (!slug) return;
    if (!isSignedIn) {
      toLogin();
      return;
    }
    mutation.mutate(!saved);
  };
  return { saved: mutation.isPending ? mutation.variables === true : saved, toggle, pending: mutation.isPending };
}

/** Saved addresses (server address book, G-W5). */
export function useAddresses() {
  const market = useMarket();
  const { isSignedIn } = useSession();
  return useQuery({
    queryKey: queryKeys.addresses(market.slug),
    enabled: isSignedIn,
    queryFn: async () => (await api.getAddresses(market.slug)).data,
  });
}

export function useSignOut() {
  const market = useMarket();
  const client = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      // Revokes this browser's token (POST auth/logout) and drops the cookies.
      await fetch(`/api/session?market=${market.slug}`, { method: "DELETE" });
    },
    onSettled: () => {
      client.clear();
      window.location.href = `/${market.slug}`;
    },
  });
}

/** Local (this-device) storage with a typed fallback; used for recently viewed. */
export function useLocalStorage<T>(key: string, fallback: T) {
  const [value, setValue] = useState<T>(fallback);
  useEffect(() => {
    try {
      const raw = window.localStorage.getItem(key);
      if (raw) setValue(JSON.parse(raw) as T);
    } catch {
      // Ignore unreadable storage.
    }
  }, [key]);
  const update = (next: T) => {
    setValue(next);
    try {
      window.localStorage.setItem(key, JSON.stringify(next));
    } catch {
      // Storage full or disabled: keep the in-memory value.
    }
  };
  return [value, update] as const;
}

/**
 * The message to show for a failed call. API messages are localised by the
 * backend (QC-028), so they are shown as returned. A throttled call (429) with
 * a `Retry-After` uses `throttled(seconds)` when given (see useErrorMessage).
 */
export function errorMessage(error: unknown, fallback: string, throttled?: (seconds: number) => string): string {
  if (error instanceof ApiError) {
    if (error.code === "network_error" || error.code === "api_unreachable") return fallback;
    if (error.status === 429 && throttled && error.retryAfter && error.retryAfter > 0) return throttled(error.retryAfter);
    const first = Object.values(error.fields)[0]?.[0];
    return first ?? error.message ?? fallback;
  }
  return fallback;
}

/** `errorMessage` bound to the common translations (fallback + 429 wait time). */
export function useErrorMessage() {
  const t = useTranslations("common");
  return (error: unknown, fallback?: string) => errorMessage(error, fallback ?? t("error"), (seconds) => t("tooManyAttempts", { seconds }));
}
