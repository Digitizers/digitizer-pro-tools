# Which module to publish on WordPress.org next

**Date:** 2026-08-30
**Goal set by Ben:** visibility and leads for Digitizer — not reducing DPT maintenance.
**Method:** `api.wordpress.org/plugins/info/1.2/` `query_plugins`, active install counts
and total result counts per search term. Counts are WordPress.org's own buckets.

This file exists because the same question was researched in an earlier session and the
answer did not survive. Record conclusions here, not only in chat.

## The finding

The general activity-log category is saturated; the AI-agent category is nearly empty.

| Search term | Total results | Top plugins (active installs) |
|---|---|---|
| activity log | 1,840 | wp-security-audit-log 300k · aryo-activity-log 200k · simple-history 300k · stream 70k |
| mcp server | **317** | vibe-ai 10k · royal-mcp 10k · easy-mcp-ai 8k |
| mcp audit log | **108** | every hit is a *connector*, none is a log |
| hide login | 1,866 | wps-hide-login 2M |
| duplicate post | 4,007 | duplicate-post 3M · duplicate-page 3M |
| name your price | 3,419 | wpc-name-your-price 6k |
| user role editor | 2,456 | user-role-editor 700k · members 300k |
| cookie banner hebrew | 31 | il-privacy-cookie-consent **200** · everything else is generic/global |

Every MCP-category plugin on WordPress.org is a *connector* — it hands an AI agent the
keys. Not one of them ships the record of what the agent then did. Searching the whole
category for an audit log returns connectors and the generic activity logs.

## Recommendation: Agent Log

Positioned as a general activity log it is dead on arrival — four incumbents above 70k,
plus Wordfence at 5M. Positioned as **"see what your AI agent changed"** it has no
competitor at all, in the one category on WordPress.org that is growing rather than
consolidated.

It also matches what Digitizer already sells (SiteAgent, Aura, elementor-mcp), so the
people who find it are the lead profile, not passers-by.

**Honest cost:** the category's absolute traffic today is small — thousands, not
millions. This is a bet on where the category goes, not on current volume.

**Honest risk:** unlike Update Hold, which touched nothing, Agent Log creates and writes
a database table. That raises the review bar — sanitization, `$wpdb->prepare`, REST
permissions, clean uninstall. All of these already survived nine Codex rounds, but the
submission is not a copy-paste of the Update Hold one.

## Runner-up, on different logic: Cookie Banner (Hebrew)

Not global volume — local dominance. The Israel-specific incumbent has 200 installs.
Hebrew-language leads are Digitizer's actual customers, so lead *quality* is higher even
though reach is far lower. Worth doing after Agent Log, not instead of it.

## Ruled out

Hide Login, Duplicate Post, User Role Editor, Name Your Price, Update Emails — all face
incumbents one to three orders of magnitude larger, with no differentiator to argue.
