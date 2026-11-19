# Lifecycle Catalogue — State Machines
> M2 discovery set · v1.0 · 2026-11-14 · Owner: BA / PM
> Scope: every state machine listed in **MASTER_PLAN v3 ADR 7** (one class each, guarded, history with actor), in the same order and grouping. Sources: SRS (`old_project_overview.md`) §5–§15, §17; MASTER_PLAN §3, §8–§11, ADR 4, ADR 11, ADR 15; Spec §5–§9.
> Where the SRS gives no states, this catalogue **proposes** them (marked *proposed*) so that one class per machine can be built. Every machine writes a `*_status_history` row `{from, to, actor_type, actor_id, reason, meta, at}`.

---

## 0. Conventions

| Item | Convention |
|---|---|
| Actor | `customer`, `vendor` (owner/team), `marketer`, `agency`, `carrier` (supervisor), `agent` (delivery), `admin`, `system` (job/listener), `gateway` (webhook). |
| Guard | Condition checked inside the machine before the transition (in addition to permission Policies). |
| Ledger | Indicative double-entry legs `Dr account / Cr account` (ADR 4). Final chart of accounts is an M8 deliverable. Every group has an idempotency key `{machine}:{id}:{transition}`. |
| Notify | Notification event (channels per `notification_templates`: DB, mail, broadcast, FCM, WhatsApp, SMS). |
| Job | Laravel scheduled job (MASTER_PLAN §11, SRS §17). All jobs are country-aware (run per country DB) and idempotent. |
| Terminal | States with no outgoing transitions are marked **(T)**. |

Indicative ledger accounts used below: `gateway_clearing`, `cod_receivable:agent`, `customer_wallet`, `seller_payable`, `marketer_payable_pending`, `marketer_payable_spendable`, `agent_payable`, `agency_payable`, `platform_revenue`, `platform_commission`, `vat_payable`, `refund_payable`, `gift_card_liability`, `promo_credit_liability`, `campaign_budget_reserved`, `campaign_fee_receivable`, `subsidy_expense`, `ad_revenue`, `subscription_revenue`, `storage_fee_revenue`, `warranty_revenue`, `bank_clearing`.

---

## 1. Catalog & listings

### 1.1 Listing (vendor, platform and marketer listings) — SRS §5.2–5.4
States: `draft`, `pending_review`, `active`, `paused`, `out_of_stock`, `rejected`, `archived` **(T)**. Platform listings use `active ⇄ paused`, `out_of_stock`, `archived` only (no review).

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| draft → pending_review (submit) | vendor / marketer | **FR-LST-1** ListingContractGate (category/product contract signed) and ListingCertificationGate (required certifications approved); marketer independent listing must bind to an active source listing (SRS §5.4); click-wrap signed | Notify admins (badge) |
| pending_review → active | admin | Product `active`; stock rules (FBN: `quantity_available > 0` in platform warehouse, **FR-LST-2**) | Search index upsert; notify owner |
| pending_review → rejected | admin | Reason required | Notify owner |
| rejected → pending_review (resubmit) | owner | Same as submit | — |
| active → paused / paused → active | owner, admin | Resume: stock > 0 | Campaigns on the item re-evaluated (`ListingStockChanged`) |
| active → out_of_stock | system | `quantity_available = 0` (**FR-LST-3**, movement written) | Pause campaigns below min stock; hide from buy box |
| out_of_stock → active | system | stock > 0 | Campaign resume check |
| any non-terminal → archived | owner, admin | No open sub-orders reserved on it (proposed) | Index delete |
| campaign listing follows campaign | system | Campaign `paused` / `done` / `active` | Campaign listing paused / archived / resumed with its campaign (SRS §5.4) |
Job: none timed; listener `ListingStockChanged`, hourly `MonitorCampaignStockJob` reacts.

### 1.2 Product status — SRS §5.1 (FR-CAT-1)
States: `draft`, `active`, `discontinued` **(T)**, `restricted`.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| draft → active | admin | Variants, category, per-country settings present; GTIN unique (N) | Listings may be created; index |
| active → restricted | admin | Reason (legal/compliance) | All listings paused; buy box hidden; notify sellers |
| restricted → active | admin | — | Listings resume if eligible |
| active / restricted → discontinued | admin | — | Listings archived; open campaigns → `done` (stock rule) |

### 1.3 Product request — SRS §5.1 (FR-CAT-2)
Implemented: `ProductRequestStatus`, `App\Domains\Catalog\Support\ProductRequests` (2026-11-19).
States: `pending`, `approved` **(T)**, `rejected` **(T)**, `cancelled` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (create) → pending | vendor / marketer | Product not already in catalog (GTIN/name check) | Notify admins |
| pending → approved | admin | — | Creates the product (draft or active); notify requester |
| pending → rejected | admin | Reason | Notify requester |
| pending → cancelled | requester | — | — |

### 1.4 Product certification — SRS §4.1.5, §13.7
States *(SRS: approve/reject; expiry proposed)*: `pending`, `approved`, `rejected`, `expired` *(proposed)*.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (upload) → pending | vendor | File type/size valid; private disk | Notify admins (badge) |
| pending → approved / rejected | admin | Reason on reject | Unblocks ListingCertificationGate / notify |
| approved → expired *(proposed)* | system | `valid_until < today` | Dependent listings paused; notify vendor |
| rejected / expired → pending | vendor | New file | — |
Job *(proposed)*: daily certification-expiry check.

---

## 2. Orders

### 2.1 Master order (roll-up) — SRS §7.2
Derived, never set directly. States: `placed`, `confirmed`, `partially_shipped`, `shipped`, `partially_delivered`, `delivered`, `completed` **(T)**, `cancelled` **(T)**, `refunded` **(T)**, `disputed`.

| Rule (evaluated after every sub-order transition) | Result |
|---|---|
| All sub-orders `completed` | `completed` |
| All sub-orders `cancelled` or `refunded` | `cancelled` (or `refunded` if any refund) |
| Some shipped, some not | `partially_shipped` |
| All shipped/out_for_delivery | `shipped` |
| Some delivered, some not | `partially_delivered` |
| All delivered | `delivered` |
| Any open dispute | `disputed` (overlay; reverts when resolved) |
Side effects: customer notifications on milestone change; history row with actor `system`.

