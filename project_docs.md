# MASTER PROJECT PROMPT — MULTI-COUNTRY COMMERCE & SERVICES SUPER PLATFORM

## 1. YOUR ROLE & MISSION

You are acting as a complete senior product and engineering organization, not just a coding assistant.

Your responsibilities include:

- Principal Software Architect
- Senior Laravel Backend Architect
- Senior Next.js Frontend Architect
- Senior Flutter Architect
- UI/UX Design Director
- Figma Designer
- Product Manager
- Database Architect
- DevOps Engineer
- QA & Automation Engineer
- Application Security Engineer
- E-commerce and Marketplace Business Analyst

Your task is to research, design, architect, implement, test, and prepare a complete production-ready, multi-country digital commerce and services platform.

The platform combines the major business capabilities and user experiences of:

1. Noon — Multi-vendor e-commerce, shopping experience, seller onboarding, promotions, orders, checkout, and seller management.
2. OpenSooq — Classified advertisements and customer-to-customer / business-to-customer marketplace.
3. Boutiqaat — Beauty, fashion, curated products, influencers, and creator-driven commerce.
4. Boksha — Fashion, lifestyle, and curated marketplace concepts.
5. Additional original modules described in this specification: travel and tourism, affiliate marketing, influencers, real-estate brokers, delivery companies, vouchers, gift cards, digital wallets, paid advertisements, and scheduled live audio/video content.

The result must be one integrated platform with a unified customer experience and separate, professional management experiences for every business role.

**This is not a prototype, landing page, or collection of static dashboards. It must be implemented as a real, working system with connected databases, APIs, permissions, business logic, payment flows, and tested user journeys.**

Do not claim that a feature is complete merely because its page or button exists.

---

# 2. STRICT EXECUTION RULES

Follow these rules throughout the project:

1. Do not skip any requirement in this document.
2. Do not start coding before understanding the business requirements and completing the reference-site audit and architecture documentation.
3. Do not build the entire platform as one enormous Laravel module. Use a modular, well-organized architecture with clear business boundaries.
4. Do not use Laravel Livewire anywhere in this project.
5. Do not use Next.js for admin, vendor, delivery, marketer, broker, or other management dashboards. Those dashboards must be implemented inside Laravel.
6. The customer-facing website must be implemented using Next.js.
7. Mobile applications must be implemented using Flutter.
8. Laravel is the single source of truth for business rules, permissions, calculations, transactions, and database operations.
9. Next.js and Flutter must communicate with Laravel through documented APIs. Never access the database directly from either client.
10. Do not place business calculations directly inside controllers, Blade files, JavaScript files, or UI components.
11. Do not duplicate commission, tax, discount, wallet, or shipping calculations across different parts of the system.
12. All financial calculations must use reliable decimal arithmetic and appropriate database precision. Never use floating-point arithmetic for financial balances.
13. Every important action must have authorization, validation, logging, and appropriate error handling.
14. All critical operations must be transactional and safe against duplicate requests.
15. Do not leave fake buttons, static counters, non-functional forms, dummy API responses, or unfinished pages in a feature marked as complete.
16. Do not insert secrets, credentials, payment keys, or API tokens into source code.
17. Do not modify existing project files or configuration blindly. Inspect the environment and existing code first.
18. Do not silently make destructive changes to existing data.
19. Never invent the behavior of a reference feature that cannot be inspected. Document the gap and use verified references or a clearly identified product decision.
20. Keep a live project checklist and update it as implementation progresses.

If a tool, API, MCP capability, or external integration is unavailable, explain precisely what is unavailable and continue with the best possible implementation using a clearly defined adapter or documented integration boundary.

Do not stop after producing a plan. After the analysis and design approval stages, execute the implementation incrementally.

---

# 3. REFERENCE PLATFORMS — MANDATORY RESEARCH

Reference websites:

## Noon UAE customer website

https://www.noon.com/uae-en/

https://www.noon.com/uae-ar/

## Noon seller registration and onboarding

https://sell.noon.com/uae-en

https://sell.noon.com/uae-en/go-live

## Noon Seller Lab

https://login.noon.partners

## Additional references

https://www.opensooq.com/

https://www.boutiqaat.com/

https://www.boksha.com/

Research the current versions of these platforms, but prioritize Noon UAE as the primary UI/UX reference.

## 3.1 Complete Noon customer website audit

You MUST systematically inspect the accessible Noon UAE website.

Do not inspect only the homepage.

Build a reference audit covering at least these page families:

### A. Global layout

- Main header
- Country and language selector
- Logo and search area
- Search suggestions
- Main navigation
- Mega menus
- Category navigation
- Promotional navigation
- Desktop footer
- Mobile header
- Mobile navigation
- Floating actions
- Sticky components
- Responsive behavior

### B. Homepage

- Complete page layout
- Promotional hero sliders
- Campaign banners
- Category shortcuts
- Product carousels
- Product grids
- Flash deals
- Trending sections
- Recommended products
- Brand sections
- Seasonal campaigns
- Personalized content
- Promotional widgets
- Footer sections

The homepage must use a professional Page Builder, allowing administrators to create different homepage layouts for every country and language.

### C. Shopping and discovery

- Main category pages
- Subcategory pages
- Product listing pages
- Product grid and list views
- Search results
- Search filters
- Sorting options
- Brand pages
- Campaign pages
- Promotional landing pages
- Product recommendations
- Recently viewed products
- Related products

### D. Product details

Inspect the different product-detail patterns, including:

- Product title
- Brand
- Images and gallery
- Video
- Product variants
- Specifications
- Price
- Previous price
- Discounts
- Availability
- Delivery estimates
- Seller information
- Ratings and reviews
- Questions and answers
- Product description
- Warranty
- Return policy
- Add-to-cart behavior
- Buy-now behavior
- Wishlist
- Related products
- Frequently bought together

### E. Cart and checkout

Inspect:

- Cart
- Cart item editing
- Quantity updates
- Promotional codes
- Seller-specific discounts
- Shipping charges
- Order summary
- Login before checkout
- Address selection
- Shipping methods
- Payment methods
- Order confirmation
- Failed payments
- Empty states
- Validation and error states

### F. Customer account

Inspect and document:

- Sign in
- Registration
- OTP verification
- Account dashboard
- Personal information
- Address book
- Orders
- Order details
- Shipment tracking
- Cancellations
- Returns
- Refunds
- Wallet or credits, where applicable
- Wishlist
- Reviews
- Notifications
- Saved payment methods, without storing card secrets
- Security settings
- Help and support

### G. Other page families

- Help center
- Contact pages
- About pages
- Policies
- Terms and conditions
- Privacy
- Return policy
- Delivery information
- Promotional pages
- Brand information
- SEO landing pages
- Any additional accessible page types discovered during research

## 3.2 Noon seller experience audit

Inspect both:

1. The public seller acquisition website at sell.noon.com.
2. Seller Lab and the seller-management workflows that can be inspected with authorized access.

Do not confuse the public seller onboarding website with the authenticated seller-management portal.

Inspect the public seller website:

- Seller landing page
- Benefits of selling
- Registration
- Country selection
- Business information
- Required documentation
- Brand onboarding
- Product setup
- Fulfillment options
- Go-live process
- FAQs
- Seller policies
- Support
- Registration status and next steps

For seller management, investigate all accessible workflows and organize the functionality into these areas:

