"use client";

import { createContext, useContext, useState, type ReactNode } from "react";
import type { Offer } from "@/lib/api/schemas";

type Selected = { offer: Offer | null; setOffer: (offer: Offer | null) => void; others: Offer[]; setOthers: (offers: Offer[]) => void; choose: (listingId: number) => void; setChoose: (fn: (listingId: number) => void) => void };

const SelectedOfferContext = createContext<Selected | null>(null);

/**
 * The offer chosen in the buy box (variant + seller), shared with the PDP
 * right rail (Figma 24:2: delivery and seller cards sit in the rail).
 */
export function SelectedOfferProvider({ initial, children }: { initial: Offer | null; children: ReactNode }) {
  const [offer, setOffer] = useState<Offer | null>(initial);
  const [others, setOthers] = useState<Offer[]>([]);
  const [chooser, setChooser] = useState<{ fn: (listingId: number) => void }>({ fn: () => undefined });
  return (
    <SelectedOfferContext.Provider value={{ offer, setOffer, others, setOthers, choose: chooser.fn, setChoose: (fn) => setChooser({ fn }) }}>{children}</SelectedOfferContext.Provider>
  );
}

export function useSelectedOffer(): Selected | null {
  return useContext(SelectedOfferContext);
}