### 2.2 Sub-order — SRS §7.1 (OrderStateMachine)
Checkout creates one sub-order per seller; a seller group mixing FBN and seller-shipped items becomes **two sub-orders** (owner decision D17, 2026-11-19): the warehouse parcel (`fulfilled_by = nawy`, advanced only by warehouse staff through Pick & pack) and the seller's parcel. Each runs this lifecycle, its shipment, returns, refunds and settlement on its own.
States: `placed`, `confirmed`, `processing`, `packed`, `shipped`, `out_for_delivery`, `delivered`, `completed` **(T)**, `cancelled` **(T)**, `returned`, `refunded` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| placed → confirmed | vendor, admin, system (platform/FBN) | Payment `authorized/captured` or COD | Items `fulfillment_status`; notify customer |
| confirmed → processing → packed | vendor, admin, warehouse | — | Print label; shipment `label_created` |
| packed → shipped | vendor, admin, carrier | Shipment picked up | Event `SubOrderShipped`; notify |
| shipped → out_for_delivery | system (from assignment) | Assignment `picked_up` | Notify with delivery code |
| out_for_delivery → delivered | agent (code match), admin | Assignment `delivered` (ADR 15) | Event `SubOrderDelivered`; return-window clock starts; COD → `cod_receivable:agent`; ledger capture for COD: Dr `cod_receivable:agent` / Cr `seller_payable`, `platform_commission`, `vat_payable` |
| delivered → completed | system **AutoCompleteOrdersJob 02:00** | Return window passed; no open return/dispute | Unlocks payout eligibility (COD also needs remittance) and conversion approval |
| placed/confirmed/processing/packed → cancelled | customer (before shipped) | Not shipped | Release inventory; refund starts; conversion reversed (§4.3); notify |
| any pre-delivered → cancelled | vendor | — | Same + **seller score penalty / strike** |
| any → cancelled | admin (force) | Reason | Same; shipment cancelled. Built for every pre-completion state including `delivered` (QC-034): a delivered force-cancel refunds and cancels warranty plans but puts no stock back (the parcel is with the customer; a return restocks it). `completed`, `returned`, `refunded` stay closed |
| shipped → delivered *(built exception, QC-034)* | admin, system | No Nawy agent assignment (third-party carrier hand-over or admin confirmation) | Same as delivered. Agents always go through `out_for_delivery`; sellers and customers cannot take this move |
| delivered → returned | system (return `received`) | Return approved and received | Event `SubOrderReturned`; restock per item |
| returned → refunded | system (refund `completed`) | — | Conversion reversed; payout line negative |
Ledger at placement (card): Dr `gateway_clearing` / Cr `seller_payable` (net), `platform_commission`, `vat_payable`, shipping income. Warranty plan: Cr `warranty_revenue`.

### 2.3 Shipment — SRS §8 (+N reschedule/RTO)
States: `label_created`, `picked_up`, `in_transit`, `out_for_delivery`, `delivered` **(T)**, `failed`, `rescheduled` (N), `returning_to_origin` (N), `returned` **(T)**, `cancelled` **(T)** *(proposed)*.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| label_created → picked_up | agent / carrier | Pickup scan (app) | Sub-order `shipped`; tracking event |
| picked_up → in_transit → out_for_delivery | carrier / system | — | Tracking events; customer notify |
| out_for_delivery → delivered | agent | Assignment code matched | Sub-order `delivered` |
| out_for_delivery → failed | agent | Failure reason required (N) | Notify customer; supervisor queue |
| failed → rescheduled → out_for_delivery | agent / supervisor / customer | New slot within policy (max attempts setting) | ETA recomputed with country calendar |
| failed → returning_to_origin → returned | supervisor / system | Max attempts reached or customer refused | Sub-order cancelled/returned; carrier claim possible |

### 2.4 Delivery assignment — SRS §4.6, §8; ADR 15
States: `assigned`, `accepted`, `declined` *(N, app 11)*, `picked_up`, `delivered` **(T)**, `failed` **(T)**, `reassigned` **(T)** *(proposed)*.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (auto/manual) → assigned | system / admin / carrier supervisor | Agent active, available, in zone | 4-digit **delivery code generated** and shown in agent app + customer app/email; SLA timer starts |
| assigned → accepted / declined | agent | — | Declined → back to unassigned queue |
| accepted → picked_up | agent | Pickup at vendor (FBM) or FBN warehouse; scan | Shipment `picked_up` |
| picked_up → delivered | agent | **Customer reads code aloud; agent taps "Codes match"** (agent never types it); proof photo or signature if COD or above `delivery_proof_required_above`; COD amount recorded | Shipment/sub-order `delivered`; agent earning line Dr `platform_delivery_cost` / Cr `agent_payable`; COD: Dr `cod_receivable:agent` |
| picked_up → failed | agent | Reason | Shipment `failed`; reschedule/RTO path |
| assigned/accepted → reassigned | supervisor / admin | — | New assignment with **new code** |
Job: **CheckSlaBreachJob every 15 min** (flags breaches, notifies supervisor/admin; scorecard input).

### 2.5 Return — SRS §7.4
States: `requested`, `approved`, `rejected` **(T)**, `awaiting_pickup`, `in_transit`, `received`, `inspecting`, `completed` **(T)**, `cancelled` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (create) → requested | customer | Sub-order `delivered`, within category return window; custom/made-to-order items excluded by rule | Notify vendor/admin |
| requested → approved / rejected | vendor, admin | Reason on reject | Notify; pickup arranged |
| approved → awaiting_pickup → in_transit → received | carrier / agent / system | — | Reverse shipment |
| received → inspecting → completed | admin / vendor / warehouse | Outcome per item: good → full refund; customer-damaged → partial/none; transit-damaged → full refund + carrier investigated; restock decision per item | Creates refund (§2.6); restock movement; carrier claim (§6.4) when transit damage |
| requested/approved → cancelled | customer | Before pickup | — |

### 2.6 Refund — SRS §7.5
States: `pending`, `approved`, `processing`, `completed` **(T)**, `failed`, `rejected` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (create) → pending | system (cancel, return, dispute) / admin | Amount ≤ captured − refunded | — |
| pending → approved / rejected | admin (finance), system for auto rules | — | — |
| approved → processing | system | — | Card: gateway refund call (idempotent); wallet: instant; COD: to customer wallet |
| processing → completed | gateway webhook / system | Verified | Ledger reversal: Dr `seller_payable`, `platform_commission`, `vat_payable` / Cr `gateway_clearing` or `customer_wallet`; conversion reversed or **clawback** if paid (§4.3); order payment `refunded/partially_refunded` |
| processing → failed → processing (retry) | system / admin | Retry policy | Alert finance |

### 2.7 Dispute — SRS §7.6
States: `open`, `seller_responded`, `under_review`, `escalated`, `resolved`, `closed` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (create) → open | customer | Order exists, no duplicate open dispute | Master order overlay `disputed`; notify vendor |
| open → seller_responded | vendor | — | Notify customer |
| open/seller_responded → under_review | admin (assign) | — | — |
| under_review → escalated | admin | — | — |
| under_review/escalated → resolved | admin | Resolution `favor_customer / favor_seller / split / no_action` | Refund (§2.6) for customer/split; strike possible |
| resolved → closed | system / admin | — | Overlay removed |

### 2.8 Warranty claim — SRS §7.7 (states *proposed*; SRS gives outcomes only)
States: `submitted`, `under_review`, `approved`, `rejected` **(T)**, `in_repair`, `replacement_sent`, `refund_issued`, `resolved`, `closed` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (create) → submitted | customer | Within standard warranty (vendor) or active extended plan (platform) | Thread opened (customer, vendor, admin); vendor sees only own listings |
| submitted → under_review → approved / rejected | vendor (standard), admin (extended) | Reason on reject | Notify |
| approved → in_repair / replacement_sent / refund_issued | vendor / admin | Outcome repair / replace / refund | Refund via §2.6; replacement creates zero-priced shipment |
| → resolved → closed | admin / system | — | — |
Job: **ExpireWarrantyPurchasesJob daily 03:00** (extended plans `active → expired`).