- Dashboard and analytics
- Product catalog
- Product listings
- Product creation
- Product variations
- Inventory
- Stock management
- Warehouses
- Fulfillment models
- Orders
- Shipments
- Returns
- Customer issues
- Promotions
- Advertising
- Performance reports
- Sales reports
- Fees and commissions
- Settlements
- Payouts
- Invoices
- Legal entities
- Business documents
- Account settings
- Team members
- Permissions
- Seller support

Use the FBN (Fulfilled by Noon) and FBP (Fulfilled by Partner) concepts as references for designing configurable fulfillment models in our platform, while allowing our own fulfillment rules and country-specific configurations.

If Seller Lab requires an authenticated account, never bypass its authentication or access controls. Use available public documentation, authorized access, provided screenshots, and verifiable workflows.

Create an explicit list of:

- Pages inspected successfully.
- Pages partially inspected.
- Pages requiring authenticated access.
- Features inferred from reliable documentation.
- Features requiring a product decision.

Do not claim to have inspected every private page when access was unavailable.

## 3.3 Reference audit deliverables

Before implementation, create:

- `docs/reference-research/noon-customer-audit.md`
- `docs/reference-research/noon-seller-audit.md`
- `docs/reference-research/opensooq-audit.md`
- `docs/reference-research/boutiqaat-audit.md`
- `docs/reference-research/boksha-audit.md`
- `docs/reference-research/reference-page-inventory.md`
- `docs/reference-research/feature-comparison.md`

The page inventory must contain the page name, reference URL, purpose, layout, key interactions, responsive behavior, and our implementation route.

For pages that have dynamic variations, document the page template and its variants rather than treating each product URL as a separate design.

---

# 4. PRODUCT IDENTITY AND PLATFORM STRUCTURE

Build a scalable multi-country commerce platform.

The platform must support multiple countries, languages, currencies, payment methods, tax rules, delivery policies, seller policies, shipping companies, and business configurations.

Use a country-and-language URL pattern:

`platform.com/{country}-{locale}`

Examples:

- `/uae-ar`
- `/uae-en`
- `/egypt-ar`
- `/egypt-en`
- `/saudi-ar`
- `/saudi-en`

The country and locale must control the entire customer experience.

Each country has its own:

- Product catalog
- Vendors
- Classified listings
- Advertisements
- Tourism providers
- Trips and packages
- Brokers
- Influencers and marketers
- Campaigns
- Orders
- Bookings
- Wallet policies
- Payment gateway configurations
- Delivery companies
- Shipping rules
- Tax configurations
- Currencies
- Promotional campaigns
- Terms and policies
- Local settings

Data belonging to one country must never accidentally appear in another country.

The platform should have a central administration layer with independent country operations.

## 4.1 Country isolation architecture

Design a reliable isolation architecture.

Preferred direction:

- Central control-plane database for global administration, country registry, country configurations, domain mapping, and central subscription/system settings.
- Independent operational database for each country.
- Country-specific database connections resolved by trusted country context.
- Country-specific cache namespaces, storage organization, queues, and configurations wherever required.
- Strict authorization and country scoping at the service and repository layers.

An alternative isolation strategy may be proposed only if you document its security, scalability, backup, reporting, and operational trade-offs.

Create clear documentation explaining:

- Which tables live in the central database.
- Which tables live in country databases.
- How users and identities are managed.
- Whether a customer can have a separate account per country or a controlled cross-country identity.
- How a global administrator accesses country data.
- How backups and migrations are managed.
- How a country is added without modifying the whole application.
- How country-specific data isolation is tested.

Do not solve country isolation using only a `country_id` filter on every random query.

---

# 5. COMPLETE BUSINESS MODULES

Build the platform as independent but connected business modules.

## 5.1 E-commerce module

A complete Noon-style multi-vendor e-commerce experience.

Include:

- Categories and subcategories
- Brands
- Products
- Product attributes
- Variants
- SKU management
- Barcode support
- Product images and videos
- Product specifications
- Multiple sellers for comparable catalog products, where appropriate
- Seller-specific listings
- Pricing
- Discounts
- Stock and inventory
- Multiple warehouses
- Product availability
- Product approval workflows
- Product moderation
- Product ratings
- Reviews
- Questions and answers
- Recommendations
- Search and filtering
- Product comparison
- Recently viewed
- Wishlists
- Cart
- Checkout
- Orders
- Invoices
- Returns
- Refunds
- Cancellations
- Shipment tracking

Support both platform-owned inventory and seller-owned inventory.

Handle multi-vendor orders properly.

If a customer orders products from multiple sellers, support a parent order with seller-specific sub-orders and separate shipment and settlement calculations.

Do not assume every item in an order belongs to one seller.

## 5.2 Open marketplace / classifieds module

A complete OpenSooq-style marketplace for classified listings.

Support categories such as:

- Vehicles
- Real estate
- Electronics
- Home and furniture
- Jobs
- Services
- Fashion
- Business opportunities
- Miscellaneous categories

Features:

- Listing creation
- Multiple images
- Video
- Category-specific attributes
- Location and map
- Pricing
- Negotiable pricing
- Listing expiry
- Listing renewal
- Featured listings
- Paid boosting
- Listing moderation
- Reporting
- Favorites
- Seller profile
- Buyer-seller communication
- Contact options
- Inquiry management
- Listing status
- Listing analytics
- Search and advanced filters
- Country-specific categories and policies

Support both individual and commercial sellers.

Build secure messaging and reporting mechanisms, and protect users against fraudulent listings and suspicious activity.

## 5.3 Travel, tourism, and booking module

Create a complete travel and tourism marketplace.

Support:

- Tourism companies
- Tour operators
- Travel agencies
- Hotels and accommodation providers, where applicable
- Trip organizers
- Travel packages
- Activities
- Excursions
- Group trips
- Private trips
- Seasonal offers

Required capabilities:

- Destination management
- Trip schedules
- Departure dates
- Availability calendars
- Capacity
- Number of travelers
- Booking forms
- Traveler information
- Optional services
- Pricing rules
- Promotional discounts
- Booking confirmation
- Payment
- Booking cancellation
- Refund policies
- Booking modifications
- Booking vouchers
- Invoices
- Reviews
- Booking analytics
- Operator settlements

Every trip and booking must belong to a country and follow its policies and currency rules.

Prevent overbooking using transactional capacity allocation and appropriate locking.

## 5.4 Influencers, affiliate marketers, and creators

Build a complete affiliate and influencer ecosystem.

Support:

- Influencers
- Content creators
- Affiliate marketers
- Marketing agencies
- Commercial affiliates
- Platform-approved sellers who also work as marketers

Each user can have a professional public profile including:

- Profile image
- Bio
- Country
- Languages
- Social accounts
- Categories
- Audience information
- Marketing specialties
- Verification status
- Performance statistics
- Campaign history
- Reviews and ratings, where applicable

Core features:

- Affiliate product catalog
- Campaign marketplace
- Campaign invitations
- Campaign applications
- Campaign acceptance
- Affiliate links
- Unique tracking IDs
- Coupon attribution
- Commission calculation
- Conversion tracking
- Click tracking
- Sales attribution
- Refund and cancellation adjustments
- Earnings
- Wallet
- Payout requests
- Marketing analytics
- Performance rankings
- Campaign reporting
- Content submission
- Approval workflows
- Promotional media library

Build an attribution engine supporting configurable attribution policies.

At minimum, design an implementation that can track:

- Affiliate click ID
- Campaign ID
- Marketer ID
- Influencer ID
- Coupon ID
- Customer/order relation
- Attribution timestamp
- Conversion
- Refund or cancellation
- Commission eligibility

The attribution model must be configurable by the administrator.

