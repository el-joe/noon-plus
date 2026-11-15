import { z } from "zod";
import { get, request } from "./client";
import { money, requiredContract } from "./schemas";

/*
 * Account extras (WP9 part B): disputes (DisputeController), warranty claims
 * (WarrantyClaimController) and pending contracts (ContractApiController@mine).
 */

const threadMessage = z.object({ from: z.string(), body: z.string(), at: z.string() });
export type ThreadMessage = z.infer<typeof threadMessage>;

export const DISPUTE_REASONS = ["not_received", "item_damaged", "not_as_described", "missing_parts", "other"] as const;

export const dispute = z.object({
  number: z.string(),
  shipment: z.string().nullable(),
  status: z.string(),
  reason: z.string(),
  resolution: z.string().nullable(),
  refund: money.nullable(),
  created_at: z.string(),
  messages: z.array(threadMessage).optional(),
});
export type Dispute = z.infer<typeof dispute>;

export const getDisputes = (market: string) => get(market, "disputes", z.array(dispute));
export const getDispute = (market: string, number: string) => get(market, `disputes/${encodeURIComponent(number)}`, dispute);
export const openDispute = (market: string, body: { shipment: string; reason: string; message: string }) =>
  request(market, "disputes", dispute, { method: "POST", body });
export const postDisputeMessage = (market: string, number: string, body: string) =>
  request(market, `disputes/${encodeURIComponent(number)}/messages`, dispute, { method: "POST", body: { body } });

export const warrantyClaim = z.object({
  number: z.string(),
  item: z.string().nullable(),
  coverage: z.string(),
  status: z.string(),
  outcome: z.string().nullable(),
  created_at: z.string(),
  messages: z.array(threadMessage).optional(),
});
export type WarrantyClaim = z.infer<typeof warrantyClaim>;

export const getWarrantyClaims = (market: string) => get(market, "warranty-claims", z.array(warrantyClaim));
export const getWarrantyClaim = (market: string, number: string) => get(market, `warranty-claims/${encodeURIComponent(number)}`, warrantyClaim);
export const submitWarrantyClaim = (market: string, body: { order_item_id: number; description: string }) =>
  request(market, "warranty-claims", warrantyClaim, { method: "POST", body });
export const postWarrantyMessage = (market: string, number: string, body: string) =>
  request(market, `warranty-claims/${encodeURIComponent(number)}/messages`, warrantyClaim, { method: "POST", body: { body } });

// ContractApiController@mine — contracts still waiting for the customer's consent.
export const myContracts = z.object({
  consent_required: z.boolean(),
  contracts: z.array(requiredContract.partial({ version_id: true, version: true })),
});
export type MyContracts = z.infer<typeof myContracts>;
export const getMyContracts = (market: string) => get(market, "me/contracts", myContracts);