### 2.9 Review — SRS §7.8
States: `pending`, `published`, `flagged`, `auto_flagged`, `hidden`, `rejected` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (create) → pending / auto_flagged | customer / system | Verified purchase (proposed); profanity/fraud rules → auto_flagged | — |
| pending → published / rejected | admin | — | Rating aggregates updated |
| published → flagged | customer/vendor report | — | Moderation queue |
| flagged / auto_flagged → published / hidden / rejected | admin | — | Aggregates updated |
Vendor reply: `pending → published / rejected` (admin moderates replies).

---

## 3. Money

### 3.1 Vendor payout — SRS §9
States: `pending`, `approved`, `processing`, `completed` **(T)**, `failed`, `on_hold`.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (generate) → pending | system **payout job** (weekly Mon 02:00 / biweekly 1st & 15th / monthly 1st per vendor schedule) | Eligible sub-orders: completed, or delivered past return window; **COD only if `cod_remittance_confirmed`** | Statement lines: price − category commission − fees − subsidy share − FBN fees − pending campaign fees − sample commissions (`SettlementCalculator`); notify vendor |
| pending → approved | admin (finance) | 4-eyes (proposed: not the generator) | — |
| pending → on_hold / on_hold → pending | admin | Reason (seller hold, dispute) | Notify vendor |
| pending → pending (recalculate) | admin | — | Lines rebuilt; history entry |
| approved → processing → completed | admin (process), bank confirmation | Verified bank account | Ledger: Dr `seller_payable` / Cr `bank_clearing`; campaign fee lines Dr `seller_payable` / Cr `campaign_fee_receivable` |
| processing → failed → processing | system / admin | — | Alert |

### 3.2 Wallet withdrawal — SRS §9 (marketer, agent, agency, vendor)
States: `pending`, `approved`, `processed` **(T)**, `rejected` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (request) → pending | wallet owner | Amount ≤ spendable; wallet not frozen; min amount (setting) | Ledger: spendable → reserved |
| pending → approved / rejected | admin | — | Reject: reserved → spendable |
| approved → processed | admin / bank | — | Dr `*_payable_spendable(reserved)` / Cr `bank_clearing` |

### 3.3 Delivery payout — SRS §9 ("generate → approve → process"; state names *proposed*)
States: `generated`, `approved`, `processing`, `paid` **(T)**, `failed`.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (generate) → generated | admin / system | Per-delivery rates + bonuses for the period | Lines per assignment |
| generated → approved | admin | — | — |
| approved → processing → paid | admin | — | Dr `agent_payable` / Cr `bank_clearing` (or agent wallet spendable) |

### 3.4 COD settlement — SRS §4.6, §8
States: `pending`, `settled` **(T)**, `disputed`.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (generate) → pending | system **GenerateCodSettlementsJob daily 23:30** | Delivered COD assignments not yet settled | Aggregates agent/carrier cash |
| pending → settled | admin (finance) | Cash/bank remittance matches | `cod_remittance_confirmed = true` on sub-orders → **vendor payout unlocks**; Dr `bank_clearing` / Cr `cod_receivable:agent`; vendor amber notice removed |
| pending → disputed → pending / settled | admin / agent | Variance reason | Shortfall to agent/carrier claim (proposed) |

### 3.5 Storage-fee invoice (FBN) — SRS §8, §4.1.3 (states *proposed*)
States: `accruing`, `issued`, `deducted` **(T)**, `paid` **(T)**, `waived` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| daily fee rows | system **FBN overage daily 01:00**; **flag overstored unpaid 01:15** | Free storage days passed (default 30) | `storage_fee_daily` rows |
| accruing → issued | system **monthly 1st 07:00** | Month closed | Invoice; notify vendor |
| issued → deducted | system (payout generation) | Payout available | Payout line; Dr `seller_payable` / Cr `storage_fee_revenue` |
| issued → paid / waived | vendor (wallet) / admin | — | Ledger accordingly |

### 3.6 Subscription (vendor plan) — SRS §9
Implemented: `VendorSubscriptionStatus`, `App\Domains\Finance\Support\VendorSubscriptions` (2026-11-19). Related machine: **acquisition commission** `pending → approved → paid | rejected` (`AcquisitionCommissionStatus`, four-eyes: the acquirer never approves or pays their own).
States: `trialing`, `active`, `past_due`, `cancelled` **(T)**, `expired` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (subscribe) → trialing / active | vendor, admin | Plan priced in country currency | Invoice; Dr `seller_payable` or gateway / Cr `subscription_revenue` |
| trialing → active | system | Trial end + payment | — |
| active → past_due | system **`nawy:subscriptions:bill` (daily 02:15)** | Invoice unpaid after its due date (issued + `subscription.grace_days`) | Notify (mail + panel); listings cannot be submitted, plan listing cap |
| past_due → active | payment | — | — |
| past_due → cancelled / expired | system / admin | A further `subscription.grace_days` unpaid (final notice the day before) / admin cancel | Open invoices voided (or deducted from payout when `subscription.deduct_unpaid_from_payout`) |
| active → cancelled | vendor / admin | — | End of period |
Nawy One membership uses the same pattern (see Appendix A).

---

## 4. Marketing

### 4.1 Marketer campaign — SRS §11.5
States: `pending_admin`, `marketer_requested`, `active`, `auto_approved`, `paused`, `done` **(T)**, `cancelled` **(T)**, `rejected` **(T)**, legacy `completed` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (create A/B/C) → pending_admin | vendor / agency / admin | Ownership; allowed fulfilment model; product stock after samples ≥ `min_stock_for_campaign`; budget blank (→ end date required) or > influencer fixed total; collaboration contract signed | Fee snapshot; influencer fixed reserved; samples planned; item `campaign_enabled = true`; notify admins + owner; **ProcessCampaignAutoApproveJob** scheduled |
| pending_admin → pending_admin (edit) | owner | Title, notes, ad types, end date, budget | Re-snapshot fee |
| pending_admin → active | admin | — | Invitations dispatched; **campaign fee charged** (wallet else `pending`) — Dr owner wallet or `campaign_fee_receivable` / Cr `platform_revenue` (fee); budget reserved Dr owner / Cr `campaign_budget_reserved` (proposed) |
| pending_admin → auto_approved | system **ProcessCampaignAutoApproveJob** (delayed, `marketer_campaign_auto_approve_hours` 36) | Still pending | Same as active |
| pending_admin → rejected | admin | Reason | Pending fee voided; reservations released; undispatched samples cancelled |
| pending_admin → cancelled | owner | — | Same cleanup |
| (request D) → marketer_requested | marketer | Listing eligible | Notify listing owner |
| marketer_requested → active | owner (vendor `approve-request` / admin) | Owner sets budget > 0 | Ownership flips; fee snapshot (vendors); requester invitation dispatched + auto-accepted; fee charged |
| marketer_requested → rejected | owner | — | Notify requester |
| (peer E) → active | system | `assertPeerPromotionAllowed` (both influencers, own FBN listing, stock ≥ `marketer_peer_campaign_min_stock`, 1 per promoter-owner per month, row lock) | No fee; proxy listing; owner notified |
| active/auto_approved → paused | system / owner | Stock < min, item paused, or owner pause (`owner_paused_at`) | No new attributions; marketer listings paused |
| paused → active | system / owner | Stock ≥ min, item active, budget left, before end date, not owner-paused (owner lifts own pause) | Listings resumed |
| active/auto_approved/paused → done | system **MonitorCampaignStockJob (hourly)**, `ListingStockChanged`, **CompleteEndedMarketerCampaignsJob 04:00**, budget exhausted, classified sold, trip sold out/expired/departed | — | Pending invitations cancelled; listings archived; **influencers settled** net of fee into spendable (idempotent `influencers_settled_at`): Dr `seller_payable`/`platform_revenue` / Cr `marketer_payable_spendable`, `platform_commission`; notify all |
| active → cancelled | owner (`cancel-active`) | — | Influencers **not** paid; reserved budget released; unpaid fee voided; paid fee per `campaign_fee_refund_policy` (default none) |