Support last-click as an initial strategy while leaving room for additional models.

Prevent fraudulent clicks, fake conversions, self-referrals where prohibited, and duplicate commission claims.

## 5.5 Marketers selling their own products

A marketer or influencer must also be able to sell their own products separately from their marketing activity.

This must be a real seller capability, not just a product-sharing page.

The system must distinguish:

1. Platform-owned products.
2. Vendor-owned products.
3. Marketer/influencer-owned products.

For every product and listing, preserve clear ownership and seller information.

Define how stock, fulfillment, payment, tax, commission, refunds, customer service, and returns work for each ownership type.

A creator who owns a product must be able to manage their own inventory and sales, subject to the same platform policies and country restrictions applicable to that product category.

Marketing commission and product-sale profit must be separate accounting concepts.

Do not mix them in one ambiguous balance.

## 5.6 Cross-promotion rules between marketers

The platform must include a special cross-promotion program.

Marketers who are approved for this program must participate in promoting eligible products belonging to other approved marketers according to administrator-defined rules.

Build this as a configurable business policy engine.

The administrator must be able to configure:

- Eligibility
- Minimum account age
- Required profile completion
- Verification status
- Country eligibility
- Product eligibility
- Restricted categories
- Minimum number of products
- Promotion participation limits
- Required promotional activities
- Participation windows
- Commission allocation
- Campaign rules
- Reward conditions
- Fraud detection rules
- Penalties for violations
- Exemptions
- Manual approvals
- Suspension and reinstatement

Build a transparent participation dashboard for each marketer.

Do not hardcode participation requirements or commission percentages inside controllers.

Every rule must be visible to administrators and reflected in the marketer's policy status.

Make this feature configurable and disableable by country.

## 5.7 Real-estate broker module

Build a dedicated real-estate broker marketplace.

Support:

- Real-estate brokers
- Agencies
- Property owners, where enabled
- Commercial and residential properties
- Rent and sale
- Property types
- Locations
- Map integration
- Price
- Area
- Bedrooms
- Bathrooms
- Property features
- Images
- Videos
- Property verification
- Broker profiles
- Lead generation
- Buyer inquiries
- Contact requests
- Follow-up management
- Featured properties
- Paid promotions
- Broker commissions
- Campaign participation
- Lead analytics

Each broker must have a dedicated dashboard.

Provide separate workflows for property listings and broker marketing campaigns.

## 5.8 Coupons and flash-sale campaigns

### Seller-created coupons

Each approved vendor must be able to create coupons under country and administrator policies.

Support:

- Percentage discount
- Fixed discount
- Product-specific discounts
- Category-specific discounts
- Minimum order
- Maximum discount
- Expiry
- Usage limits
- Per-customer limits
- First-order restrictions
- Country restrictions
- Seller restrictions
- Stackability rules
- Usage reporting

### Flash sales

Create a complete flash-sale module.

The administrator can create platform-wide events and invite or allow vendors to participate.

Vendors can:

- Apply to participate
- Submit products
- Set campaign prices within approved constraints
- Submit requested inventory
- Track approval status
- Monitor sales
- Monitor available stock
- View campaign reports

Administrators control:

- Event schedule
- Country
- Campaign eligibility
- Participation approval
- Start and end times
- Product limits
- Discount policies
- Campaign visibility
- Promotional priority
- Stock requirements
- Maximum per-customer quantity

Include countdown timers and scheduled publication.

Support alternate campaign names such as Mega Deals, Daily Deals, Special Offers, or Flash Sales, configurable by the marketing team.

---

# 6. VOUCHERS, GIFT CARDS, AND DIGITAL WALLETS

Build a unified but properly separated digital value system.

## 6.1 Vouchers

Support:

- Single-use vouchers
- Multiple-use vouchers
- Product vouchers
- Category vouchers
- Service vouchers
- Trip vouchers
- Seller vouchers
- Platform vouchers
- Country-specific vouchers
- Expiration
- Redemption
- Cancellation
- Reporting

## 6.2 Gift cards

Support:

- Gift card creation
- Fixed denominations
- Custom amounts, where permitted
- Physical or digital gift cards
- Unique secure codes
- Recipient information
- Email delivery
- Redemption
- Partial redemption
- Remaining balance
- Expiration policy
- Refund policy
- Country and currency restrictions
- Sales reports

Never expose gift-card code values in administrative logs unnecessarily.

## 6.3 Wallet

The wallet must support multiple transaction types.

Examples:

- Affiliate earnings
- Broker commissions
- Refund credits
- Seller balances
- Promotional credits
- Customer wallet
- Gift-card redemptions
- Withdrawals
- Manual adjustments with authorization

Implement a reliable financial ledger.

Requirements:

- Immutable transaction history
- Double-entry accounting or an equivalent auditable ledger architecture
- Available balance
- Pending balance
- Reserved balance
- Withdrawable balance
- Transaction references
- Reconciliation
- Withdrawal requests
- Approval workflows
- Refund transactions
- Reversal transactions
- Administrative adjustments
- Audit logs

Do not update a wallet balance without an associated ledger transaction.

Prevent duplicate financial transactions through idempotency and database constraints.

---

# 7. ADVERTISING AND PAID VISIBILITY

Build a complete paid-advertising system.

Allow the following participants to advertise:

- Platform administrators
- Vendors
- Classified sellers
- Marketers
- Influencers
- Real-estate brokers
- Travel operators

Advertising should support increased visibility while maintaining relevance and transparency.

Possible placements:

- Homepage banners
- Product listing placements
- Search results
- Sponsored products
- Category pages
- Classified listings
- Property listings
- Travel packages
- Campaign pages
- Brand pages

Advertising features:

- Campaign creation
- Target audience
- Country targeting
- Category targeting
- Keyword targeting
- Budget
- Daily budget
- Start and end dates
- Placement selection
- Campaign approval
- Campaign scheduling
- Active / paused / completed status
- Impressions
- Clicks
- CTR
- Conversions
- Spend
- Remaining budget
- Reports
- Billing
- Wallet or invoice-based billing

Design extensible pricing strategies such as:

- Fixed placement
- CPM
- CPC

Do not expose a paid listing as an ordinary organic result without an appropriate sponsored label.

Build ad ranking and sponsored placement as configurable services.

Do not put ranking rules directly inside a SQL query or front-end component.

---

# 8. SHIPPING COMPANIES AND DELIVERY OPERATIONS

Build two independent but connected management experiences.

## 8.1 Shipping company dashboard

A shipping company must have its own dedicated subdomain and permissions.

Features:

- Company profile
- Country and operating areas
- Service areas
- Delivery zones
- Pricing rules
- Shipping methods
- Delivery estimates
- Pickup requests
- Shipment acceptance
- Shipment assignment
- Tracking events
- Driver management
- Delivery performance
- Returned shipments
- COD management
- Settlement and remittance
- Invoices
- Reports
- Support tickets
- Users and permissions

Allow multiple shipping companies to operate in one country.

## 8.2 Delivery agent application

Build a full Flutter application for the delivery agent.

Required features:

- Secure login
- OTP verification when applicable
- Driver availability
- Daily tasks
- Assigned shipments
- Pickup information
- Customer information
- Navigation
- Shipment details
- Status updates
- Pickup confirmation
- Delivery confirmation
- OTP delivery verification
- Signature, where required
- Proof-of-delivery photo, subject to policy
- Failed delivery reasons
- Rescheduling
- Return-to-origin workflow
- COD collection
- COD reconciliation
- Daily summary
- Notifications
- Contact support

Delivery agents must only access assigned and authorized shipment data.

