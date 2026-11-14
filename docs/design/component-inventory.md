# Component inventory — Figma file A "Nawy · Design System & Panels"
File: https://www.figma.com/design/d2MhBJmRddMrMSHfjJY8Y8 · Node IDs in `figma-state.json`.

Every visual property is bound to variables (colour light/dark, spacing, radius); text uses shared styles `en/*` (Inter) and `ar/*` (Cairo).

## Foundations (page *Foundations*)
| Asset | Count / notes |
|---|---|
| Colour primitives | 47 (amber = brand #F9BB25, navy ink, gray, green, red, blue, orange) |
| Semantic colours | 32 tokens × Light/Dark (`color/bg/*`, `color/text/*`, `color/border/*`), CSS syntax `var(--color-…)` |
| Spacing / Radius | 12 / 8 variables |
| Text styles | 13 roles × EN/AR = 26 |
| Effect styles | shadow/xs → xl |
| Brand/Logo mark | from `logo.webp` |

## Core components (page *Core components*)
| Component | Variants / properties | Blade (`<x-ui.*>`) | Next.js |
|---|---|---|---|
| Icon/* (51, Lucide) | stroke recolour via override | `<x-ui.icon name>` | `lucide-react` |
| Button | Style (Primary, Secondary, Outline, Ghost, Danger) × Size (SM/MD/LG) × State (Default/Disabled) = 30 · Label, Show icon, Icon swap | `button` | `Button` |
| Input | Default, Focus, Error, Disabled · Label, Value, Helper, Show helper, Show icon, Icon | `input` | `Input` |
| Select | Default, Open, Disabled · Label, Value | `select` (Select2) | `Select` |
| Checkbox / Radio / Switch | Off/On/Indeterminate · Off/On · On True/False | `checkbox`, `radio`, `switch` | same |
| Badge | Tone: Neutral, Brand, Success, Warning, Danger, Info · Label, Show dot | `status-badge` | `Badge` |
| Alert | Info, Success, Warning, Danger · Title, Message | `alert` (flash) | `Alert` |
| Tab, Avatar, Pagination item, Breadcrumb | — | `tabs`, `avatar`, `pagination`, `breadcrumb` | same |

## Panel kit (page *Panel kit*)
| Component | Notes |
|---|---|
| Sidebar item | Default/Active · Label, Icon, Show count, Count |
| Sidebar group label, Sidebar | 264px dark ink; "Panel name" property |
| Topbar | search ⌘K, **country/currency switcher** (Country prop), language EN/العربية, notifications, user |
| Page header | breadcrumb, Title, Subtitle, actions |
| Stat card | Trend Up/Down · Label, Value, Delta, Icon |
| Data table | Yajra server-side: toolbar (search, Select2 filter chips, columns, export), selectable rows, status badges, row actions, pagination |
| Modal, Empty state, Wizard step (Done/Current/Upcoming) | |
| **Payment method option** | Selected/Default/Disabled · Name, Description, Logo, Recommended — used in checkout + admin preview |

## Layouts
- **Panel shells (8):** Admin · Seller Center · Creator Studio · Marketplace (classifieds) · Broker · Travel Partner · Shipping · Ops — each with its own sidebar IA from MASTER_PLAN §5/§12.
- **Page templates (2):** List page (Seller Center → Products) · Form page (Admin → Oman → Payment gateways, with customer checkout preview; Thawani recommended).

## Not yet designed
Detail-page template, RTL (Arabic) mirror of the shells, dark-mode check of panels, website file B, apps file C.