### 4.2 Invitation — SRS §11.7
States: `pending`, `accepted`, `rejected` **(T)**, `timed_out` **(T)**, `cancelled` **(T)**; flag `replaced_at`.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (dispatch) → pending | system | Campaign active; marketer active | Fresh 10-char code, `/r/{code}`, QR; influencer fixed copied (`reserved`); sample row; in-app + **WhatsApp signed link**; **ProcessInvitationTimeoutJob** (delayed, 12 h) |
| pending → accepted | marketer (panel or signed link) | **FR-MKT-1** active + current contract accepted | Marketer listing with code; notify owner (in-app + WhatsApp) |
| pending → rejected | marketer | Reason | **Auto-replacement** search (same type, active, same country, never invited, ≥ min accepted, budget fit for influencers; ordered by fewest invites in 30 days then fewest accepted); reservation released; sample handed over or cancelled; notifications to marketer, owner, admins |
| pending → timed_out | system ProcessInvitationTimeoutJob | `expires_at` passed | Same replacement flow |
| pending/accepted → cancelled | system | Campaign done/cancelled/rejected | Reservation released if not settled |
Resend: admin, cooldown `marketer_whatsapp_resend_cooldown_minutes` (10).

### 4.3 Conversion — SRS §11.11, MP §8 (clawback N)
States: `pending`, `approved`, `released` *(spendable; proposed name)*, `paid` *(in a withdrawal; proposed)*, `reversed` **(T)**, `clawback_pending` (N), `recovered` **(T)** (N).

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (record) → pending | system `CampaignSaleRecorder` under campaign row lock | One conversion per source (unique); attribution per configured model | Gross/fee/net snapshot; budget consumed (last commission shortened); flash-sale bonus if live |
| pending → approved | system **ApproveMarketerConversionsJob 03:15** | product_sale: sub-order delivered/completed and return window passed; classified_sale: `marketer_classified_approval_days` (2) after sale; travel_booking: booking completed; classified_inquiry never auto-approved | Net → marketer **pending** balance |
| approved → released | system **ReleaseMarketerPendingCommissionJob 03:30** | `marketer_payout_clearing_days` passed | Dr `seller_payable` (vendor) or `platform_revenue` (platform campaign) gross / Cr `marketer_payable_spendable` net, `platform_commission` fee |
| pending/approved/released → reversed | system | Cancel, return, booking cancel, classified un-sold | Wallet taken back (pending or spendable); ledger mirrored; budget freed (campaign not reopened) |
| released → paid *(built, QC-035)* | system (withdrawal processed) | The marketer's processed withdrawals cover the conversion, oldest released first (spendable money is pooled, so FIFO is the attribution); `paid_at` set | — |
| paid → reversed / clawback_pending (N) | system | Reversal after payout: taken from spendable first, the shortfall goes to clawback | Negative balance / recovery entry; notify marketer |
| clawback_pending → recovered | system **clawback recovery job** | Next earnings or manual settlement | Ledger recovery |

### 4.4 Sample — SRS §11.9 (states *proposed*)
States: `planned`, `awaiting_address`, `address_submitted`, `dispatched`, `delivered`, `received` **(T)**, `cancelled` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (create) → planned | system | Platform sample at campaign create; influencer sample at invitation dispatch; affiliate row if targeted | Quantity from category (≥ minimums) |
| planned → awaiting_address → address_submitted | system / marketer | Invitation accepted | — |
| address_submitted → dispatched → delivered | admin | Stock available | Stock leaves like a sale (inventory movement); **zero-priced commission line** on seller's next statement |
| delivered → received | marketer | — | — |
| planned/awaiting_address → cancelled / handed over | system | Campaign rejected/cancelled/done, or replacement | — |

### 4.5 Coupon invitation (participation invitation) — SRS §10 (states *proposed* around SRS rules)
States: `draft`, `open`, `closed`, `active`, `ended` **(T)**, `cancelled` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| draft → open | admin | Min fee, capacity, expiry set | Notify eligible vendors/marketers |
| open → closed | system **`coupons:close-expired-participation-invitations` every 15 min** / capacity reached | — | — |
| closed → active | system **daily 00:30 (activate fulfilled coupon invitations)** | ≥ 1 paid participant | Coupon live for participants |
| active → ended | system **coupon expiry 00:15** | Coupon expired | — |
| open → cancelled | admin | — | Paid fees refunded |

### 4.6 Coupon participation request — SRS §10
States: `requested`, `approved_pending_payment` *(proposed)*, `paid`, `rejected` **(T)**, `refunded` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (apply) → requested | vendor / marketer | Offered fee ≥ min; capacity left | — |
| requested → paid | admin | Wallet covers fee | Dr participant wallet / Cr `platform_revenue` |
| requested → approved_pending_payment → paid | admin | Bank transfer proof confirmed | Dr `bank_clearing` / Cr `platform_revenue` |
| requested/paid → rejected / refunded | admin | — | A paid fee is refunded to wallet |

### 4.7 Ad campaign (self-serve) — SRS §10
States: `draft`, `pending_review`, `active`, `paused`, `budget_exhausted`, `ended` **(T)**, `rejected` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| draft → pending_review | vendor (advertiser) | Budget, targeting set; wallet/billing valid | Notify admins |
| pending_review → active / rejected | admin | — | `SponsoredRanker` eligibility |
| active ⇄ paused | advertiser, admin | — | — |
| active → budget_exhausted | system (spend ≥ daily/total budget) | — | Resumes after **ResetAdCampaignDailyBudgetJob 00:00** if daily cap only |
| active → ended | system / advertiser | End date | — |
Spend ledger: Dr advertiser wallet / Cr `ad_revenue` per click/impression batch. Fraud patterns blocked (invalid clicks not billed).

### 4.8 Ad booking (Nawi Ads) — SRS §10
States: `draft`, `pending_review`, `approved`, `scheduled`, `active`, `paused`, `completed` **(T)**, `rejected` **(T)**, `cancelled` **(T)**, `expired` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| draft → pending_review | vendor / marketer | Slot availability (≤ max concurrent), dates, creative (image by advertiser; **video by admin**, ADR 11), payment | Dr wallet / Cr `ad_revenue` (deferred, proposed) |
| pending_review → approved / rejected | admin | Creative reviewed separately | Reject → refund |
| approved → scheduled → active | system **PaidAdSchedulerJob every 5 min** | Start reached | Placement live |
| active ⇄ paused | admin / advertiser | — | — |
| active → completed | system | End reached | — |
| draft/pending → cancelled / expired | advertiser / system | Start passed without approval | Refund |