Implement secure status transitions and maintain shipment event history.

---

# 9. LIVE AUDIO AND VIDEO PLATFORM

Build a live-streaming and scheduled media module.

The platform should support:

- Live audio rooms
- Live video streaming
- Hosts
- Speakers
- Participants
- Scheduled sessions
- Scheduled audio clips
- Scheduled video clips
- Recording, where enabled
- Replay
- Media moderation
- Audience controls
- Session notifications
- Access permissions

## 9.1 Scheduled homepage media

Administrators must be able to schedule a video or audio clip for a specific date and time.

When the scheduled content becomes active:

- Display a configurable floating icon on the relevant homepage.
- Show the active content to eligible users.
- Allow users to open the audio/video player.
- Handle playback, pause, mute, and close.
- Respect user preferences and device behavior.
- Prevent repeated interruptions after the user dismisses the content, according to configured rules.
- Support scheduling by country, language, target audience, and user segment.

## 9.2 Live streaming architecture

Research and select a suitable WebRTC / SFU or streaming provider architecture.

Evaluate relevant options such as LiveKit or other suitable services based on:

- Scaling
- Cost
- Recording
- Mobile compatibility
- Audio quality
- Video quality
- Moderation
- Latency
- Deployment complexity

Do not assume that ordinary HTTP requests are sufficient for real-time media.

Implement provider adapters so that the platform is not permanently tied to a single streaming provider.

Document all infrastructure requirements, including TURN/STUN or equivalent media transport configuration where needed.

---

# 10. CUSTOMER EXPERIENCE

Build a complete customer-facing website using Next.js.

The design must follow the audited Noon UAE customer experience as the primary UX reference while incorporating the additional platform modules.

Customer features include:

- Homepage
- Search
- Categories
- Products
- Classified marketplace
- Travel and trips
- Influencer profiles
- Affiliate products
- Real-estate listings
- Deals
- Flash sales
- Paid promotions
- Campaign pages
- Coupons
- Vouchers
- Gift cards
- Wallet
- Cart
- Checkout
- Payment
- Orders
- Bookings
- Returns
- Refunds
- Reviews
- Wishlist
- Notifications
- Account management
- Support

The customer account must have a consistent Noon-style structure, extended with the new services.

Create relevant sections for:

- Shopping orders
- Classified inquiries
- Trip bookings
- Wallet transactions
- Affiliate purchases
- Gift cards
- Vouchers
- Favorite properties
- Favorite products
- Favorite listings
- Reviews
- Notifications
- Addresses
- Profile
- Security
- Support

The same customer must have a coherent profile while still respecting the isolation rules of each country.

---

# 11. SELL WITH US — LARAVEL PROJECT

The seller acquisition and registration website must be implemented inside Laravel.

Do not build it using Next.js.

Create a professional seller acquisition experience inspired by sell.noon.com.

It must support different registration journeys based on the account type.

Registration types:

1. Product vendor.
2. Classified marketplace seller.
3. Travel / tourism operator.
4. Influencer.
5. Affiliate marketer.
6. Real-estate broker.
7. Shipping company.
8. Other approved business types, if configured by administrators.

Each type must have its own onboarding workflow.

Examples:

### Product vendor onboarding

- Country selection
- Seller type
- Contact information
- Legal entity
- Commercial documents
- VAT/tax information
- Bank or payout information
- Brand details
- Product categories
- Fulfillment model
- Policies agreement
- Document upload
- Review
- Approval
- Rejection with reason
- Request for additional information
- Activation

### Influencer and marketer onboarding

- Profile information
- Country
- Contact details
- Social media accounts
- Categories
- Audience information
- Verification
- Marketing interests
- Required agreements
- Approval

### Travel operator onboarding

- Business information
- Licenses
- Country
- Service categories
- Operating locations
- Bank/payout information
- Verification
- Approval

### Broker onboarding

- Professional information
- Agency details
- Required documents
- Country
- Property specialties
- Verification
- Approval

### Shipping company onboarding

- Legal entity
- Operating countries
- Service regions
- Company documents
- Logistics capabilities
- Bank information
- Approval

All onboarding steps must be configurable through the central admin.

Every application must have a trackable status and audit trail.

---

# 12. LARAVEL DASHBOARDS AND SUBDOMAINS

All management dashboards must be implemented within Laravel.

Each dashboard must have an independent interface, navigation structure, permission set, and suitable subdomain.

Example structure:

- `admin.platform.com`
- `vendor.uae.platform.com`
- `market.uae.platform.com`
- `creator.uae.platform.com`
- `broker.uae.platform.com`
- `travel.uae.platform.com`
- `shipping.uae.platform.com`
- `driver.uae.platform.com`
- `operations.uae.platform.com`

These are examples only. Make the domain pattern configurable and document the DNS and TLS requirements.

The central admin must be able to manage supported domains and country mappings.

## 12.1 Platform administration dashboard

Build a professional, comprehensive central admin.

Required sections:

### General administration

- Overview
- System health
- Global settings
- Countries
- Languages
- Currencies
- Locales
- Domains
- Time zones
- Global permissions
- Audit logs
- System notifications

### Country management

- Country setup
- Country-specific settings
- Country administrators
- Currency
- Tax
- Payment gateways
- Shipping policies
- Countries' terms
- Country-specific categories
- Country-specific homepages
- Country-specific marketing campaigns

### E-commerce administration

- Products
- Brands
- Categories
- Vendors
- Product approvals
- Inventory
- Orders
- Returns
- Refunds
- Promotions
- Flash sales
- Coupons
- Reviews
- Seller fees

### Marketplace administration

- Classified listings
- Categories
- Moderation
- Reports
- Sellers
- Paid listings
- Disputes

### Travel administration

- Operators
- Destinations
- Trips
- Packages
- Bookings
- Cancellations
- Refunds
- Commission policies

### Affiliate administration

- Marketers
- Influencers
- Brokers
- Campaigns
- Affiliations
- Tracking
- Attribution
- Commissions
- Cross-promotion rules
- Payouts
- Fraud monitoring

### Financial administration

- Wallets
- Financial ledger
- Gift cards
- Vouchers
- Transactions
- Refunds
- Payouts
- Settlements
- Reconciliation
- Payment reports

### Advertising

- Campaigns
- Placements
- Advertisers
- Approval
- Budgets
- Billing
- Metrics
- Sponsored ranking settings

### Delivery administration

- Shipping companies
- Delivery zones
- Drivers
- Shipments
- COD
- Settlements
- Delivery performance

### Live content

- Live sessions
- Scheduled media
- Audio library
- Video library
- Homepage floating media
- Moderation
- Analytics

### Administration and operations

- Staff users
- Roles
- Permissions
- Support tickets
- Notifications
- Email templates
- SMS / OTP templates
- Queue monitoring
- Failed jobs
- Background jobs
- API logs
- Security events
- System configurations

Use modular navigation and avoid one overcrowded sidebar.

## 12.2 Vendor dashboard

The vendor dashboard must be comparable in breadth to a serious marketplace seller portal.

Include:

- Dashboard
- Sales analytics
- Products
- Product creation
- Product editing
- Product variants
- Stock
- Warehouses
- Orders
- Order details
- Fulfillment
- Shipments
- Returns
- Cancellations
- Coupons
- Flash-sale applications
- Campaigns
- Ads
- Reviews
- Customer communication
- Earnings
- Commissions
- Statements
- Payouts
- Invoices
- Store settings
- Staff users
- Permissions
- Documents
- Support

Support different fulfillment methods and delivery integrations.

