# WP AI Agent — Technical Documentation

> **Version documented:** 1.2.0
> **Type:** Universal AI chatbot for WordPress (with deep WooCommerce support)
> **Text domain:** `wp-ai-agent`  ·  **Option key:** `wp_ai_agent_options`  ·  **Function prefix:** `wp_ai_agent_`
> **Audience:** A developer who needs to understand the whole plugin without reading all 27 PHP files.

---

## Table of Contents

1. [Plugin Overview](#1-plugin-overview)
2. [Folder Structure](#2-folder-structure)
3. [File-by-File Explanation](#3-file-by-file-explanation)
4. [Function Reference](#4-function-reference)
5. [Class Diagram](#5-class-diagram)
6. [Database Structure](#6-database-structure)
7. [AI Workflow](#7-ai-workflow)
8. [Search Workflow](#8-search-workflow)
9. [Voice Workflow](#9-voice-workflow)
10. [Chat Workflow](#10-chat-workflow)
11. [Admin Workflow](#11-admin-workflow)
12. [API Flow](#12-api-flow)
13. [Execution Flow (Lifecycle)](#13-execution-flow-lifecycle)
14. [Dependency Diagram](#14-dependency-diagram)
15. [Security Review](#15-security-review)
16. [Performance Review](#16-performance-review)
17. [Code Quality Review](#17-code-quality-review)
18. [Suggested Improvements](#18-suggested-improvements)
19. [Future Architecture Recommendations](#19-future-architecture-recommendations)
20. [Overall Plugin Rating](#20-overall-plugin-rating)

---

## 1. Plugin Overview

### What is it?
WP AI Agent is a self-contained **AI chat widget** for any WordPress site. It answers visitor questions using the site's own content (pages, posts, products, FAQs, policies) and — on WooCommerce sites — acts as a **shopping assistant** (product search, filtering, comparison, order tracking, coupons, cart, image search). It adapts its persona and features to the *kind* of website it runs on (store, restaurant, clinic, school, blog, agency…).

### What problem does it solve?
- Gives small/medium sites a **24×7 support + sales assistant** without a SaaS subscription — it calls the site owner's own AI provider key (OpenAI / Gemini / Groq).
- **Grounds answers in the site's real content** (Retrieval-Augmented Generation), so the bot doesn't hallucinate off-topic answers. General-knowledge answering is off by default.
- Captures **leads, bookings, and support tickets** through conversation, and hands off to **WhatsApp / a human** when needed.

### Main Features
| Area | Capability |
|---|---|
| Conversation | Rule-based intent detection → deterministic tool routing; optional LLM fallback; multi-step flows (order lookup, lead, booking, ticket). |
| Commerce | Product ranking with hard facet filters (colour/gender/size/price/rating/discount/stock), comparison, sale/browse, categories, coupons, shipping, payments, cart, order tracking, **image (visual) product search**. |
| Retrieval | Custom content index + **semantic vector search** (embeddings) + live builder/ACF search. |
| Website Intelligence | Auto-detects site type → persona → enabled modules. |
| Memory | 24 h guest session restore; shopping-filter memory; "shown products" de-duplication. |
| Voice | Browser speech-to-text (ChatGPT-style auto-send + continuous conversation) and text-to-speech replies. |
| Admin | Settings, Training/Index, Q&A, **two-panel Conversation dashboard**, Analytics, Leads, Bookings, Tickets, Orders, Appearance. |
| Theming | 9 CSS variables, colour presets, auto-detect from theme, branding fields. |

### Architecture Overview
The plugin is **procedural** (function-prefixed), with only three small `class`es (`WP_AI_Agent_AI_Engine`, `WP_AI_Agent_Lead_Manager`, `WP_AI_Agent_Booking_Manager`). It is organised as layers:

```
Frontend widget (chat.js + template + chat.css)
        │  fetch()  (REST wp-ai-agent/v1/*)
        ▼
REST layer (api/rest-routes.php + handlers in includes/)
        ▼
Agent brain  ── tool-router.php ──► agent-tools.php (tools)
   ├─ intent-detection.php (rules)
   ├─ conversation-state.php (flow + memory transients)
   ├─ user-auth.php (identity/gating)
   └─ ai-engine.php (LLM provider calls — fallback + phrasing)
        ▼
Knowledge layer
   ├─ woocommerce-search.php (product ranking)
   ├─ content-indexer.php + universal-indexer.php (RAG index)
   ├─ embeddings.php (vector search)
   ├─ image-search.php (vision)
   └─ website-profile.php (site-type intelligence)
        ▼
Data layer (chat-handler.php + conversations.php + qa/lead/booking managers) → custom tables
```

### High-Level Workflow
```
Visitor types/speaks ─► chat.js ─► POST /chat ─► wp_ai_agent_handle_chat_request
   ─► wp_ai_agent_agent_respond():
        pending flow? → trained Q&A? → small talk? → shopping memory?
        → detect intent → route to tool → (LLM fallback if allowed)
   ─► normalized response {message, source, intent, data{products|list|actions}}
   ─► logged to wp_ai_conversations ─► rendered as bubbles/cards in the widget
```

---

## 2. Folder Structure

| Path | Responsibility |
|---|---|
| `wp-ai-agent.php` | **Bootstrap.** Constants, all `require_once` includes, activation, hooks, admin menu, asset enqueue, widget display gating, home cards / quick actions. |
| `includes/` | Core engine (non-admin): AI, agent brain, search, indexing, embeddings, image search, website profile, chat handler, conversation DB layer, appearance, managers. |
| `includes/agent/` | The "brain": `intent-detection.php`, `tool-router.php`, `agent-tools.php` (all tools), `conversation-state.php` (flow + memory), `user-auth.php`. |
| `api/` | `rest-routes.php` — registers all REST endpoints; two handlers defined inline. |
| `admin/` | All wp-admin screens (settings, analytics, conversations classic + dashboard, agent-admin, training). |
| `templates/` | `chatbot-widget.php` — the widget markup rendered in `wp_footer`. |
| `assets/js/` | `chat.js` — the entire frontend (single IIFE). |
| `assets/css/` | `chat.css` — widget styling + theme variables. |
| `docs/` | This documentation. |
| `db.php` | **NOT part of the plugin.** It is the third-party **Query Monitor `db.php` drop-in** that happens to sit in this directory. Ignore it for plugin logic. |

> There is **no** `languages/`, `modules/`, `helpers/`, `voice/`, `chat/`, or `search/` folder — those concerns live inside the files above. "Modules" is a *logical* concept (see Website Profile), not a folder.

---

## 3. File-by-File Explanation

### Root

#### `wp-ai-agent.php` — Bootstrap
- **Loads:** always (main plugin file).
- **Contains:** constants (`WP_AI_AGENT_VERSION` etc.), 26 `require_once` includes (order matters — see §13), `wp_ai_agent_activate()`, options defaults/sanitizer, admin menu, asset enqueue + `wp_localize_script`, widget display gating, home cards, quick actions.
- **Depends on:** every `includes/` and `admin/` file it requires.
- **If removed:** plugin does not exist.
- **Note:** it registers the `/chat` REST route a **second time** (duplicate of `api/rest-routes.php`) and includes a dangling "Hello World test route" comment — leftover debugging.

### `api/`

#### `api/rest-routes.php`
- **Loads:** always (required by bootstrap). Registers routes on `rest_api_init`.
- **Contains:** one closure registering 8 routes under `wp-ai-agent/v1`, plus two inline handlers: `wp_ai_agent_handle_search_debug_request` (admin-only) and `wp_ai_agent_handle_handoff_click_request`.
- **Depends on:** handler functions in `chat-handler.php`, `content-indexer.php`, `image-search.php`, `conversations.php`.
- **If removed:** the widget cannot talk to the server.

### `includes/` (core)

| File | Purpose | Key note |
|---|---|---|
| `ai-engine.php` | `WP_AI_Agent_AI_Engine` class — calls OpenAI/Gemini/Groq; builds persona system prompt; RAG user prompt. | Only used for the general fallback + phrasing, not the primary path. No response caching. |
| `chat-handler.php` | Main `/chat` handler, conversations table schema + logging, legacy responder, search-debug, price context, client IP. | Owns the `ai_conversations` table. |
| `conversations.php` | Read/manage layer for conversations: history, delete, the new dashboard queries, and the whole analytics data layer. | DB schema version = **4**. |
| `qa-manager.php` | Trained Q&A: table, CRUD, token-overlap matcher (`match_custom_qa`), admin screen, feeds the index. | Threshold 0.6. |
| `woocommerce-search.php` | Product ranking pipeline + hard facet filters. | Loads up to 200 products/query. |
| `image-search.php` | Vision-based visual product search (Woo-only). | Same-type-only matching. |
| `content-indexer.php` | Legacy transient index + search/scoring + NLP helpers (tokenizer, synonyms, `retrieve_context`). | RAG entry `wp_ai_agent_retrieve_context`. |
| `universal-indexer.php` | Primary DB content index (`ai_content_index`): collectors, rebuild, candidate retrieval, categorized content. | Truncate + row-by-row insert. |
| `embeddings.php` | Vector embeddings (OpenAI/Gemini) + cache table + cosine search. | In-PHP cosine over all rows. |
| `website-profile.php` | Website Intelligence: type detection → persona → modules; commerce gating; type directory. | Cached 1 day. |
| `appearance.php` | Colour presets, CSS-var generation, branding, auto-detect. | Injects inline CSS after `chat.css`. |
| `lead-manager.php` | `WP_AI_Agent_Lead_Manager::add_lead()` (thin). | Table created elsewhere (bootstrap). |
| `booking-manager.php` | `WP_AI_Agent_Booking_Manager::create_booking()` — **stub** (returns "not configured"). | Real booking flow lives in `agent-tools.php`. |
| `whatsapp.php` | `wp_ai_agent_send_whatsapp()` — builds a `wa.me` deep link (no API). | |

### `includes/agent/` (the brain)

| File | Purpose |
|---|---|
| `intent-detection.php` | `wp_ai_agent_detect_intent()` — ~38 ordered regex rules (English + Hinglish) → `{intent, confidence, entities}`; entity extraction. |
| `tool-router.php` | `wp_ai_agent_agent_respond()` orchestration + `wp_ai_agent_route_intent()` switch + shopping-memory + pending-flow helpers. |
| `agent-tools.php` | **~5,600 lines / ~115 functions.** Every `wp_ai_agent_tool_*` implementation, agent-table schema, dynamic contact/business-info discovery, product cards. |
| `conversation-state.php` | Two transient stores: pending-flow state (30 min) and preferences/shop context (24 h); "shown products" cap. |
| `user-auth.php` | Identity, login/register/logout/account tools (link out to real WP pages), account gating. |

### `admin/`

| File | Screen(s) | Save mechanism |
|---|---|---|
| `settings.php` | AI Agent Settings | Settings API (`options.php`). |
| `training.php` | Training / Content Index | Self-posting forms (reindex, re-detect profile). |
| `analytics.php` | Analytics dashboard | Read-only + admin-post exports (CSV/XLS/report) + clear logs (TRUNCATE). |
| `conversations.php` | **Classic** conversations list (legacy — superseded). | admin-post delete/bulk-delete; CSV export. |
| `conversation-dashboard.php` | **New two-panel conversation manager.** | AJAX (thread/delete/archive) + admin-post (export JSON/CSV, print). |
| `agent-admin.php` | Leads, Bookings, Tickets, Orders | admin-post status updates + CSV exports. |

### `templates/` & `assets/`
- `templates/chatbot-widget.php` — launcher + panel (shared header, Home view, Chat view, bottom nav with center Voice). Inline robot SVG avatar.
- `assets/js/chat.js` — single IIFE; session mgmt, views, send flow, rendering, history restore, image search, full voice assistant.
- `assets/css/chat.css` — theme variables, layout, components, nav, voice animations, responsive (voice hidden ≥1025 px). **No dark mode** (theming is via `--wpaia-*`).

---

## 4. Function Reference

> The plugin has ~300 functions. This section lists the **most important** per subsystem with purpose, key inputs, and output. See each file's docblocks for the exhaustive list.

### Bootstrap (`wp-ai-agent.php`)
| Function | Purpose | In → Out |
|---|---|---|
| `wp_ai_agent_activate()` | Create all tables, build index + profile on activation. | — |
| `wp_ai_agent_get_options()` | Merged options array. | → array |
| `wp_ai_agent_get_active_api_key($options)` | Resolve `api_key_{provider}` → legacy fallback. | → string |
| `wp_ai_agent_should_display_widget()` | Gate widget by type + exclude IDs. | → bool (filter `wp_ai_agent_should_display`) |
| `wp_ai_agent_enqueue_frontend_assets()` | Enqueue chat.css/js + `wp_localize_script('wpAiAgentData', …)`. | — |
| `wp_ai_agent_get_home_cards()` / `_get_quick_actions()` | Build starter cards / suggestion chips (cached 1 h). | → array |

### AI Engine (`ai-engine.php`, class `WP_AI_Agent_AI_Engine`)
| Method | Purpose | In → Out |
|---|---|---|
| `ask($prompt, $context='', $mode='match')` | Public entry; dispatch to provider. | → answer string |
| `build_system_prompt()` | Persona + mode (general/overview/match) system prompt. | → string |
| `build_user_prompt($prompt,$context)` | Wrap RAG context + question. | → string |
| `resolve_model($provider)` | Guard model/provider mismatch. | → model id |

### Intent + Routing (`intent-detection.php`, `tool-router.php`)
| Function | Purpose |
|---|---|
| `wp_ai_agent_detect_intent($message)` | Ordered regex rules → `{intent, confidence, entities}`. First match wins. |
| `wp_ai_agent_extract_entities($message)` | `{order_number, email, phone, name}`. |
| `wp_ai_agent_agent_respond($message,$session_id,$page_url)` | **Top orchestrator** (see §7). |
| `wp_ai_agent_route_intent($intent, …)` | Switch intent → tool; directory + commerce gating first. |
| `wp_ai_agent_maybe_continue_shopping(…)` | Continue/refine an active product search from memory. |
| `wp_ai_agent_update_shopping_context(…)` | Sync/clear shopping filters after each turn. |

### Tools (`agent-tools.php`) — selected
| Function | Returns |
|---|---|
| `wp_ai_agent_tool_response($msg,$args)` | Standard shape `{message,handled,source,intent,pending,matched,data}`. |
| `wp_ai_agent_tool_product(…)` | `data.products` (main product search). |
| `wp_ai_agent_tool_compare(…)` | 2 cards + recommendation. |
| `wp_ai_agent_tool_sale_products` / `_browse_products` | `data.products` (sale / general browse). |
| `wp_ai_agent_tool_categories` / `_catalog` | `data.list` / `data.products`. |
| `wp_ai_agent_tool_order` / `_order_begin_lookup` / `_order_continue` | Order tracking flow (email-gated). |
| `wp_ai_agent_tool_lead` / `_booking` / `_ticket` | Multi-step capture flows (`pending=true`). |
| `wp_ai_agent_tool_contact` / `_social` | Dynamically discovered contact / social + newsletter. |
| `wp_ai_agent_tool_shipping` / `_payment` / `_coupons` / `_cart` | Store info tools. |
| `wp_ai_agent_tool_business_info` / `_products_overview` / `_information` | RAG "about"/overview/generic answers. |
| `wp_ai_agent_tool_directory` | Non-Woo listings (menu, doctors, courses, rooms…). |
| `wp_ai_agent_discover_email/_phone/_hours/_social` + `newsletter_status` | Per-site discovery, cached 12 h, **no hardcoding**. |

### Search & Index
| Function | Purpose |
|---|---|
| `wp_ai_agent_wc_rank_products($msg,$limit,&$type,&$total)` | Product ranking pipeline (facets + scoring). |
| `wp_ai_agent_wc_filter_by_facets(…)` | Hard colour/gender/size enforcement. |
| `wp_ai_agent_retrieve_context($query)` | RAG: semantic + keyword + live → `{context, mode, has_match}`. |
| `wp_ai_agent_universal_search` / `_semantic_search` / `_live_search` | Merged / vector / builder-aware search. |
| `wp_ai_agent_rebuild_index()` | Truncate + reinsert `ai_content_index` + re-embed. |
| `wp_ai_agent_get_website_profile($refresh)` | Detect type → persona → modules (cached). |
| `wp_ai_agent_handle_image_search_request($req)` | Vision → keywords → same-type product match. |

### Data (`chat-handler.php`, `conversations.php`)
| Function | Purpose |
|---|---|
| `wp_ai_agent_handle_chat_request($req)` | `/chat` endpoint. |
| `wp_ai_agent_log_conversation(…)` | Insert a turn (now with user_id/ip/ua/status/admin_read). |
| `wp_ai_agent_conversation_sessions($args)` | Dashboard grouped list. |
| `wp_ai_agent_conversation_thread` / `_meta` | Dashboard thread + header. |
| `wp_ai_agent_set_session_status` / `_mark_session_read` | Archive / read. |
| `wp_ai_agent_analytics_summary` / `_top_questions` / `_trends` … | Analytics data layer. |

---

## 5. Class Diagram

The codebase is ~97% procedural. Only three classes exist:

```
┌─────────────────────────────┐
│   WP_AI_Agent_AI_Engine     │  includes/ai-engine.php
├─────────────────────────────┤
│ - options (array)           │
├─────────────────────────────┤
│ + __construct()             │
│ + ask(prompt,context,mode)  │
│ + force_ipv4… (static hook) │
│ - call_openai/groq/gemini() │
│ - send_request()/send_gemini│
│ - build_system_prompt()     │
│ - build_user_prompt()       │
│ - resolve_model()           │
└─────────────────────────────┘
   used by: tool-router (fallback), agent-tools (phrasing)

┌───────────────────────────┐      ┌────────────────────────────┐
│ WP_AI_Agent_Lead_Manager  │      │ WP_AI_Agent_Booking_Manager │
├───────────────────────────┤      ├────────────────────────────┤
│ + add_lead($data)         │      │ + create_booking($data)     │  ← STUB
└───────────────────────────┘      └────────────────────────────┘
```

> The lead/booking managers are thin; the **real** lead/booking/ticket logic is the procedural `wp_ai_agent_tool_*` flow in `agent-tools.php`. Everything else — intent, routing, tools, search, admin — is plain functions communicating via the `wp_ai_agent_tool_response()` array shape.

---

## 6. Database Structure

All tables are prefixed `{$wpdb->prefix}` (shown here with the default `wp_`). Created via `dbDelta`.

### `wp_ai_conversations` — chat log *(chat-handler.php, schema v4)*
| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned PK AI | |
| `session_id` | varchar(64) | guest/session id |
| `user_id` | bigint unsigned (0=guest) | **v4** |
| `page_url` | varchar(255) | |
| `user_message` | text | |
| `bot_message` | text | |
| `response_ms` | int unsigned | reply time |
| `ip_address` | varchar(45) | **v4**, best-effort |
| `user_agent` | varchar(255) | **v4**, device/browser derivation |
| `status` | varchar(20) `active`/`archived` | **v4** |
| `admin_read` | tinyint | **v4**, unread badge |
| `created_at` | datetime (site-local) | |

Indexes: PK(id), `session_id`, `page_url`, `session_page(session_id,page_url(100))`, `created_at`, `user_id`, `status`. Upgrades run on `admin_init` via version option `wp_ai_agent_conv_db_version`.

### `wp_ai_qa` — trained answers *(qa-manager.php)*
`id` PK · `question` text · `answer` longtext · `hits` bigint · `created_at`. Index: PK only.

### `wp_ai_agent_leads` — captured leads *(created in bootstrap `wp_ai_agent_create_tables`)*
`id` PK · `name`(191) · `email`(191) · `phone`(50) · `message` · `lead_source`(='chat') · `page_url`(255) · `session_id`(64) · `lead_status`(='new') · `score` tinyint · `created_at`. Keys: `lead_status`, `created_at`.

### `wp_ai_content_index` — RAG index *(universal-indexer.php, version 5)*
`id` PK · `content_type`(50) · `post_id` · `title` text · `content` longtext · `source`(50) · `url`(255) · `embedding` longtext NULL (JSON vector) · `last_updated`. Keys: PK, `content_type`, `post_id`, `source`.

### `wp_ai_embeddings_cache` — vector cache *(embeddings.php)*
`hash` char(32) PK (md5 of embed text) · `vector` longtext (JSON) · `updated_at`.

### Agent tables *(agent-tools.php `wp_ai_agent_create_agent_tables`)*
`ai_agent_bookings`, `ai_agent_tickets`, `ai_agent_order_logs`, `ai_agent_handoffs` — created/upgraded on `admin_init` (version option `wp_ai_agent_agent_tables_ready`, currently `4`).

### Transients (not tables)
`wp_ai_agent_content_index` (12 h) · `wp_ai_agent_website_profile` (24 h) · `wp_ai_agent_quick_actions` (1 h) · discovery caches: `..._phone_number/_business_email/_business_hours/_social_links/_newsletter` (12 h) · flow state `wp_ai_agent_flow_{md5(session)}` (30 min) · prefs/shop context `wp_ai_agent_prefs_{md5(session)}` (24 h).

### Relationships
`session_id` is the join key across `ai_conversations`, flow/prefs transients, and lead rows. `user_id` links to `wp_users`. `post_id` in `ai_content_index` links to `wp_posts`/terms. Embeddings join `ai_content_index.embedding` ⇆ `ai_embeddings_cache` by content hash.

---

## 7. AI Workflow

```
User message
  │ chat.js POST /chat  {message, session_id, page_url}
  ▼
wp_ai_agent_handle_chat_request()
  │ sanitize + length cap (500) ; start timer
  ▼
wp_ai_agent_agent_respond(message, session_id, page_url)
  1. extract entities (order#, email, phone)
  2. PENDING FLOW  → continue order/lead/booking/ticket (unless cancel / topic change)
  3. TRAINED Q&A   → wp_ai_agent_match_custom_qa()  (exact or ≥0.6 token overlap)
  4. SMALL TALK    → greetings/thanks/goodbye
  5. SHOPPING MEMORY → refine active product search ("red", "under $100", "compare them")
  6. DETECT INTENT → wp_ai_agent_detect_intent()  (+ filter wp_ai_agent_detected_intent)
  7. contact+email/phone in a website_info/faq msg → promote to lead_generation
  8. ROUTE INTENT  → wp_ai_agent_route_intent()
        ├─ directory gate (non-store verticals)
        ├─ commerce gate (store-only intents blocked on non-stores)
        └─ switch(intent) → wp_ai_agent_tool_*()
  9. (if route returns null) LLM FALLBACK — only if allow_general_ai=1 AND provider configured
 10. (else) WhatsApp handoff → not-found response
  ▼
Response array {message, source, intent, matched, data{products|list|actions}}
  ▼
log to wp_ai_conversations (user_id/ip/ua captured) → JSON to browser → render
```

**Where the LLM is actually called:** only (a) the *general* fallback in step 9, (b) `wp_ai_agent_tool_business_info` / `_information` phrasing (RAG answers), and (c) image-search vision. **Primary commerce/support flows are deterministic** (no token cost).

**Prompt building (`ai-engine.php`):** system prompt = persona (from Website Profile) + one of three modes — `general` (chit-chat allowed), `overview` (describe site), `match` (answer only from provided context; emit exact fallback if context empty/unrelated). User prompt fences the retrieved context. Temperature `0.2`. Model auto-corrected per provider.

---

## 8. Search Workflow

### Product search (WooCommerce)
```
message → wc_parse_intent (price bounds, sort, best/related)
        → wc_extract_numeric_filters (rating/discount/in-stock/qty — HARD)
        → wc_query_keywords (remove ~150 stop/filler words + numbers)
        → load ≤200 products
        → per-product HARD filters (price/stock/rating/discount/qty)  [drop on fail]
        → score by field weight (name 200 > cat 140 > attr 90 > tag 60 > desc 12;
                                 exact title +1000, SKU +600, slug +300)
        → TYPE gate (must match type keyword in name/cat/tag/attr, never desc;
                     strict = hyphen-aware so "shirt" ≠ "t-shirt")
        → facet filter (colour/gender/size MANDATORY; gender never relaxes)
        → order (best→sales, asc/desc→price, else relevance) → in-stock float
        → exact rows preferred over "similar"; display cap 8
```
Design choices: "under $X" sets a *bound only* (no cheapest-first sort) to avoid surfacing $0 items; gift cards (price ≤ 0) are dropped when a price bound exists.

### Content search (RAG) — `wp_ai_agent_retrieve_context()`
```
universal_search (semantic embeddings ∪ keyword index)  ─┐
live_search (fresh WP + Elementor/Bricks/ACF)            ─┤→ dedupe
   if top relevance ≥ 0.2 or live has hits → mode = match  (answer from context)
   else if overview query → mode = overview (general_context)
   else → mode = none (strict: LLM NOT called, show not-found message)
```

### Semantic (vector) search — `embeddings.php`
Query embedded (OpenAI `text-embedding-3-small` / Gemini `text-embedding-004`) → cosine similarity vs every indexed row's stored vector in PHP → threshold 0.25. Content vectors cached by md5 so unchanged content isn't re-embedded.

### Image search — `image-search.php`
Vision model describes the photo → 6–12 keywords → **same-type-only** product match (name/cat/tag/attr) + semantic boost; never returns a different product type.

### FAQ / pages / categories
No dedicated FAQ engine — FAQs are `custom_post_type` rows whose title contains "faq" and surface via the normal keyword/semantic index; categories via `tool_categories`; pages via `tool_navigation` and the index.

### Priority & fallback
Trained Q&A → small talk → shopping memory → intent tool (commerce/content) → LLM general (if enabled) → WhatsApp → honest not-found.

---

## 9. Voice Workflow

Browser Web Speech API only — no backend change. Config from `wpAiAgentData` (`voice`, `voiceReply`, `manualSend`, `speechRate/Pitch/Volume`, `lang`).

```
Tap 🎤 (mobile/tablet only — hidden ≥1025px)
  → stopSpeaking() (cancel any TTS)
  → SpeechRecognition (continuous, interimResults)
  → mic pulse + floating badge "🎤 Listening…"
  → 2.5s silence  OR  second tap  → rec.stop()
  → transcript captured
      AUTO mode (default): input stays empty → pendingVoiceSend=true → submitQuery()
                           → user bubble appears → AI reply → "🧠 Processing…"
      MANUAL mode: transcript dropped in input for review
  → if voiceReply on: speakReply() (TTS, URLs stripped) → "🔊 Speaking…" → "✓ Ready"
      → maybeResumeVoice(): re-open mic 700ms later (continuous conversation)
```
**Interrupts** (new recording / new message / new chat / close / page change) all call `speechSynthesis.cancel()` and `stopListening()`; only one recording and one playback ever exist. Errors show "I couldn't hear anything / understand your request" and never send empty messages. Status is shown **outside** the input (floating badge), never in the placeholder.

---

## 10. Chat Workflow

| Step | Mechanism |
|---|---|
| Widget load | `wp_footer` → `chatbot-widget.php`; only if `should_display_widget()`. |
| Session | `getSessionId()` — localStorage `guest_…` id, 24 h sliding window (`touchSession`). |
| History restore | On open, GET `/history?session_id&hours=24` → replay + "Welcome back" + resume summary. |
| Message send | `submitQuery` → append human bubble + loading bubble → POST `/chat` → `resolveMessage`. |
| Rendering | Text bubbles; `data.products` → cards (**View only**); `data.list` → tappable rows; `data.actions` → chips. Bot URLs become DOM link buttons (no innerHTML of message text). |
| History save | Server-side only (every turn logged). Client history is **ephemeral/in-memory**; localStorage persistence is disabled (see dead code note). |
| New chat | `startNewChat()` — new session id (server copy kept), fresh Chat view. |
| Clear chat | `clearChat()` — POST `/clear-history` (delete server rows + state + prefs) then new id. |
| Guest vs logged-in | Same session mechanism; logged-in users are linked by `user_id` at log time; account-gated tools check WP login. |

---

## 11. Admin Workflow

| Screen | Purpose | Data / Actions |
|---|---|---|
| **Settings** | Provider keys, model, chat/voice/display/WhatsApp/general-AI toggles. | Settings API; sanitizer in bootstrap. |
| **Training** | Website-intelligence panel + content-index stats + rebuild / re-detect. | Self-posting nonce forms → `rebuild_index()`, `get_website_profile(true)`. |
| **Q&A** | Trained answers CRUD. | `wp_ai_qa` table; feeds the index. |
| **Conversations (dashboard)** | Two-panel manager: session list (search + range/user-type/status/unread filters + pagination) → AJAX thread (bubbles, date separators, device/browser/IP/page details) → delete / archive / export JSON+CSV / print-PDF / copy. | AJAX `wpaia_conv_thread/delete/archive`; admin-post `wpaia_conv_export/print`; all nonce + `manage_options`. |
| **Conversations (classic)** | Legacy list table (superseded by the dashboard). | admin-post delete/bulk-delete + CSV. |
| **Analytics** | 11 overview cards, trend bar charts, top questions/pages/product searches/failed, recent. | Read-only + CSV/XLS/report exports + clear (TRUNCATE). |
| **Leads / Bookings / Tickets / Orders** | Review captured data. | Status updates + CSV; Orders reads `wc_get_orders` + tracking log. |
| **Appearance** | Colour presets, pickers, branding, live preview, auto-detect. | Its own option `wp_ai_agent_appearance`; injects inline CSS. |

---

## 12. API Flow

Namespace `wp-ai-agent/v1`. All handlers return `WP_REST_Response`.

| Route | Method | Permission | Handler | Purpose |
|---|---|---|---|---|
| `/chat` | POST | `__return_true` | `wp_ai_agent_handle_chat_request` | Main chat turn. |
| `/search-content` | GET/POST | `__return_true` | `wp_ai_agent_handle_search_content_request` | Categorized content search. |
| `/log-conversation` | POST | `__return_true` | `wp_ai_agent_handle_log_conversation_request` | Legacy client logging. |
| `/image-search` | POST | `__return_true` | `wp_ai_agent_handle_image_search_request` | Visual product search. |
| `/history` | GET/POST | `__return_true` | `wp_ai_agent_handle_history_request` | Restore last 24 h. |
| `/clear-history` | POST | `__return_true` | `wp_ai_agent_handle_clear_history_request` | Delete a session. |
| `/handoff-click` | POST | `__return_true` | `wp_ai_agent_handle_handoff_click_request` | Log WhatsApp click. |
| `/search-debug` | GET/POST | `manage_options` | `wp_ai_agent_handle_search_debug_request` | Admin retrieval trace. |

**Request→response contract** (`/chat`):
```
Request : { message*, session_id, page_url }
Validate: sanitize_text_field(message) → cap 500; esc_url_raw(page_url); sanitize session
Process : wp_ai_agent_agent_respond()
Response: { message, source, matched, intent, data? , debug? }
Errors  : empty message → gentle 200 (matched:false); no exceptions surfaced to client
```
**AJAX (dashboard):** `admin-ajax.php?action=wpaia_conv_*` with `_ajax_nonce` (`wpaia_conv`) + `manage_options`; JSON via `wp_send_json_*`.

---

## 13. Execution Flow (Lifecycle)

```
WordPress loads plugin (wp-ai-agent.php)
  → define constants
  → require_once 26 files (order: indexers → embeddings → qa → wc-search → image →
     profile → appearance → ai-engine → chat-handler → conversations → managers →
     agent/* → api/rest-routes → admin/*)
  → register hooks:
       admin_menu       → add_admin_pages
       admin_init       → register_settings, maybe_create_agent_tables,
                          maybe_upgrade_conversations, maybe_upgrade_index,
                          register_appearance_settings
       wp_enqueue_scripts → enqueue_frontend_assets (+ appearance inline CSS @20)
       wp_footer        → render_chat_widget
       rest_api_init    → register 8 routes
       save_post/term   → schedule reindex (debounced +30s)
  (activation once)     → create tables + full index + profile

Front-end request:
  wp_enqueue_scripts → should_display_widget()? → enqueue chat.css/js + localize
  wp_footer          → render widget markup
  Visitor opens widget → restore history → sends message → /chat → agent → render

Admin request:
  admin_init upgrades run → admin_menu builds pages → screen renders
```

---

## 14. Dependency Diagram

```
chat.js ──POST──► /chat (rest-routes.php)
                     │
                     ▼
             chat-handler.php ──► tool-router.php ──► agent-tools.php
                     │                  │                   │
                     │                  ├─ intent-detection.php
                     │                  ├─ conversation-state.php (transients)
                     │                  ├─ user-auth.php
                     │                  └─ ai-engine.php ──► OpenAI/Gemini/Groq
                     │
   agent-tools.php ──┼──► woocommerce-search.php ──► content-indexer.php (tokenizer/synonyms)
                     ├──► image-search.php ──────────► embeddings.php ──► ai_embeddings_cache
                     ├──► content-indexer.php ──► universal-indexer.php ──► ai_content_index
                     └──► website-profile.php (persona/modules/commerce gate)

conversations.php ◄── chat-handler.php (logging)  ──► admin/conversation-dashboard.php
appearance.php ──► inline CSS after chat.css
```
Load-order dependency: `wp_ai_agent_activate()` calls table/index functions defined in `includes/*`, so those must be required **before** activation runs (they are, by include order).

---

## 15. Security Review

| Area | Status | Notes |
|---|---|---|
| **REST auth** | ⚠️ **Weak** | 7 of 8 routes use `permission_callback => '__return_true'`. `/history` and `/clear-history` act on any client-supplied `session_id` → **IDOR** (read/delete another visitor's session if the id is guessed/leaked). `/chat` & `/image-search` are unauthenticated and hit paid APIs → **cost/DoS amplification**. A `wp_rest` nonce is localized but **not verified** by any route. |
| **Capability checks** | ✅ mostly | All admin *actions* check `manage_options` + nonces. Two page renderers (`settings.php`, `training.php`) rely on menu cap only (no in-function `current_user_can`). |
| **Nonces** | ✅ admin / ⚠️ REST | Admin AJAX + admin-post are nonce-protected. REST is deliberately nonce-less (public endpoints). |
| **SQL** | ✅ | Values parameterised via `$wpdb->prepare` + `esc_like`; orderby/columns whitelisted; dynamic IN-lists build placeholders. Table names interpolated but internal (safe). |
| **Escaping** | ✅ | Admin output escaped (`esc_html/attr/url`, `wp_kses_post`, `nl2br(esc_html())`). JS builds bot links via `createTextNode` (no innerHTML of message text). Intentional raw output: static robot SVG. |
| **Secrets** | ⚠️ | Provider API keys stored plaintext in options; Gemini key passed as `?key=` query param (can leak into access logs). Upstream error bodies echoed to visitors. |
| **PII / retention** | ⚠️ | IP, user-agent, full messages stored indefinitely; no retention pruning, no GDPR export/erase. Password masking depends on tools setting `log_user` (see stale docblock). |
| **File/PDF parsing** | ⚠️ minor | Indexer parses uploaded DOCX/PDF (regex + `@gzuncompress`) — mild DoS surface, capped at 5 MB / 50 k chars. |

**Top security priorities:** (1) tie `/history` & `/clear-history` to session ownership or a nonce; (2) rate-limit `/chat` & `/image-search`; (3) move Gemini key to header or accept the documented risk; (4) don't echo upstream error bodies.

---

## 16. Performance Review

| Concern | Detail | Mitigation present |
|---|---|---|
| Product ranking cost | Loads ≤200 `WC_Product` objects/query, each doing multiple `wp_get_post_terms` (cats/tags/brand/attrs) — dozens–hundreds of uncached term queries per request. Image match loads 300. | Limits filterable; no object cache layer. |
| Semantic search | Loads **every** row's embedding and computes cosine in PHP (O(n·dim)); no ANN/vector index. | Threshold + cache for generation only. |
| Index rebuild | `TRUNCATE` + row-by-row `$wpdb->insert` (no batch/transaction); index briefly empty mid-rebuild; collects all products with reviews. | Debounced 30 s single event. |
| Candidate LIKE | `title/content LIKE '%…%'` (leading wildcard) on `longtext`, no FULLTEXT index. | Candidate cap 200. |
| Quick actions | First uncached front-end hit runs `get_posts(50)` + categories + counts. | Hourly transient. |
| External HTTP on request | `scan_business_email` / `newsletter_status` may `wp_remote_get(home_url())` (up to 8 s) on cache miss. | 12 h cache. |
| Admin exports | Leads/bookings CSV load up to 100 000 rows into memory. | none — OOM risk. |
| Asset loading | Single chat.js/css, versioned, footer-loaded. Admin files required on **every** request (no `is_admin()` gate). | minor. |

---

## 17. Code Quality Review

### Unused / dead code
- `db.php` — third-party Query Monitor drop-in, not plugin code.
- Duplicate `/chat` route registration (bootstrap + rest-routes.php).
- `admin/conversations.php` (classic) vs `admin/conversation-dashboard.php` — both claim the same slug; the classic screen is superseded.
- `chat.js`: header three-dot menu handlers, `contactBtn`, `showWelcome`/`isGreeting`, and `STORAGE_KEY`/`saveHistory` "localStorage cache" machinery reference DOM/behaviour not present → dormant/misleading. CSS `.wp-ai-agent-cart-btn/.hero/.header/.contact-btn` have no live markup.
- `ai-engine.php`: `is_gemini_api_key()` never called; the Gemini branch inside `send_request()` is effectively unreachable/incorrect.

### Duplicate patterns
- The options/postmeta/theme-mods/Elementor **blob-scan loop** is copy-pasted across phone, email, social, and newsletter discovery.
- Near-identical `set_state(… pending=true)` boilerplate across lead/booking/ticket flows.

### Large functions / files
- `agent-tools.php` — ~5,600 lines / ~115 functions in one file (schema + DB + discovery + parsing + presentation).
- `wp_ai_agent_detect_intent()` — ~38 sequential regex rules, order-sensitive, ReDoS-prone.
- `wp_ai_agent_wc_rank_products()`, `wp_ai_agent_route_intent()`, `startVoice()` — long, multi-branch.

### Possible bugs
- **Intent registry out of sync:** `wp_ai_agent_intents()` omits `business_info`, `products_overview`, `social` which `detect_intent()` returns.
- **Stale docblock (important):** `user-auth.php` documents an in-chat `wp_authenticate`/`wp_insert_user`/`wp_set_auth_cookie` flow with `log_user => '[hidden]'` — **none of it exists**; auth just links to WP pages. The comments misrepresent the security model.
- Website-type: `agency` persona/modules exist but detection never returns it; NGO `type_directory` CPTs (`program`) mismatch collectors (`give_forms`); medical rule precedence is fragile.
- Volunteered-contact promotion can misfire (any email-like token in a website_info message → forced lead).
- Analytics `messages = conversations × 2` is an approximation; "failed" detection is brittle string-equality; `current_time('timestamp')` is deprecated-style.

### Hardcoded values
Model IDs, temperature 0.2, timeouts (35/30/8/45 s), the ~150-word product stop-list (not filterable), booking slots (10/11/2/4), many limits (8/40/100/200/300/100000), thresholds (0.2/0.25/0.6), score weights, English/Hinglish keyword regexes, `wa.me` base, menu position 58.

### Comments
Docblocks are generally thorough and helpful; the main defects are the **stale `user-auth.php` docblock** and leftover debug comments in the bootstrap.

---

## 18. Suggested Improvements

1. **Lock down REST:** verify the `wp_rest` nonce on `/chat`, `/history`, `/clear-history`, `/image-search`; scope history/clear to the caller's own session (or logged-in user); add simple per-IP rate limiting.
2. **Fix the `user-auth.php` docblock** to match reality (link-out flow), or implement the documented in-chat auth with real masking.
3. **Split `agent-tools.php`** into `tools/{product,order,lead,booking,contact,social,directory,info}.php`; extract the shared discovery blob-scanner into one helper.
4. **Search performance:** add a FULLTEXT index (or a proper vector store) for candidate retrieval; batch-insert the content index inside a transaction; cache `product_field_text` per product.
5. **Sync the intent registry** with the detector; add a unit test that asserts every returned intent is registered and routable.
6. **Stream exports** (leads/bookings/analytics) row-by-row instead of loading 100 k rows.
7. **Retention & privacy:** add a configurable log-retention pruner and GDPR export/erase hooks; consider hashing/omitting IP.
8. **Localization:** move hardcoded JS/PHP user-facing strings through `__()`/`wp_localize_script`; make the product stop-list filterable.
9. **Remove dead code** (duplicate `/chat`, classic conversations screen once confirmed, dormant chat.js/CSS blocks, `is_gemini_api_key`).
10. **Guard admin renderers** with `current_user_can` consistently.

---

## 19. Future Architecture Recommendations

- **Adopt a light service/registry pattern for tools:** register each tool with `{intents, capabilities, handler}` so routing is data-driven instead of a 270-line switch, and third parties can add tools via a filter.
- **Introduce a dedicated vector store** (e.g. SQLite-vss, a pgvector service, or a managed embedding DB) when catalogs/content exceed a few thousand rows — the in-PHP cosine loop won't scale.
- **Move heavy indexing to Action Scheduler** (batched, resumable) rather than a single +30 s cron event with `TRUNCATE`.
- **Namespace the code** (PSR-4 classes) to replace the flat function prefix and the 5,600-line file; keep procedural shims for backward compatibility.
- **Provider abstraction:** formalise an interface for chat/embedding/vision providers so adding Claude/others is a class, not scattered branches.
- **Client build step:** split `chat.js` into modules (session, render, voice, image) and bundle; add a JS i18n table.
- **First-class analytics events** (typed rows) instead of inferring "messages/failed" from string patterns.

---

## 20. Overall Plugin Rating

| Dimension | Score (/10) | Comment |
|---|---|---|
| Feature completeness | 9 | Exceptionally broad: RAG, commerce, vision, voice, leads/bookings/tickets, analytics, theming, site-type intelligence. |
| Architecture | 6 | Clean layering *conceptually*, but one 5.6k-line file and a giant switch/regex chain hurt maintainability. |
| Code quality | 6.5 | Good docblocks & escaping; let down by dead code, a misleading security docblock, and duplication. |
| Security | 5 | Solid SQL/escaping, but public unauthenticated REST with IDOR + cost-amplification is the main risk. |
| Performance | 6 | Fine for small/medium sites; product-term N+1 and in-PHP vector search won't scale to large catalogs. |
| Extensibility | 8.5 | Very rich set of `apply_filters` hooks throughout — most behaviour is overridable. |
| UX (frontend) | 9 | Polished widget, guided cards, premium voice UX, responsive. |
| **Overall** | **7 / 10** | A genuinely capable, near-commercial-grade AI agent. Address REST security, split the mega-file, and fix the stale auth docblock, and it comfortably reaches 8.5+. |

---

*Generated from a full read-only analysis of all 27 PHP files, `chat.js`, and `chat.css` (v1.2.0). No plugin code was modified.*