### 4.9 Ad-package subscription — SRS §11.13
States: `pending`, `active`, `expired` **(T)**, `rejected` **(T)**, `cancelled` **(T)** *(proposed)*.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (subscribe, wallet) → active | marketer | Wallet covers price + VAT | Dr wallet / Cr `ad_revenue`, `vat_payable` |
| (subscribe, bank transfer) → pending | marketer | Proof uploaded | — |
| pending → active / rejected | admin | Proof verified | Ledger on approve |
| active → expired | system **`ad-packages:expire` hourly** | `expires_at` passed | Features removed |

### 4.10 Flash-sale event — SRS §10
States: `draft`, `submission_open`, `submission_closed`, `under_review`, `approved`, `live`, `ended` **(T)**, `cancelled` **(T)**.
Marketer invite sub-machine: `pending → accepted | declined` (implemented: `FlashSaleInviteStatus`, 2026-11-19).

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| draft → submission_open | admin / system | Window set (country local time + Oman label) | Invite vendors and marketers |
| submission_open → submission_closed → under_review | system **TransitionFlashSaleStatusJob / FlashSaleSchedulerJob every 5 min** | Window times | — |
| under_review → approved | admin | Submissions reviewed | — |
| approved → live | system (every 5 min) | Start time | Prices applied; countdown; marketer bonus active |
| live → ended | system | End time | **Prices revert automatically** |
| any pre-live → cancelled | admin | — | Submissions withdrawn |

### 4.11 Flash-sale submission — SRS §10
States: `draft`, `submitted`, `under_review`, `approved`, `rejected` **(T)**, `live`, `sold_out` **(T)**, `ended` **(T)**, `withdrawn` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| draft → submitted | vendor | Price **< original price**; within discount policy; requested inventory ≤ stock | — |
| submitted → under_review → approved / rejected | admin | — | Notify |
| approved → live | system (event live) | — | Listing price overridden |
| live → sold_out / ended | system | Allocated qty 0 / event ended | Price revert |
| draft/submitted/approved → withdrawn | vendor | Before live | — |

### 4.12 Gift card — SRS §10, Spec §6.2 (states *proposed*)
States: `pending_payment`, `scheduled` (email delivery), `active`, `partially_redeemed`, `redeemed` **(T)**, `expired` **(T)**, `cancelled` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (purchase) → pending_payment → active / scheduled | customer / gateway | Payment verified | Dr `gateway_clearing` / Cr `gift_card_liability`; code generated (never logged) |
| (admin issue) → active | admin | Value, currency, expiry | Dr `promo_expense` / Cr `gift_card_liability` |
| scheduled → active | system **gift-card scheduled email delivery job** | Send time reached | Email to recipient |
| active → partially_redeemed → redeemed | customer at checkout | Currency = order currency; country match | Dr `gift_card_liability` / Cr order payment |
| active/partially_redeemed → expired | system **daily 00:30** | `expires_at` passed | Breakage per policy |
| active → cancelled; extend; adjust balance | admin | Reason | Ledger adjustment |

---

## 5. Classifieds & travel

### 5.1 Classified listing (incl. property) — SRS §12.1
States: `draft`, `pending_contract`, `pending_review`, `active`, `paused`, `sold` **(T)**, `expired`, `rejected` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| draft → pending_contract | seller | Category requires a seller contract | — |
| pending_contract → pending_review | seller | **FR-CLS-1** contract signed before submission | Notify admins |
| draft → pending_review | seller | No contract needed | — |
| pending_review → active / rejected | admin | **FR-CLS-6** attachments verified; **FR-CLS-2** never public before approval | Index; notify |
| active ⇄ paused | seller | — | Campaign paused/resumed |
| active → sold | seller (mark sold) | Live campaign → final sold price required; optional referring affiliate/broker + buyer note | `classified_sale` conversion; open campaigns → `done` (influencers paid) |
| sold → active (un-mark) | seller | Commission not yet paid out | Conversion reversed; campaign **not** reopened |
| active → expired | system **classified expiry job** | `expires_at` passed | Renewal reminder sent before (job) |
| expired → pending_review / active (renew) | seller | Renewal policy (paid or free) | `listing_renewals` row |
Boost/featured (N): overlay flag with its own period.

### 5.2 Travel package — SRS §12.2
States: `draft`, `pending_review`, `active`, `sold_out`, `expired` **(T)**, `rejected` *(proposed)*.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| draft → pending_review | agency | Departures, price, seats, inclusions, contract file | Notify admins |
| pending_review → active / rejected | admin | — | Index |
| active → sold_out | system | All departures 0 seats | Campaigns → done |
| sold_out → active | system | Seats freed (cancel) | — |
| active/sold_out → expired | admin / system | Last departure passed | Campaigns → done |

### 5.3 Booking — SRS §12.2 (+ seat hold N)
States: `held` (N), `pending_documents`, `confirmed`, `completed` **(T)**, `cancelled` **(T)**, `hold_expired` **(T)** (N). Modifications (N) are sub-records `requested → approved | rejected`.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (start) → held | customer | Seats available — **transactional allocation with row lock** | Seat hold timer (`travel_seat_hold_minutes`) |
| held → hold_expired | system **travel seat-hold expiry every minute** | Timer passed, unpaid | Seats released |
| held → pending_documents | customer + gateway | Payment verified | Dr `gateway_clearing` / Cr `agency_payable`, `platform_commission`; trip contract signed (customer ↔ agency) |
| pending_documents → confirmed | agency | Traveller documents complete | **Conversion created** if marketer code; voucher + invoice issued; notify |
| confirmed → completed | system **CompleteTravelBookingsJob 03:45** | Trip end date passed | Conversion approvable; operator settlement eligible |
| pending_documents/confirmed → cancelled | customer / agency / admin | Refund policy of the package | Seats released; refund; conversion reversed |
Same job retries pending agency campaign fees.

### 5.4 Inquiry — SRS §12.1 (classified), §12.2 (travel)
States: classified `new`, `contacted`, `closed` **(T)**; travel adds `converted` **(T)**. Real-estate leads: see Appendix A.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (create) → new | customer or guest | Throttle 5/min; **customer purchase contract gate** (`customer_contract_required`) | Stamp marketer/campaign/code only if click was for the same item; notify seller; `classified_inquiry` conversion (lead, never auto-approved) |
| new → contacted | seller / agency / broker | — | — |
| contacted → converted (travel) | agency | Booking created | — |
| any → closed | seller / agency / customer | — | — |

---

## 6. Logistics

### 6.1 FBN inbound — SRS §4.1.3, §8
States: `draft`, `submitted`, `approved`, `shipped`, `received` **(T)**, `rejected` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| draft → submitted | vendor | Items, quantities, warehouse; within vendor storage limit | Notify warehouse/admin |
| submitted → approved / rejected | admin | — | — |
| approved → shipped | vendor | — | ETA |
| shipped → received | warehouse / admin | Counted quantities | Inventory movements (receipt); **free-storage clock starts** |
Job: **FbnInboundReminderJob weekly**.

### 6.2 Inventory transfer — SRS §4.1.3
States: `draft`, `in_transit`, `received` **(T)**, `cancelled` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| draft → in_transit | admin / vendor | Source stock ≥ qty | Movement out (reserved) |
| in_transit → received | receiver | — | Movement in |
| draft/in_transit → cancelled | admin | — | Movement reversed |