## 12.3 Classified seller dashboard

Include:

- Listings
- Create listing
- Categories
- Images and videos
- Pricing
- Listing statistics
- Inquiries
- Conversations
- Favorites
- Paid boosting
- Subscription packages, if enabled
- Reports
- Profile
- Payments
- Support

## 12.4 Marketer / influencer dashboard

Include:

- Overview
- Profile
- Social accounts
- Verification
- Affiliate catalog
- Own products
- Campaign marketplace
- Campaign applications
- Active campaigns
- Affiliate links
- Tracking
- Coupon management
- Sales
- Conversions
- Earnings
- Wallet
- Withdrawals
- Reports
- Cross-promotion program
- Collaboration tasks
- Notifications
- Content submission
- Support

Clearly distinguish affiliate earnings from the revenue and profit generated by the user's own products.

## 12.5 Broker dashboard

Include:

- Profile
- Properties
- Listing creation
- Leads
- Inquiries
- Follow-ups
- Marketing campaigns
- Paid visibility
- Commissions
- Wallet
- Reports
- Documents
- Support

## 12.6 Travel operator dashboard

Include:

- Business profile
- Destinations
- Packages
- Trips
- Departure dates
- Capacity
- Pricing
- Bookings
- Travelers
- Payments
- Refunds
- Promotions
- Reviews
- Earnings
- Reports
- Staff
- Support

## 12.7 Shipping company dashboard

Include:

- Overview
- Company settings
- Service zones
- Pricing
- Shipments
- Pickup requests
- Drivers
- Assignments
- Tracking
- Delivery exceptions
- Returns
- COD
- Reconciliation
- Settlements
- Reports
- Support

## 12.8 Delivery supervisor dashboard

Include:

- Driver management
- Driver availability
- Shipment dispatch
- Shipment assignments
- Delivery status
- Failed deliveries
- Daily operations
- COD monitoring
- Performance reports

---

# 13. PAYMENTS — MULTI-COUNTRY GATEWAY ARCHITECTURE

Integrate the following payment gateways:

- Thawani
- Stripe
- PayPal
- PayTabs

The architecture must allow gateways to be enabled in one or more countries.

Each country and gateway combination must have its own configuration.

Example:

- UAE: Thawani + Stripe + PayPal + PayTabs
- Egypt: Stripe + PayTabs
- Other countries: configurable

These are examples, not fixed business decisions.

Support:

- Gateway activation
- Gateway credentials
- Sandbox and production modes
- Supported currencies
- Payment methods
- Transaction limits
- Country restrictions
- Webhook configuration
- Webhook signature validation
- Refunds
- Partial refunds, where supported
- Payment reconciliation
- Failed payments
- Retry policies
- Transaction references
- Gateway reporting

Use a common payment gateway interface and individual drivers/adapters for each provider.

Do not mix provider-specific API code throughout order controllers.

Gateway credentials must be stored securely and encrypted.

Never expose secret keys to the browser or mobile application.

Implement idempotency and secure webhook processing.

Use official provider documentation when implementing each integration.

Do not mark a payment as successful merely because the frontend redirected to a success URL. Verify it using the provider's confirmed transaction state or valid webhook flow.

For each gateway, deliver:

- Configuration form
- Connection validation where supported
- Create payment
- Verify payment
- Webhook handling
- Refund handling
- Error mapping
- Test cases
- Documentation

---

# 14. REQUIRED TECHNOLOGY STACK

## 14.1 Backend — Laravel

Create one professional Laravel backend project containing:

- REST APIs
- Business logic
- Country administration
- Authentication
- Authorization
- Laravel-based dashboards
- Seller onboarding
- Financial operations
- Background jobs
- Notifications
- Integrations
- API documentation

Use a modular monolith architecture initially.

Do not introduce microservices unless there is a clear technical reason and a documented architectural decision.

### Required backend engineering patterns

Use:

- Services
- Repositories
- DTOs
- Enums
- Form Requests
- Policies
- Middleware
- Events
- Listeners
- Jobs
- Notifications
- API Resources
- Actions / Use Cases where useful
- Dedicated calculators and formula classes
- Database transactions
- Domain-specific exceptions

Do not create abstractions without a real purpose.

### Example project organization

Adapt this structure to the Laravel version and existing repository:

```text
app/
  Domains/
    Countries/
    Identity/
    Customers/
    Catalog/
    Vendors/
    Marketplace/
    Classifieds/
    Travel/
    Affiliate/
    Influencers/
    Brokers/
    Advertising/
    Campaigns/
    FlashSales/
    Coupons/
    Wallet/
    GiftCards/
    Vouchers/
    Payments/
    Shipping/
    Delivery/
    LiveMedia/
    Orders/
    Bookings/
    Notifications/
    Support/
    Reporting/

  Shared/
    DTOs/
    Enums/
    Exceptions/
    Contracts/
    Helpers/
    Rules/
```

Each domain should contain appropriately separated:

- Models
- Repositories
- Services
- DTOs
- Enums
- Actions
- Calculators
- Events
- Listeners
- Jobs
- Policies
- Resources
- Requests

Keep folder organization consistent.

### Formula and calculation classes

Create dedicated, testable formula services such as:

- `ProductPriceCalculator`
- `DiscountCalculator`
- `CouponCalculator`
- `TaxCalculator`
- `ShippingFeeCalculator`
- `VendorCommissionCalculator`
- `AffiliateCommissionCalculator`
- `BrokerCommissionCalculator`
- `FlashSalePriceCalculator`
- `PaymentFeeCalculator`
- `WalletBalanceCalculator`
- `RefundCalculator`
- `SettlementCalculator`
- `AdvertisingBudgetCalculator`
- `TripPriceCalculator`

The exact class names may be improved, but calculations must remain centralized and independently testable.

Each calculator must document its inputs, outputs, rounding rules, and business assumptions.

### Database rules

- Use proper foreign keys.
- Use unique constraints where needed.
- Add indexes based on real query patterns.
- Use database transactions for money and inventory operations.
- Avoid N+1 queries.
- Avoid unbounded queries.
- Implement pagination.
- Use soft deletes only where appropriate.
- Preserve financial and audit history.
- Build country-specific migration and seeding strategies.
- Use queue jobs for expensive or asynchronous operations.

## 14.2 Laravel dashboard frontend

Build all dashboards using:

- Laravel Blade
- Tailwind CSS
- Pure JavaScript
- Select2 where suitable
- Yajra DataTables for server-side tables
- Additional libraries where justified

**Never use Livewire anywhere in this project.**

Do not use React or Next.js for the Laravel dashboards.

Build reusable Blade components for:

- Forms
- Tables
- Modals
- Filters
- Search
- Cards
- Statistics
- Charts
- Tabs
- Dropdowns
- Breadcrumbs
- Navigation
- Notifications
- File uploads
- Status labels

Use clean JavaScript modules instead of placing all JavaScript in one giant file.

All dashboard forms must have server-side validation and proper authorization.

## 14.3 Customer website — Next.js

Build the customer website as an independent Next.js project.

Use:

- Next.js App Router
- TypeScript
- Tailwind CSS
- A clean feature-based architecture
- Reusable components
- Shared UI foundations
- Typed API client
- Central API error handling
- Server-side rendering where appropriate
- Client-side interactions where appropriate
- SEO metadata
- Dynamic metadata
- Sitemap
- Structured data
- Image optimization
- Localization
- RTL support
- Responsive design

The frontend must communicate only with the Laravel API.

Organize the application into clear features such as:

```text
src/
  app/
  components/
  features/
    auth/
    home/
    catalog/
    search/
    product/
    cart/
    checkout/
    orders/
    customer-account/
    wishlist/
    classifieds/
    travel/
    affiliate/
    influencers/
    brokers/
    wallet/
    vouchers/
    gift-cards/
    campaigns/
    live-media/
    support/
  services/
  lib/
  hooks/
  types/
  config/
  i18n/
```

This is a starting proposal. Adapt it as necessary without sacrificing consistency.

## 14.4 Flutter mobile application

Use a professional feature-first / clean architecture.

Implement a unified Flutter codebase with role-aware application experiences where practical.

### Customer app

Build a full Noon-inspired shopping and services application.

Include:

- Homepage
- Navigation
- Search
- Categories
- Product details
- Product variants
- Cart
- Checkout
- Payment
- Orders
- Tracking
- Returns
- Wallet
- Vouchers
- Gift cards
- Classified marketplace
- Travel
- Trips
- Influencers
- Brokers
- Notifications
- Live media
- Customer profile
- Account settings

### Vendor app

Implement the essential mobile operations:

- Sales overview
- Orders
- Order status updates
- Products
- Inventory
- Notifications
- Basic analytics

### Marketer / influencer app

Implement:

- Dashboard
- Campaigns
- Affiliate products
- Links
- Sales
- Earnings
- Wallet
- Notifications
- Basic reports

### Shipping supervisor app

Implement:

- Driver overview
- Shipment dispatch
- Assignments
- Delivery status
- Notifications
- Operational summaries

### Delivery agent app

Implement the complete delivery-agent workflow described earlier.

Use:

- Central API handler
- Secure token handling
- Secure local storage
- Request interceptors
- Error handling
- Network handling
- Push notifications
- Deep linking
- Proper loading and empty states
- Role-based navigation

Evaluate a suitable state-management approach such as Riverpod or Bloc and use one consistent strategy throughout the application.

---

# 15. FIGMA — COMPLETE UI/UX DESIGN FIRST

Figma MCP is already connected.

You MUST use Figma MCP for the design process.

Do not jump directly into frontend coding with improvised layouts.

First inspect the available Figma tools and use the appropriate connected workflows.

## 15.1 Design principles

The customer experience should closely follow the structural patterns, layout logic, navigation behavior, information density, and shopping UX of the current Noon UAE website.

Create original platform branding and assets. Use Noon as a visual and UX reference, not as authorization to reproduce proprietary logos, copyrighted assets, or source code.

The design should feel like a mature, high-quality regional marketplace.

Requirements:

- Professional
- Modern
- Clean
- Commercial
- Responsive
- Consistent
- Accessible
- Fast to use
- Suitable for Arabic and English
- RTL and LTR
- Desktop and mobile

Do not use generic AI-generated dashboard layouts.

Avoid inconsistent spacing, random colors, arbitrary card styles, and unnecessary decorative elements.

## 15.2 Figma project structure

Create a complete, organized design system and separate design sections for:

### Section 1 — Design System

- Color tokens
- Typography
- Spacing
- Grid
- Breakpoints
- Border radii
- Shadows
- Icons
- Buttons
- Inputs
- Selects
- Dropdowns
- Tables
- Product cards
- Category cards
- Navigation
- Modals
- Alerts
- Badges
- Tabs
- Pagination
- Empty states
- Loading states
- Error states
- Form validation
- Responsive rules

### Section 2 — Customer website

Design the complete page inventory from the reference audit.

Include all major templates and their different states.

At minimum:

- Homepage
- Category page
- Subcategory page
- Search results
- Product listing
- Product details
- Brand page
- Deals page
- Flash sales
- Campaign landing page
- Classified listing page
- Classified details
- Classified seller profile
- Travel home
- Destination page
- Trip details
- Booking flow
- Influencer profile
- Affiliate product page
- Broker profile
- Real-estate details
- Cart
- Checkout
- Login
- Registration
- OTP
- Customer dashboard
- Orders
- Order details
- Returns
- Wallet
- Vouchers
- Gift cards
- Wishlist
- Notifications
- Profile settings
- Help and support

### Section 3 — Seller website

Create a complete Laravel seller acquisition experience:

- Landing page
- Seller benefits
- Registration type selector
- Country selector
- Registration wizard
- Documentation upload
- Business details
- Brand registration
- Product setup
- Fulfillment selection
- Application status
- Approval
- Rejection
- Additional documents request
- Success states

### Section 4 — Vendor dashboard

Design all vendor dashboard pages.

### Section 5 — Classified seller dashboard

Design all classified seller pages.

### Section 6 — Marketer and influencer dashboard

Design all marketing and affiliate pages.

### Section 7 — Broker dashboard

Design all real-estate management pages.

### Section 8 — Travel operator dashboard

Design all trip and booking management pages.

### Section 9 — Shipping company dashboard

Design the logistics dashboard.

### Section 10 — Delivery agent mobile experience

Design the complete mobile delivery workflow.

### Section 11 — Customer Flutter application

Design all essential customer mobile screens.

### Section 12 — Other mobile role experiences

Design minimal mobile operations for vendor, marketer, and shipping supervisor.

### Section 13 — Platform admin

Design a complete, professional administration experience.

## 15.3 Figma page inventory and traceability

Maintain a page inventory.

Every required UI route must have a corresponding design reference.

For every page, track:

- Page name
- User role
- Desktop frame
- Tablet frame, where relevant
- Mobile frame
- Arabic version
- English version
- RTL behavior
- Components used
- Prototype interactions
- Implementation status

Do not consider the design complete because the homepage and dashboard overview have been designed.

Use Figma components and variants instead of manually duplicating identical UI elements.

Use auto layout and reusable styles/tokens.

Where possible, build clickable prototypes for the most important journeys:

- Customer purchase
- Seller registration
- Product creation
- Vendor order processing
- Classified listing
- Trip booking
- Affiliate campaign participation
- Broker lead management
- Delivery completion

Before coding, produce a Figma design coverage report showing which requirements are covered by actual frames.

---

# 16. CANVA MCP — IMAGE AND MEDIA ASSET PIPELINE

Canva MCP is already connected.

Use Canva MCP to create or source professional visual assets.

Do not fill the website with random images or repeated placeholder images.

Create a documented asset plan.

Asset categories include:

- Homepage hero banners
- Campaign banners
- Category banners
- Product placeholder images
- Fashion images
- Beauty images
- Electronics images
- Home and lifestyle images
- Travel destinations
- Tourism banners
- Real-estate imagery
- Influencer campaign imagery
- Classified category visuals
- Empty-state illustrations
- Promotional visuals
- App onboarding visuals

Requirements:

1. Use high-quality, commercially appropriate assets.
2. Use licensed or otherwise authorized content.
3. Avoid visible watermarks.
4. Avoid duplicate images across unrelated product categories.
5. Maintain consistent image dimensions for similar placements.
6. Optimize images for web performance.
7. Use WebP or AVIF where suitable.
8. Preserve sufficient quality.
9. Create appropriate crops for desktop and mobile.
10. Organize the final assets by category and use case.

Use a predictable asset structure:

```text
assets/
  brand/
  homepage/
  categories/
  products/
  fashion/
  beauty/
  electronics/
  home/
  travel/
  real-estate/
  marketplace/
  campaigns/
  influencers/
  app/
  icons/
  empty-states/
```

Do not assume that an image has been saved to the codebase merely because Canva produced or displayed it. Verify its export and actual project location.

Use realistic sample content for development, but clearly distinguish demo data from production data.