### 6.3 Packaging order — SRS §4.1.3
Implemented: `PackagingOrderStatus`, `App\Domains\Fulfilment\Support\PackagingOrders` (2026-11-19); the "wallet or payout deduction" is a payout-statement deduction (stores have no separate wallet).
States: `pending`, `approved`, `shipped`, `delivered` **(T)**, `rejected` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (cart checkout) → pending | vendor | Unit cost snapshotted; delivery fee never subsidised | Wallet or payout deduction |
| pending → approved / rejected | admin | — | Reject → refund |
| approved → shipped → delivered | admin / carrier | — | — |

### 6.4 Carrier claim — SRS §4.1.11
States: `submitted`, `under_review`, `approved`, `rejected` **(T)**, `compensated` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (create) → submitted | vendor / admin / system (transit damage on return) | Shipment lost/damaged | — |
| submitted → under_review → approved / rejected | admin | — | Scorecard input |
| approved → compensated | admin / carrier | Amount agreed | Dr `carrier_payable` / Cr `seller_payable` or `customer_wallet` |

---

## 7. Applications & accounts

### 7.1 Vendor application — SRS §4.1.5, §4.2; MP §5a (states partly *proposed*)
States: `in_progress` (wizard), `submitted` (in review), `under_review`, `more_documents_requested`, `approved` **(T)**, `rejected` **(T)**, `abandoned` **(T)** *(proposed)*.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| in_progress (steps 1–5) | applicant | Step validation; slug unique; documents per country × type × category; **Seller Agreement signed (step 5)** | Funnel events per step |
| in_progress → submitted | applicant | All mandatory documents uploaded | Success page; notify admins; `application_audits` |
| submitted → under_review | admin ("start review" / "assign to me") | — | — |
| under_review → more_documents_requested | admin | Missing/rejected docs listed | Status page "More documents"; email |
| more_documents_requested → submitted | applicant | — | — |
| under_review → approved | admin | All mandatory docs `approved` | Vendor + `vendor:*` membership `active`; credentials email; status page "Approved" |
| under_review → rejected | admin | Reason | Status page "Rejected" with reason |
| in_progress → abandoned | system | Inactive > N days (proposed 30) | Reminder emails before |
Travel agency (2-step), marketer, broker and shipping-partner applications follow the same pattern through their onboarding-step builder definition (MP §7a).

### 7.2 Onboarding document — SRS §13.7
States: `pending`, `approved`, `rejected`, `expired` *(proposed)*.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (upload) → pending | vendor / agency / agent / marketer | MIME, size; private disk | Badge |
| pending → approved / rejected | admin | Reason on reject | Application gate; agent activation gate |
| approved → expired *(proposed)* | system | Document validity date | Notify; may put vendor on hold |

### 7.3 Change request (locked profile sections) — SRS §4.1.5, §12.2
Implemented: `ChangeRequestStatus`, `App\Domains\Vendors\Support\ChangeRequests` (2026-11-19) for stores and travel agencies.
States: `pending`, `approved` **(T)**, `rejected` **(T)**, `cancelled` **(T)** *(proposed)*.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (submit) → pending | vendor / agency | Section locked | Badge |
| pending → approved | admin | Bank account verification for bank changes | Change applied; audit diff |
| pending → rejected / cancelled | admin / requester | — | — |

### 7.4 Marketer global status — SRS §11.2
States: `pending`, `active`, `suspended`, `rejected` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (register + onboarding) → pending | marketer | Job type(s) chosen, profile filled | Notify admins |
| pending → active / rejected | admin | — | Active still needs onboarding contract to accept campaigns (**FR-MKT-1**) |
| active ⇄ suspended | admin | Reason | Suspended: no new invitations/attributions; pending invitations cancelled (proposed) |

### 7.5 Agency status (travel agency; also marketing agency) — SRS §12.2
States: `pending`, `active`, `suspended`, `rejected` **(T)** *(proposed)*.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (portal register) → pending | agency owner | Licence and logo uploaded; `travel_agency` contract signed (MP §9) | Notify admins |
| pending → active / rejected | admin | — | Membership active; credentials |
| active ⇄ suspended | admin | — | Packages hidden while suspended |

---

## 8. Contracts

### 8.1 Contract template (version) — SRS §13.3
States: `draft`, `scheduled` (published, notice mode, effective date in future), `published`, `retired` **(T)**. Seller enrollment flag: `signed → re_sign_required`.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (create / edit published) → draft | admin | Scope fixed at creation; editing a published version creates a new draft | — |
| draft → published (click-wrap) | admin | Country or legacy set; Arabic text/file present | Previous version → retired; signatures stale → `re_sign_required`; partner web + **vendor app** blocked until signed |
| draft → scheduled (notice) | admin | Seller Agreement only; effective date (default +14 days); change summary | Email + in-app notice |
| scheduled → published | system **`contracts:apply-effective` hourly** | Effective date reached | Continued use recorded as consent (method *continued use*) |
| published → retired | system | Newer version published | Category links move to the new version |

### 8.2 Customer contract — SRS §13.1
States: `active`, `superseded` **(T)**, `cancelled` **(T)**; purpose `purchase | rental`.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (accept) → active | customer | Current published version for category × country | Evidence stored (§13.6); inquiry/purchase unblocked |
| active → superseded | system | New version published | Customer must accept again |
| active → cancelled | admin / customer | — | — |

### 8.3 Marketer-seller (collaboration) contract — SRS §13.1, §13.5
States: `active`, `superseded` **(T)**, `revoked` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (sign) → active | seller | Applicable templates for picked marketer types in seller country; open-call option | Covers invitations (incl. later joiners for open-call) |
| active → superseded / revoked | system / admin | New version / revoke | New campaigns require re-sign |

### 8.4 Exclusive contract — SRS §11.13 (states *proposed*)
States: `scheduled`, `active`, `expired` **(T)**, `revoked` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (grant) → scheduled / active | admin | Listing or category; date window; private file | Storefront shows exclusive marketer (listing beats category) |
| active → expired | system **daily 00:45** | End date passed | Badge removed |
| active → revoked | admin | Reason | — |

---

## 9. Content & support

### 9.1 Special request — SRS §11.13
States: `open`, `in_progress`, `fulfilled` **(T)** *(proposed)*, `closed` **(T)**, `cancelled` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (create) → open | customer | — | Matching active brokers notified |
| open → in_progress | broker ("start") | **Atomic** update — only one broker can claim | Lead created (Appendix A); customer notified |
| in_progress → fulfilled / closed | broker / customer | — | — |
| open → cancelled | customer | — | — |

### 9.2 Page / banner / blog schedule — SRS §15, §10
| Object | States | Driving job |
|---|---|---|
| Page (page builder) | `draft → published / scheduled → archived` | **PageSchedulerJob every 5 min**; append-only revisions; cache cleared on publish |
| Banner | `scheduled → active → expired`, `inactive` | **BannerSchedulerJob every 5 min** |
| Blog post | `draft → scheduled → published → archived` *(archived proposed)* | **PublishScheduledBlogPostsJob every 5 min** |
| Scheduled homepage media (N) | `scheduled → active → ended` | **scheduled homepage media every 5 min** |
Guards: publish needs the country/locale set; scheduled time in country local time with Oman label. Actor: admin (content) or system.