---

# 17. CORE SYSTEM DESIGN REQUIREMENTS

## 17.1 Authentication and identity

Implement secure authentication for:

- Customers
- Vendors
- Classified sellers
- Marketers
- Influencers
- Brokers
- Travel operators
- Shipping companies
- Delivery agents
- Administrators
- Internal staff

Support:

- Email, where enabled
- Phone number
- OTP
- Password
- Session management
- Device management
- Password reset
- Account recovery
- Two-factor authentication where appropriate
- Account suspension
- Account verification
- Login history

Use strong role-based and permission-based authorization.

A person may have more than one approved business role where the business rules allow it.

Do not assume that one person can only ever be a vendor, influencer, or marketer.

Use role memberships and country-aware permissions instead of a rigid one-role-per-user schema.

## 17.2 Notifications

Implement a central notification system supporting:

- In-app notifications
- Email
- SMS
- Push notifications
- WhatsApp integration, if configured
- Real-time notifications

Examples:

- Order placed
- Payment received
- Order shipped
- Delivery update
- Return update
- Campaign invitation
- Commission earned
- Payout update
- Booking confirmation
- Listing approval
- Listing rejection
- New customer inquiry
- New broker lead
- Scheduled live session

Create notification templates with country and language support.

## 17.3 Real-time capabilities

Use Laravel Reverb or an appropriate real-time transport for:

- Chat updates
- Order notifications
- Shipment updates
- Dashboard alerts
- Live administrative events
- Relevant real-time marketplace interactions

Do not force real-time infrastructure into operations that are better handled by normal API requests or scheduled jobs.

## 17.4 Search

Design a scalable search architecture for:

- Products
- Categories
- Brands
- Classified listings
- Trips
- Properties
- Influencers
- Brokers

Evaluate Laravel database search, Meilisearch, or OpenSearch based on actual needs.

Support:

- Country filters
- Language
- Categories
- Price ranges
- Attributes
- Location
- Sorting
- Availability
- Sponsored results
- Popular searches
- Search analytics

Document how search indexes are maintained and rebuilt.

---

# 18. API DESIGN

Create versioned APIs.

Example:

`/api/v1/...`

Requirements:

- Consistent response format
- Standardized validation errors
- Pagination
- Filtering
- Sorting
- Authentication
- Authorization
- Rate limiting
- Request validation
- Idempotency for critical operations
- Country resolution
- API logging
- Proper HTTP status codes
- API documentation

Use OpenAPI or an equivalent documented API contract.

Separate public APIs from authenticated management APIs.

Do not expose internal admin endpoints to customers.

Document all mobile and web APIs.

---

# 19. SECURITY AND COMPLIANCE

Apply strong security practices.

Include:

- OWASP-aligned protections
- Secure authentication
- Secure authorization
- Input validation
- Output escaping
- CSRF protection where applicable
- XSS protection
- SQL injection protection
- Rate limiting
- File upload validation
- MIME and extension validation
- Secure file storage
- Secure secret management
- Encrypted sensitive data
- Secure webhooks
- Audit logs
- Session protection
- Country data isolation
- Backup strategy
- Data retention policies
- Suspicious transaction detection
- Financial operation authorization

Payment card information must be handled by the payment provider's secure flow. Do not store raw card numbers or CVVs.

Financial and wallet operations need a documented audit and reconciliation model.

Include appropriate privacy, consent, terms, and country-specific legal configuration points.

Do not treat a generic legal template as proof of regulatory compliance in every country.

---

# 20. PERFORMANCE AND SCALABILITY

Design for high traffic and large catalogs.

Requirements:

- Redis caching where appropriate
- Queue workers
- Background jobs
- Efficient database indexing
- Query optimization
- Pagination
- Media optimization
- CDN support
- Object storage
- Cache invalidation
- Search indexing
- Rate limits
- Monitoring
- Error tracking
- Database backup
- Queue monitoring
- Scheduled tasks

Optimize the customer site for SEO and Core Web Vitals.

Avoid loading large libraries unnecessarily.

Use lazy loading where appropriate.

Do not compromise financial consistency or authorization for performance.

---

# 21. TESTING — MANDATORY

A feature is not complete until its important behavior has been tested.

## 21.1 Backend tests

Use the test framework appropriate to the installed Laravel version.

Write tests for:

- Authentication
- Authorization
- Country isolation
- Product ownership
- Vendor management
- Product listing
- Inventory
- Stock reservation
- Cart
- Checkout
- Multiple sellers in an order
- Payment confirmation
- Duplicate webhooks
- Refunds
- Coupons
- Flash sales
- Wallet ledger
- Gift cards
- Affiliate attribution
- Cross-promotion rules
- Commission calculation
- Broker leads
- Trip booking
- Booking capacity
- Shipment assignment
- Delivery confirmation
- Payouts
- Advertising budgets

Include failure cases and unauthorized access attempts.

## 21.2 Frontend tests

Use appropriate testing tools for Next.js.

Test:

- Navigation
- Responsive behavior
- Authentication
- Search
- Product pages
- Cart
- Checkout
- RTL
- API failures
- Loading states
- Empty states
- Validation
- Customer account journeys

## 21.3 Dashboard tests

Test:

- Each role's access
- Page permissions
- CRUD operations
- Filters
- Tables
- Forms
- Approval workflows
- Financial summaries
- Country switching restrictions

## 21.4 Flutter tests

Test:

- Login
- API handling
- Secure token storage
- Role-based navigation
- Customer purchase
- Vendor operations
- Marketer operations
- Supervisor operations
- Driver workflow
- Push notifications
- Network failures

## 21.5 End-to-end scenarios

Create complete end-to-end scenarios:

1. Customer purchases a product from a vendor.
2. Customer purchases products from multiple vendors.
3. Vendor creates a coupon and customer redeems it.
4. Administrator launches a flash sale and vendor participates.
5. Influencer joins a campaign and earns a commission.
6. Marketer promotes another marketer's eligible product.
7. Marketer sells an independently owned product.
8. Customer books a travel package.
9. Customer contacts a real-estate broker.
10. Shipping company receives a shipment and assigns a driver.
11. Delivery agent collects and completes delivery.
12. Customer redeems a gift card.
13. Customer spends wallet credit.
14. Administrator creates a scheduled video and it appears at the scheduled time.
15. A payment fails and can be safely retried.
16. A payment webhook is delivered twice without duplicating the financial transaction.
17. A refund reverses the appropriate financial allocations.
18. A user from one country cannot access another country's operational data.

Create automated tests wherever practical.

---

# 22. DEVELOPMENT EXECUTION PHASES

Work through the following phases in order.

Do not skip phases.

## Phase 0 — Environment inspection

Inspect:

- Current workspace
- Existing repositories
- Installed PHP version
- Composer
- Laravel version, if present
- Node.js
- npm / pnpm
- Flutter
- Database availability
- Redis availability
- Existing environment configuration
- Available MCP integrations
- Figma connection
- Canva connection
- Browser and research tools

Create:

`docs/project/environment-audit.md`

Do not destroy an existing project.

## Phase 1 — Product discovery and reference research

Complete the reference audits.

Create a full feature inventory.

Identify all roles, their permissions, their workflows, and their data relationships.

Produce:

- Product requirements document
- Functional requirements
- Non-functional requirements
- Role matrix
- Page inventory
- User journeys
- Business rules
- Country requirements
- Risk register
- Open decisions

## Phase 2 — System architecture

Design:

- Architecture diagram
- Database topology
- Entity relationship diagrams
- Domain module map
- API structure
- Authentication architecture
- Country isolation
- Financial ledger architecture
- Payment adapters
- Shipping adapters
- Live media architecture
- Queue architecture
- Storage architecture
- Deployment architecture

Create appropriate architecture decision records.

Do not proceed with a vague architecture.

## Phase 3 — Figma design

Create the complete design system and application designs using Figma MCP.

Create all required page templates, role-specific screens, mobile frames, and critical prototypes.

Verify design coverage against the page inventory.

## Phase 4 — Canva assets

Create and export the required visual assets through Canva MCP.

Organize and verify actual exported files.

Create:

`docs/design/asset-inventory.md`

## Phase 5 — Laravel foundation

Build:

- Laravel project structure
- Country control plane
- Country-specific database connections
- Authentication
- Roles and permissions
- Dashboard routing
- Shared UI foundations
- API foundation
- Central settings
- Logging
- Queue infrastructure
- Notifications

Do not build all business modules in the same initial phase.

## Phase 6 — Core commerce

Implement:

- Catalog
- Brands
- Products
- Variants
- Inventory
- Vendors
- Listings
- Cart
- Checkout
- Orders
- Shipping calculation
- Coupons
- Flash sales

## Phase 7 — Payments and finance

Implement:

- Payment adapter system
- Provider integrations
- Webhooks
- Wallet
- Gift cards
- Vouchers
- Commission calculations
- Refunds
- Settlements
- Reconciliation

## Phase 8 — Additional marketplaces

Implement:

- Classifieds
- Travel
- Affiliate marketing
- Influencers
- Own-product selling
- Cross-promotion
- Brokers
- Real estate
- Advertising

## Phase 9 — Shipping and live media

Implement:

- Shipping companies
- Delivery supervisors
- Delivery agents
- Shipment operations
- COD
- Scheduled audio/video
- Live sessions

## Phase 10 — Laravel dashboards and seller portal

Complete all role dashboards and seller onboarding workflows.

Ensure each dashboard uses its correct permissions and country context.

## Phase 11 — Next.js customer website

Implement all customer-facing page templates and connect them to real Laravel APIs.

Build the audited Noon-inspired shopping experience and all additional business modules.

## Phase 12 — Flutter applications

Implement customer, vendor, marketer, supervisor, and delivery-agent experiences according to their defined scope.

## Phase 13 — Full integration and QA

Run:

- Unit tests
- Feature tests
- API tests
- Frontend tests
- Flutter tests
- End-to-end tests
- Security checks
- Performance checks
- RTL checks
- Cross-country isolation tests

Fix discovered issues.

Do not ignore failed tests or hide them from reports.

## Phase 14 — Deployment and final handover

Prepare:

- Production environment variables
- Deployment guide
- Domain and subdomain mapping
- SSL requirements
- Database setup
- Country database setup
- Migrations
- Queue workers
- Scheduler
- Redis
- Storage
- CDN
- Payment webhooks
- Streaming infrastructure
- Monitoring
- Backups
- Recovery procedure

Do not claim production readiness without checking the actual deployment dependencies and environment.

---

# 23. FINAL DOCUMENTATION AND DELIVERABLES

Maintain the following documentation:

```text
docs/
  project/
    environment-audit.md
    product-requirements.md
    functional-requirements.md
    non-functional-requirements.md
    role-permission-matrix.md
    business-rules.md
    country-strategy.md
    implementation-roadmap.md
    risk-register.md
    open-decisions.md

  reference-research/
    noon-customer-audit.md
    noon-seller-audit.md
    opensooq-audit.md
    boutiqaat-audit.md
    boksha-audit.md
    reference-page-inventory.md
    feature-comparison.md

  architecture/
    system-architecture.md
    database-topology.md
    database-er-diagram.md
    domain-modules.md
    api-architecture.md
    authentication.md
    country-isolation.md
    financial-ledger.md
    payment-architecture.md
    shipping-architecture.md
    live-media-architecture.md
    deployment-architecture.md
    decisions/

  design/
    figma-structure.md
    design-coverage.md
    component-inventory.md
    asset-inventory.md

  api/
    openapi.yaml
    api-usage.md

  testing/
    test-strategy.md
    test-results.md
    e2e-scenarios.md
    security-checklist.md

  deployment/
    installation.md
    production-deployment.md
    environment-variables.md
    backup-recovery.md
    domain-configuration.md

  operations/
    queues.md
    scheduled-jobs.md
    monitoring.md
    troubleshooting.md
```

Also maintain:

- `PROJECT_STATUS.md`
- `FEATURE_MATRIX.md`
- `IMPLEMENTATION_CHECKLIST.md`
- `KNOWN_ISSUES.md`
- `CHANGELOG.md`

The feature matrix must show:

- Requirement
- Responsible module
- Figma design status
- Backend status
- Dashboard status
- Next.js status
- Flutter status
- Test status
- Final status

Use clear status values:

- Not Started
- Researching
- Designed
- In Development
- Implemented
- Tested
- Blocked
- Not Applicable

---

# 24. DEFINITION OF DONE

A module is considered complete only when:

1. Its business requirements are documented.
2. Its design is available in Figma.
3. Its database structure exists.
4. Its backend services and APIs work.
5. Its authorization is implemented.
6. Its frontend or dashboard is connected.
7. Its important user journeys work.
8. Its validation and error handling are implemented.
9. Its automated tests pass.
10. Its documentation is updated.

A payment integration is not complete without verified webhook processing and transaction testing.

A financial feature is not complete without ledger integrity tests.

A marketplace is not complete without moderation, ownership, and seller workflows.

A delivery module is not complete without working shipment state transitions and driver workflows.

A mobile application is not complete because it opens successfully; its required business workflows must work.

---

# 25. WORKING STYLE AND COMMUNICATION

Work in structured milestones.

After each milestone:

- Summarize what was researched.
- Explain architectural decisions.
- List implemented features.
- List the modified files and modules.
- Report tests executed and actual results.
- Identify incomplete requirements.
- Update the project status files.
- Continue with the next logical task.

Avoid repeatedly asking questions whose answers are already present in this specification.

If a non-critical detail is missing, make a reasonable documented product decision and keep it configurable.

For high-impact architectural decisions, document the options and their implications before committing to an irreversible choice.

Do not spend time repeatedly explaining what you intend to do. Actually perform the work.

Do not replace actual implementations with pseudo-code, TODO comments, screenshots, or static demonstrations.

Do not report 100% completion without evidence from implemented files, working flows, and tests.

---

# 26. IMMEDIATE FIRST TASK

Start NOW with Phase 0 and Phase 1.

Your first actions must be:

1. Inspect the existing workspace and environment.
2. Discover and verify the available Figma MCP tools.
3. Discover and verify the available Canva MCP tools.
4. Identify the available browser/research tools.
5. Inspect the referenced websites and collect their accessible page structures.
6. Build the complete reference-page inventory.
7. Build the role and feature matrix.
8. Identify architectural risks and unresolved high-impact decisions.
9. Produce the initial product requirements and architecture documents.
10. Prepare the Figma project structure and design coverage plan.

Do not begin a superficial homepage implementation.

The first milestone must establish a verified understanding of the entire platform.

After completing that milestone, continue through the phases above with actual implementation.

**FINAL OBJECTIVE: Deliver a fully integrated, multi-country, multi-vendor commerce and services ecosystem with a Noon-inspired customer experience, a complete seller platform, multiple role-specific Laravel dashboards, a professional Next.js customer website, Flutter applications, reliable financial infrastructure, and production-grade testing and documentation.**