### 9.3 Support ticket — SRS §15
States: `open`, `in_progress`, `waiting_customer`, `resolved`, `closed` **(T)**.

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (create) → open | customer / vendor / marketer / agency / carrier | — | Notify support |
| open → in_progress | admin (assign) | — | — |
| in_progress → waiting_customer | admin (reply needing info) | — | Notify requester |
| waiting_customer → in_progress | requester reply | — | — |
| in_progress → resolved → closed | admin / system (auto-close after N days, proposed 7) | — | Satisfaction survey (proposed) |
| resolved → open | requester (reopen) | Within N days | — |

### 9.4 Stream — SRS §14, ADR 11 (LiveKit)
Live broadcast states: `scheduled` / `idle`, `live`, `ended` **(T)**. Recorded channel states: `scheduled`, `live`, `ended` (healable).

| Transition | Actor | Guards | Side effects |
|---|---|---|---|
| (create) → scheduled/idle | **admin only** | Bilingual title, thumbnail, media mode | LiveKit room + key |
| scheduled/idle → live (Go Live) | admin | Live broadcast only (**FR-LS-9**: refused for channels) | `started_at`; viewers notified; homepage floating icon if configured |
| live → ended | admin | — | `ended_at`; recording saved (N); cannot go live again (**FR-LS-4**) |
| recorded: scheduled → live → ended | system **`streams:sync-recorded` every minute** + lazy API | `now ≥ scheduled_at` / `≥ scheduled_end_at` | Synced playback offset |
| recorded: ended → scheduled | system | Re-timed to future (healed) | — |

---

## Appendix A — Machines found in the sources but missing from ADR 7 (recommend adding)

| Machine | States (proposed unless cited) | Source | Job |
|---|---|---|---|
| Order payment | `pending → authorized → captured | failed | refunded | partially_refunded`, `expired` | SRS §6 FR-CHK-3 | **ExpirePendingPaymentsJob every 5 min** |
| Cart stock lock | `held → released | converted` | SRS §6 FR-CHK-1 | **ReleaseExpiredLocksJob every minute** |
| Vendor account | `active ⇄ on_hold`, `suspended`, `blacklisted (T)` (+ strikes counter) | SRS §4.1.5 | — |
| Customer account | `active ⇄ suspended`, `banned` | SRS §4.1.5 | — |
| Delivery agent | `pending_documents → active ⇄ unavailable`, `suspended` | SRS §4.1.11, §4.6 | — |
| Voucher | `active → partially_used → used`, `expired`, `cancelled` | Spec §6.1 | daily expiry (proposed with gift cards 00:30) |
| Nawy One membership | `incomplete → active`, `trialing → active → past_due → cancelled | expired` (`incomplete`: first payment started but not yet captured; a failed or abandoned first payment leaves it `incomplete` and it can `expire`; QC-068) | MP §7a, §10 | **membership renewals** |
| Promotional credit | `granted → partially_used → used`, `expired` | MP §10 | promotion expiry 15 min |
| Real-estate lead | `new → contacted → viewing_scheduled → negotiating → won | lost` + follow-ups | Spec §5.7 | follow-up reminders (proposed). Its own pipeline, moved only by the broker (D12: a viewing request no longer moves it) |
| Viewing / reserve request (Open Souq, all verticals) | **No lifecycle: a record only** (owner decision D12, 2026-11-18). The customer's name, email, phone, preferred time and message, plus `created_at`. No confirm, reject, schedule, complete or cancel; no status, no history rows, no status notifications or emails. The advertiser is mailed once that a new request arrived, sees it in a list (Seller Center → Marketplace → Requests, Creator Studio → Broker → Requests, website / app "On my ads") and contacts the customer outside Nawy. The legacy `property_viewings.status` / `scheduled_at` columns are frozen (default `requested`, never written, read or exposed). | D8, D12 | — |
| Campaign application (marketplace) | `applied → accepted | rejected | withdrawn` | MP §8 | — |
| Content submission | `submitted → approved | changes_requested | rejected` | MP §8 | — |
| Cross-promotion participation | `eligible → enrolled → compliant | in_violation → penalised | suspended → reinstated`, `exempt` | Spec §5.6 | **cross-promotion obligation evaluation (weekly/monthly)** |
| Clawback recovery | `open → partially_recovered → recovered | written_off` | MP §10, §13 | **clawback recovery** |
| Flash-sale marketer invite | `pending → accepted | declined` | SRS §10 | — |
| Classified abuse report | `open → reviewing → actioned | dismissed` | MP §12 | — |
| Saved search alert | `active ⇄ paused`, `deleted` | MP §11 | **saved-search alerts hourly** |
| Gateway webhook event | `received → verified → processed | duplicate | failed` (built, QC-032: `verified` = matched to a payment with an outcome; `duplicate` = the payment already had that outcome; a redelivery of the same event id adds no row and only increments `deliveries`; extra `ignored` = signed but nothing to act on — unknown payment or event type) | ADR 6 | a gateway redelivery re-processes a `failed` event |
| Advertise inquiry | `new → contacted → closed` | PORTAL_PAGES §8 | — |

## Appendix B — Jobs → machines

| Schedule (MP §11, SRS §17) | Job | Machines |
|---|---|---|
| every minute | ReleaseExpiredLocksJob | Cart stock lock |
| every minute | `streams:sync-recorded` | Stream (recorded) |
| every minute | travel seat-hold expiry (N) | Booking `held → hold_expired` |
| every 5 min | TransitionFlashSaleStatusJob, FlashSaleSchedulerJob | Flash-sale event, submission |
| every 5 min | BannerSchedulerJob, PageSchedulerJob, PublishScheduledBlogPostsJob, scheduled homepage media (N) | Content schedules |
| every 5 min | PaidAdSchedulerJob | Ad booking |
| every 5 min | ExpirePendingPaymentsJob | Order payment |
| every 15 min | CheckSlaBreachJob | Delivery assignment (SLA flag) |
| every 15 min | `coupons:close-expired-participation-invitations` | Coupon invitation |
| every 15 min | `promotion:expire-items` | Promotional items / credits |
| hourly | MonitorCampaignStockJob | Campaign (pause/resume/done) |
| hourly | `ad-packages:expire` | Ad-package subscription |
| hourly | `contracts:apply-effective` | Contract template (scheduled → published) |
| hourly | AggregateAnalyticsCacheJob; saved-search alerts (N) | — / Saved search |
| daily 00:00 | ResetAdCampaignDailyBudgetJob | Ad campaign |
| daily 00:15 / 00:30 / 00:45 | Coupon expiry / gift-card expiry + activate fulfilled coupon invitations / exclusive-contract expiry | Coupon, Gift card, Coupon invitation, Exclusive contract |
| daily 01:00 / 01:15 | FBN overage / flag overstored unpaid | Storage-fee invoice |
| daily 02:00 | AutoCompleteOrdersJob | Sub-order, master order |
| daily 03:00 | ExpireWarrantyPurchasesJob | Warranty purchase |
| daily 03:15 | ApproveMarketerConversionsJob | Conversion |
| daily 03:20 | SettleMarketerSalesJob | Marketer-as-seller settlement |
| daily 03:30 | ReleaseMarketerPendingCommissionJob | Conversion |
| daily 03:45 | CompleteTravelBookingsJob + agency campaign-fee retry | Booking, Campaign fee |
| daily 04:00 | CompleteEndedMarketerCampaignsJob | Campaign |
| daily 04:30 | `portal-analytics:prune` | — |
| daily 23:30 | GenerateCodSettlementsJob | COD settlement |
| daily (no slot) | Classified expiry + renewal reminders; gift-card scheduled email; reporting ETL; search index rebuild; clawback recovery; membership renewals | Classified, Gift card, Clawback, Membership |
| weekly Mon 02:00 / 1st & 15th / monthly 1st | Generate vendor payouts | Vendor payout |
| monthly 1st 02:00 | Acquisition commissions | — |
| monthly 1st 07:00 | FBN storage invoices | Storage-fee invoice |
| monthly | Subscription invoices + past_due handling | Subscription |
| weekly / monthly | FbnInboundReminderJob; bestseller ranking; cross-promotion obligation evaluation; celebrity monthly report | FBN inbound, Cross-promotion participation |
| delayed | ProcessCampaignAutoApproveJob; ProcessInvitationTimeoutJob | Campaign, Invitation |

Open point: daily times above are the old system's; whether they run at each country's local time or one UTC time is PRD OQ6 / risk T7.

## Appendix C — Job name traceability (QC-039, QC-069)

The SRS job classes (Appendix B) run as Artisan commands, each per country through `ForEachCountry`. Times are admin (Oman) time.

| SRS name | Command | Schedule |
|---|---|---|
| ReleaseExpiredLocksJob | `nawy:cart:release-expired-locks` | every minute |
| `streams:sync-recorded` | `nawy:streams:sync-recorded` | every minute |
| travel seat-hold expiry | `nawy:travel:expire-holds` | every minute |
| TransitionFlashSaleStatusJob / FlashSaleSchedulerJob | `nawy:flash-sales:transition` | every 5 min |
| BannerSchedulerJob / PageSchedulerJob / PublishScheduledBlogPostsJob | `nawy:content:publish-scheduled` (banners and homepage media use per-block visibility windows) | every 5 min |
| PaidAdSchedulerJob | `nawy:ads:schedule-bookings` | every 5 min |
| ExpirePendingPaymentsJob | `nawy:payments:expire-pending` | every 5 min |
| gift-card scheduled email | `nawy:gift-cards:deliver-scheduled` | every 5 min |
| CheckSlaBreachJob | `nawy:delivery:check-sla` | every 15 min |
| `coupons:close-expired-participation-invitations` | `nawy:coupons:close-participation-invitations` (the SRS name is kept as an alias) | every 15 min |
| `promotion:expire-items` | `nawy:wallet:expire-promotional` (the SRS name is kept as an alias) | every 15 min |
| ProcessCampaignAutoApproveJob (delayed) | `nawy:campaigns:auto-approve` (sweeps campaigns past `marketer_campaign_auto_approve_hours`) | every 15 min |
| ProcessInvitationTimeoutJob (delayed) | `nawy:campaigns:expire-invitations` (sweeps invitations past their timeout) | every 5 min |
| AutoCompleteOrdersJob | `nawy:orders:auto-complete --scheduled` | checked every 15 min; each country runs once a day at its `order.auto_complete_time` (country override, default 02:00) |
| MonitorCampaignStockJob | `nawy:campaigns:monitor-stock` | hourly |
| `ad-packages:expire` | `nawy:ad-packages:expire` | hourly |
| `contracts:apply-effective` | `nawy:contracts:apply-effective` | hourly |
| AggregateAnalyticsCacheJob | `nawy:analytics:aggregate` | hourly |
| saved-search alerts | `nawy:classifieds:saved-search-alerts` | hourly |
| membership renewals | `nawy:membership:renew` | hourly |
| ResetAdCampaignDailyBudgetJob | `nawy:ads:reset-daily-budgets` | daily 00:00 |
| coupon expiry | `nawy:coupons:expire` | daily 00:15 |
| gift-card expiry | `nawy:gift-cards:expire` | daily 00:30 |
| activate fulfilled coupon invitations | `nawy:coupons:activate-participation-invitations` | daily 00:30 |
| exclusive-contract expiry | `nawy:exclusive-contracts:sync` | daily 00:45 |
| classified expiry | `nawy:classifieds:expire` | daily 01:00 |
| certification / document expiry | `nawy:documents:expire` | daily 01:30 |
| Generate vendor payouts | `nawy:payouts:generate` | daily 02:00 (see below) |
| ExpireWarrantyPurchasesJob | `nawy:warranty:expire` | daily 03:00 |
| ApproveMarketerConversionsJob | `nawy:conversions:approve` | daily 03:15 |
| ReleaseMarketerPendingCommissionJob (+ clawback recovery) | `nawy:conversions:release` | daily 03:30 |
| CompleteTravelBookingsJob | `nawy:travel:complete-bookings` | daily 03:45 |
| CompleteEndedMarketerCampaignsJob | `nawy:campaigns:complete-ended` | daily 04:00 |
| `portal-analytics:prune` | `nawy:analytics:prune` | daily 04:30 |
| GenerateCodSettlementsJob | `nawy:cod:generate-settlements` | daily 23:30 |
| bestseller ranking | `nawy:catalog:rank-bestsellers` | weekly Mon 03:30 |
| cross-promotion obligation evaluation | `nawy:marketers:evaluate-cross-promotion` | monthly 1st 05:00 |
| support ticket auto-close | `nawy:support:auto-close` | see §9.3 |
| FBN storage snapshot / flags / inbound reminders / storage invoices | `nawy:fbn:storage-snapshot` / `nawy:fbn:flag-storage` / `nawy:fbn:inbound-reminders` / `nawy:fbn:invoice-storage` | daily 01:00 / 01:15 / weekly Sun 09:00 / monthly 1st 02:30 |
| Acquisition commissions | `nawy:finance:acquisition-commissions` | monthly 1st 02:00 (previous country-local month) |
| Subscription invoices + past_due handling | `nawy:subscriptions:bill` | daily 02:15 (each subscription's monthly or annual invoice at its period end; past_due after the due date; expiry after `subscription.grace_days`) |
| Nightly reporting ETL | `nawy:reporting:etl` | daily 01:30 (yesterday per country into `nawy_reporting`) |
| Celebrity monthly report | `nawy:marketers:celebrity-reports` | monthly 1st 06:00 |
| Search index rebuild | `nawy:search:rebuild-index` | daily 04:20 |
| Exchange-rate sync (PayPal USD) | `nawy:exchange-rates:sync` | daily 00:05 (manual driver: nothing fetched) |

**Dropped: SettleMarketerSalesJob (daily 03:20).** Owner decision M13: a marketer who sells on Nawy does so through a store, so marketer-as-seller sales settle with the store payouts (`nawy:payouts:generate`) like any vendor. There is no separate marketer-sale settlement and no command for it.

**All Appendix B jobs are built** (2026-11-19: acquisition commissions, subscription invoices, reporting ETL, celebrity report and search index rebuild closed the QC-015 list; FBN jobs were built with the FBN module).

**Vendor payouts (QC-069).** The SRS lists payout schedules weekly Monday / 1st & 15th / monthly 1st. `nawy:payouts:generate` runs **daily at 02:00** (`nawy.finance.payout_generation_time`) and, per country, creates a statement only for vendors whose own `payout_schedule` is due that day in the country's time zone (`PayoutSchedule::isDue`: `weekly` → Monday, `biweekly` → 1st and 15th, `monthly` → 1st). So each vendor still gets their SRS schedule; the daily run is how several schedules share one job. Admins can generate out of schedule from the Finance panel. Nothing is paid automatically.

